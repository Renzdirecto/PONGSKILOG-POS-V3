<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSelectionType;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\StoreSessionStatus;
use App\Http\Requests\StorePosDraftOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\User;
use App\Support\BranchCatalog;
use App\Support\ExactMoney;
use App\Support\OrderNumber;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePosDraftOrder
{
    public function __construct(private BranchCatalog $catalog, private PosAccess $access, private OrderNumber $numbers) {}

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
            $type = OrderType::from($data['order_type']);
            $tableId = ($data['branch_table_id'] ?? null) ?: null;
            if ($tableId !== null && ! $branch->tables()->whereKey($tableId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['branch_table_id' => 'Choose an active table in this branch.']);
            }
            $label = trim($data['customer_label'] ?? '');

            $products = $this->catalog->productsForOrder($branch, array_values(array_unique(array_column($data['items'], 'product_id'))))->keyBy('id');
            $requested = [];
            foreach ($data['items'] as $line) {
                $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + $line['quantity'];
            }
            $items = [];
            $modifiers = [];
            $subtotal = 0;
            $orderId = $reservedOrder === null ? (string) Str::uuid() : $reservedOrder->id;
            foreach ($data['items'] as $index => $line) {
                $product = $products->get($line['product_id']);
                if ($product === null) {
                    throw ValidationException::withMessages(["items.$index.product_id" => 'This product is no longer available. Remove it or refresh the catalog.']);
                }
                $state = $this->catalog->resolveLoaded($product);
                if ($state['tracked'] && $requested[$product->id] > $state['on_hand']) {
                    throw ValidationException::withMessages(["items.$index.quantity" => "Insufficient stock for {$product->name}. Reduce the total quantity in the cart."]);
                }
                if (! $state['is_available']) {
                    throw ValidationException::withMessages(["items.$index.product_id" => 'This product is no longer available. Remove it or refresh the catalog.']);
                }
                $itemId = (string) Str::uuid();
                $base = ExactMoney::cents($state['effective_price']);
                $unit = $base;
                $groups = $product->modifierGroups->keyBy('id');
                $selected = [];
                $counts = [];
                foreach ($line['modifiers'] as $selection) {
                    $group = $groups->get($selection['group_id']);
                    $option = $group?->options->firstWhere('id', $selection['option_id']);
                    if ($group === null || $option === null || isset($selected[$selection['option_id']])) {
                        throw ValidationException::withMessages(["items.$index.modifiers" => 'A modifier is unavailable, duplicated, or does not belong to this product.']);
                    }
                    $selected[$option->id] = true;
                    $counts[$group->id] = ($counts[$group->id] ?? 0) + 1;
                    $unit = ExactMoney::add($unit, ExactMoney::cents($option->price_delta));
                    $modifiers[] = [
                        'id' => (string) Str::uuid(), 'order_item_id' => $itemId,
                        'modifier_option_id' => $option->id, 'group_name_snapshot' => $group->name,
                        'option_name_snapshot' => $option->name, 'price_delta_snapshot' => $option->price_delta, 'quantity' => 1,
                    ];
                }
                foreach ($groups as $group) {
                    $count = $counts[$group->id] ?? 0;
                    if ($count < $group->min_select || $count > $group->max_select
                        || ($group->selection_type === ModifierSelectionType::Single && $count > 1)) {
                        throw ValidationException::withMessages(["items.$index.modifiers" => "Choose the required number of options for {$group->name}."]);
                    }
                }
                $lineTotal = ExactMoney::multiply($unit, $line['quantity']);
                $subtotal = ExactMoney::add($subtotal, $lineTotal);
                $items[] = [
                    'id' => $itemId, 'order_id' => $orderId, 'product_id' => $product->id,
                    'product_name_snapshot' => $product->name, 'unit_price' => ExactMoney::decimal($base),
                    'quantity' => $line['quantity'], 'line_total' => ExactMoney::decimal($lineTotal),
                    'notes' => $line['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ];
            }

            $attributes = [
                'id' => $orderId, 'branch_id' => $branch->id, 'source' => OrderSource::Pos,
                'order_type' => $type, 'branch_table_id' => $tableId,
                'customer_label' => $label === '' ? null : $label, 'commercial_status' => CommercialStatus::Draft,
                'payment_status' => PaymentStatus::Unpaid, 'kitchen_status' => KitchenStatus::NotSent,
                'subtotal' => ExactMoney::decimal($subtotal), 'total' => ExactMoney::decimal($subtotal),
                'created_by_user_id' => $user->id,
            ];
            if ($reservedOrder === null) {
                $createdAt = now();
                $order = $this->createOrder([...$attributes, ...$this->numbers->allocate($branch, $createdAt),
                    'created_at' => $createdAt, 'updated_at' => $createdAt]);
            } else {
                $reservedOrder->fill([
                    'order_type' => $type, 'branch_table_id' => $tableId,
                    'customer_label' => $label === '' ? null : $label,
                    'subtotal' => ExactMoney::decimal($subtotal), 'total' => ExactMoney::decimal($subtotal),
                ])->save();
                $order = $reservedOrder;
            }
            OrderItem::query()->insert($items);
            foreach (array_chunk($modifiers, 500) as $chunk) {
                OrderItemModifier::query()->insert($chunk);
            }

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
