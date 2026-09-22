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
use App\Http\Requests\PayNowOrderRequest;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Support\LoadedQrOrder;
use App\Support\OrderPaymentLegs;
use App\Support\PosAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayNowOrder
{
    public function __construct(
        private CreatePosDraftOrder $drafts,
        private ApplyOrderInventory $inventory,
        private OrderPaymentLegs $paymentLegs,
        private PosAccess $access,
        private LoadedQrOrder $loadedQr,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $user, Branch $branch, array $input): Order
    {
        $data = Validator::make($input, PayNowOrderRequest::paymentRules(! empty($input['draft_order_id'])))->validate();
        if (! empty($data['draft_order_id']) && ! empty($data['reserved_order_id'])) {
            throw ValidationException::withMessages(['reserved_order_id' => 'Choose either a saved draft or a reserved order, not both.']);
        }
        $data['idempotency_key'] = strtolower($data['idempotency_key']);

        try {
            return DB::transaction(function () use ($user, $branch, $data): Order {
                /** A root may claim different method legs; serialize it across branches too. */
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['idempotency_key']]);
                }
                /** Same branch-first lock order as draft creation and Store Open. */
                $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
                $user = $this->access->authorize($user, $branch);
                if ($replay = $this->replay($user, $branch, $data)) {
                    return $replay;
                }
                $session = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->lockForUpdate()->first();
                if ($session === null) {
                    throw ValidationException::withMessages(['store' => 'Store is closed. Open the store before confirming payment.']);
                }
                if (! empty($data['draft_order_id'])) {
                    $order = Order::query()->where('branch_id', $branch->id)->whereKey($data['draft_order_id'])->lockForUpdate()->firstOrFail();
                } elseif (! empty($data['reserved_order_id'])) {
                    $reservedOrder = Order::query()->where('branch_id', $branch->id)->whereKey($data['reserved_order_id'])->lockForUpdate()->firstOrFail();
                    $order = $this->drafts->execute($user, $branch, $data, $reservedOrder);
                } else {
                    $order = $this->drafts->execute($user, $branch, $data);
                }
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->payment_status === PaymentStatus::Paid) {
                    throw ValidationException::withMessages(['order' => 'Order has already been paid.']);
                }
                if ((! $this->loadedQr->eligible($order, $user)
                    && ($order->source !== OrderSource::Pos || $order->commercial_status !== CommercialStatus::Draft))
                    || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== null
                    || $order->kitchen_status !== KitchenStatus::NotSent || $order->committed_at !== null) {
                    throw ValidationException::withMessages(['order' => 'Only an unpaid POS draft or your loaded QR order can be paid here.']);
                }
                if ($order->source === OrderSource::CustomerQr) {
                    abort_unless($order->store_session_id === $session->id, 409, 'This QR order belongs to an earlier store session.');
                    $this->loadedQr->validateTable($order, $branch);
                }
                $paidAt = now();
                foreach ($this->paymentLegs->for($order, $data) as $method => $leg) {
                    Payment::query()->create([
                        ...$leg, 'method' => $method, 'branch_id' => $branch->id, 'store_session_id' => $session->id,
                        'order_id' => $order->id, 'created_by_user_id' => $user->id,
                        'idempotency_key' => $data['idempotency_key'].':'.$method, 'paid_at' => $paidAt,
                    ]);
                }

                $this->inventory->execute(
                    $order,
                    $branch,
                    $user,
                    InventoryMovementType::Sale,
                    'Pay Now order '.$order->order_number,
                );
                $ticket = KitchenTicket::query()->create(['branch_id' => $branch->id, 'order_id' => $order->id, 'status' => KitchenStatus::Kitchen]);
                $order->update([
                    'store_session_id' => $session->id, 'commercial_status' => CommercialStatus::Active,
                    'payment_status' => PaymentStatus::Paid, 'payment_term' => PaymentTerm::Immediate,
                    'kitchen_status' => KitchenStatus::Kitchen, 'committed_at' => $paidAt, 'version' => $order->version + 1,
                ]);
                OrderCommitted::dispatch($order);
                CustomerTrackingChanged::dispatch($order);
                KitchenTicketCreated::dispatch($order, $ticket);
                DisplayOrdersChanged::dispatch($branch, $paidAt);

                return $order;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $detail = $exception->errorInfo[2] ?? '';
            $expected = (($exception->errorInfo[0] ?? '') === '23505' && str_contains($detail, '"payments_idempotency_key_unique"'))
                || (DB::getDriverName() === 'sqlite' && str_contains($detail, 'UNIQUE constraint failed: payments.idempotency_key'));
            if (! $expected) {
                throw $exception;
            }
            /** Reload only after the entire losing transaction has rolled back. */
            $branch = $branch->fresh() ?? abort(403);
            $user = $this->access->authorize($user, $branch);

            return $this->replay($user, $branch, $data) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function replay(User $user, Branch $branch, array $data): ?Order
    {
        $payments = Payment::query()->whereIn('idempotency_key', [$data['idempotency_key'].':cash', $data['idempotency_key'].':cashless'])->get();
        if ($payments->isEmpty()) {
            return null;
        }
        $first = $payments->first();
        if ($first->branch_id !== $branch->id || $first->created_by_user_id !== $user->id
            || (! empty($data['draft_order_id']) && $first->order_id !== $data['draft_order_id'])
            || (! empty($data['reserved_order_id']) && $first->order_id !== $data['reserved_order_id'])
            || $payments->contains(fn (Payment $payment): bool => $payment->order_id !== $first->order_id)) {
            abort(409, 'This payment attempt belongs to another order or cashier.');
        }
        $order = Order::query()->where('branch_id', $branch->id)->findOrFail($first->order_id);
        if (empty($data['draft_order_id']) && ! $this->matchesCart($order, $data)) {
            abort(409, 'This payment attempt belongs to another order.');
        }
        $legs = $this->paymentLegs->for($order, $data);
        if ($order->payment_status !== PaymentStatus::Paid || count($legs) !== $payments->count()) {
            abort(409, 'This payment attempt has already been used with different details.');
        }
        foreach ($payments as $payment) {
            if (($legs[$payment->method->value] ?? null) !== [
                'amount' => $payment->amount, 'amount_received' => $payment->amount_received, 'change_amount' => $payment->change_amount,
            ]) {
                abort(409, 'This payment attempt has already been used with different details.');
            }
        }

        return $order;
    }

    /** Compare identity and selections, never today's catalog prices.
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
