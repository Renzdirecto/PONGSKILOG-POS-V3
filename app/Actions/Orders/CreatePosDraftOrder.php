<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Enums\StoreSessionStatus;
use App\Http\Requests\StorePosDraftOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderNumber;
use App\Support\OrderSnapshots;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePosDraftOrder
{
    public function __construct(private OrderSnapshots $snapshots, private PosAccess $access, private OrderNumber $numbers) {}

    /** @param array<string, mixed> $input */
    public function execute(User $user, Branch $branch, array $input, ?Order $reservedOrder = null): Order
    {
        return DB::transaction(function () use ($user, $branch, $input, $reservedOrder): Order {
            $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $user = $this->access->authorize($user, $branch);
            if (! $branch->storeSessions()->where('status', StoreSessionStatus::Open)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Open the store before creating an order.']);
            }

            /** @var array{order_type: string, branch_table_id?: string|null, customer_label?: string|null, items: list<array{product_id: string, quantity: int, notes?: string|null, modifiers: list<array{group_id: string, option_id: string}>}>} $data */
            $data = Validator::make($input, StorePosDraftOrderRequest::draftRules())->validate();
            if ($reservedOrder !== null) {
                $reservedOrder = Order::query()->where('branch_id', $branch->id)->whereKey($reservedOrder->id)->lockForUpdate()->firstOrFail();
                abort_unless($reservedOrder->created_by_user_id === $user->id
                    && $reservedOrder->source === OrderSource::Pos
                    && $reservedOrder->commercial_status === CommercialStatus::Draft
                    && $reservedOrder->payment_status === PaymentStatus::Unpaid
                    && $reservedOrder->payment_term === null
                    && $reservedOrder->kitchen_status === KitchenStatus::NotSent
                    && $reservedOrder->committed_at === null
                    && $reservedOrder->items()->doesntExist(), 404);
            }
            $orderId = $reservedOrder === null ? (string) Str::uuid() : $reservedOrder->id;
            $snapshot = $this->snapshots->prepare($branch, $data, $orderId);

            $attributes = [
                'id' => $orderId, 'branch_id' => $branch->id, 'source' => OrderSource::Pos,
                ...$snapshot['attributes'], 'commercial_status' => CommercialStatus::Draft,
                'payment_status' => PaymentStatus::Unpaid, 'kitchen_status' => KitchenStatus::NotSent,
                'created_by_user_id' => $user->id,
            ];
            if ($reservedOrder === null) {
                $createdAt = now();
                $order = $this->createOrder([...$attributes, ...$this->numbers->allocate($branch, $createdAt),
                    'created_at' => $createdAt, 'updated_at' => $createdAt]);
            } else {
                $reservedOrder->fill($snapshot['attributes'])->save();
                $order = $reservedOrder;
            }
            $this->snapshots->persist($snapshot);

            return $order->refresh()->load('items.modifiers', 'branchTable');
        });
    }

    /** @param array<string, mixed> $attributes */
    private function createOrder(array $attributes): Order
    {
        $order = new Order;
        $order->forceFill($attributes);
        $order->save();

        return $order;
    }
}
