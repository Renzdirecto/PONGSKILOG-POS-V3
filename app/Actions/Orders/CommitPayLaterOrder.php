<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\InventoryMovementType;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Enums\StoreSessionStatus;
use App\Events\KitchenTicketCreated;
use App\Events\OrderCommitted;
use App\Http\Requests\CommitPayLaterOrderRequest;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\User;
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

                return $this->replay($claimed);
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

                return $this->replay($order);
            }
            if ($hasLocalCart) {
                $order = $this->drafts->execute($user, $branch, $data, $order);
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            }
            if ($order->source !== OrderSource::Pos || $order->commercial_status !== CommercialStatus::Draft
                || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== null
                || $order->kitchen_status !== KitchenStatus::NotSent || $order->committed_at !== null) {
                throw ValidationException::withMessages(['order' => 'Only an unpaid, uncommitted POS order can be saved as Pay Later.']);
            }
            if ($order->branch_table_id !== null && ! $branch->tables()->whereKey($order->branch_table_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['table' => 'The selected table is no longer active in this branch.']);
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
            KitchenTicketCreated::dispatch($order, $ticket);

            return $order;
        });
    }

    private function replay(Order $order): Order
    {
        if ($order->source !== OrderSource::Pos || $order->commercial_status !== CommercialStatus::Active
            || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== PaymentTerm::PayLater
            || $order->kitchen_status !== KitchenStatus::Kitchen || $order->committed_at === null
            || $order->store_session_id === null || $order->kitchenTicket()->doesntExist()) {
            abort(409, 'This Pay Later attempt is not in a recoverable committed state.');
        }

        return $order;
    }
}
