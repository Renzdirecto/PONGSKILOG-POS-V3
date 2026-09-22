<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\InventoryMovementType;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\DisplayOrdersChanged;
use App\Events\KitchenTicketCreated;
use App\Events\OrderCommitted;
use App\Http\Requests\CommitPayLaterOrderRequest;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\LoadedQrOrder;
use App\Support\OrderNumber;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommitPayLaterOrder
{
    public function __construct(
        private CreatePosDraftOrder $drafts,
        private ApplyOrderInventory $inventory,
        private PosAccess $access,
        private LoadedQrOrder $loadedQr,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $user, Branch $branch, Order $requestedOrder, array $input): Order
    {
        $hasLocalCart = CommitPayLaterOrderRequest::hasCartData($input);
        $data = Validator::make($input, CommitPayLaterOrderRequest::commitRules($hasLocalCart))->validate();
        $key = strtolower($data['idempotency_key']);

        return DB::transaction(function () use ($user, $branch, $requestedOrder, $data, $hasLocalCart, $key): Order {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pay-later:'.$key]);
            }

            $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $user = $this->access->authorize($user, $branch);
            $claimed = Order::query()->where('pay_later_idempotency_key', $key)->first();
            if ($claimed !== null) {
                if ($claimed->id !== $requestedOrder->id || $claimed->branch_id !== $branch->id) {
                    abort(409, 'This Pay Later attempt belongs to another order.');
                }

                abort_if($claimed->source === OrderSource::CustomerQr && $claimed->loaded_by_user_id !== $user->id, 403);

                return $this->replay($claimed, $data, $hasLocalCart);
            }

            $session = $branch->storeSessions()
                ->where('status', StoreSessionStatus::Open)
                ->lockForUpdate()
                ->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Open the store before saving this Pay Later order.']);
            }

            $order = Order::query()
                ->where('branch_id', $branch->id)
                ->whereKey($requestedOrder->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($order->pay_later_idempotency_key !== null) {
                if ($order->pay_later_idempotency_key !== $key) {
                    abort(409, 'This order has already been committed with another Pay Later attempt.');
                }

                return $this->replay($order, $data, $hasLocalCart);
            }
            if ($hasLocalCart && $order->source === OrderSource::CustomerQr) {
                abort(409, 'Submitted QR snapshots cannot be replaced.');
            }
            if ($hasLocalCart) {
                $order = $this->drafts->execute($user, $branch, $data, $order);
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            }
            if ((! $this->loadedQr->eligible($order, $user)
                    && ($order->source !== OrderSource::Pos || $order->commercial_status !== CommercialStatus::Draft))
                || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== null
                || $order->kitchen_status !== KitchenStatus::NotSent || $order->committed_at !== null) {
                throw ValidationException::withMessages(['order' => 'Only an unpaid POS draft or your loaded QR order can be saved as Pay Later.']);
            }
            if ($order->branch_table_id !== null && ! $branch->tables()->whereKey($order->branch_table_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['table' => 'The selected table is no longer active in this branch.']);
            }

            if ($order->source === OrderSource::CustomerQr) {
                abort_unless($order->store_session_id === $session->id, 409, 'This QR order belongs to an earlier store session.');
                $this->loadedQr->applyMetadata($order, $branch, $data);
            }
            if ($order->source === OrderSource::CustomerQr && $order->order_number === null) {
                $order->forceFill(app(OrderNumber::class)->allocate($branch, now()));
            }
            $committedAt = now();
            $this->inventory->execute(
                $order,
                $branch,
                $user,
                InventoryMovementType::PayLaterCommit,
                'Pay Later order '.$order->order_number,
            );
            $ticket = KitchenTicket::query()->create([
                'branch_id' => $branch->id,
                'order_id' => $order->id,
                'status' => KitchenStatus::Kitchen,
            ]);
            $order->update([
                'store_session_id' => $session->id,
                'commercial_status' => CommercialStatus::Active,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_term' => PaymentTerm::PayLater,
                'kitchen_status' => KitchenStatus::Kitchen,
                'committed_at' => $committedAt,
                'pay_later_idempotency_key' => $key,
                'version' => $order->version + 1,
            ]);
            OrderCommitted::dispatch($order);
            CustomerTrackingChanged::dispatch($order);
            KitchenTicketCreated::dispatch($order, $ticket);
            DisplayOrdersChanged::dispatch($branch, $committedAt);

            return $order;
        });
    }

    /** @param array<string, mixed> $data */
    private function replay(Order $order, array $data, bool $hasLocalCart): Order
    {
        if ($order->commercial_status !== CommercialStatus::Active
            || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== PaymentTerm::PayLater
            || $order->kitchen_status !== KitchenStatus::Kitchen || $order->committed_at === null
            || $order->store_session_id === null || $order->kitchenTicket()->doesntExist()) {
            abort(409, 'This Pay Later attempt is not in a recoverable committed state.');
        }
        $this->loadedQr->validateReplay($order, $data);
        if ($hasLocalCart && ! $this->matchesCart($order, $data)) {
            abort(409, 'This Pay Later attempt has already been used with different order details.');
        }

        return $order;
    }

    /**
     * Compare identity and selections against committed snapshots, never current catalog prices.
     *
     * @param  array<string, mixed>  $data
     */
    private function matchesCart(Order $order, array $data): bool
    {
        if ($order->order_type->value !== $data['order_type'] || ($order->customer_label ?? '') !== trim($data['customer_label'] ?? '')
            || $order->branch_table_id !== (($data['branch_table_id'] ?? null) ?: null)) {
            return false;
        }

        $order->load('items.modifiers');
        $actual = $order->items->map(fn (OrderItem $item): array => [
            $item->product_id, $item->quantity, $item->notes ?? '', $item->modifiers->pluck('modifier_option_id')->sort()->values()->all(),
        ])->all();
        $expected = array_map(function (array $line): array {
            $options = array_column($line['modifiers'], 'option_id');
            sort($options);

            return [$line['product_id'], $line['quantity'], $line['notes'] ?? '', $options];
        }, $data['items']);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
