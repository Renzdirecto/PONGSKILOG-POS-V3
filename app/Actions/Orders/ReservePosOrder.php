<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderNumber;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservePosOrder
{
    public function __construct(private PosAccess $access, private OrderNumber $numbers) {}

    public function execute(User $user, Branch $branch, OrderType $orderType): Order
    {
        return DB::transaction(function () use ($user, $branch, $orderType): Order {
            $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $user = $this->access->authorize($user, $branch);
            if (! $branch->storeSessions()->where('status', StoreSessionStatus::Open)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Open the store before starting an order.']);
            }

            $reservation = Order::query()
                ->where('branch_id', $branch->id)
                ->where('created_by_user_id', $user->id)
                ->where('source', OrderSource::Pos)
                ->where('commercial_status', CommercialStatus::Draft)
                ->where('payment_status', PaymentStatus::Unpaid)
                ->whereNull('payment_term')
                ->where('kitchen_status', KitchenStatus::NotSent)
                ->whereNull('committed_at')
                ->where('subtotal', '0.00')
                ->where('total', '0.00')
                ->whereDoesntHave('items')
                ->latest('created_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($reservation !== null) {
                if ($reservation->order_type !== $orderType) {
                    $reservation->order_type = $orderType;
                    $reservation->save();
                }

                return $reservation;
            }

            $createdAt = now();
            $identifiers = $this->numbers->allocate($branch, $createdAt);
            $order = new Order;
            $order->forceFill([
                ...$identifiers,
                'branch_id' => $branch->id,
                'source' => OrderSource::Pos,
                'order_type' => $orderType,
                'commercial_status' => CommercialStatus::Draft,
                'payment_status' => PaymentStatus::Unpaid,
                'kitchen_status' => KitchenStatus::NotSent,
                'subtotal' => '0.00',
                'total' => '0.00',
                'created_by_user_id' => $user->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            return $order;
        });
    }
}
