<?php

namespace App\Actions\Orders;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\CommercialStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\KitchenOrderUpdated;
use App\Events\OrderUpdated;
use App\Http\Requests\EditCommittedOrderRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\OrderMoney;
use App\Support\OrderSnapshots;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EditCommittedOrder
{
    public function __construct(
        private PosAccess $access,
        private OrderSnapshots $snapshots,
        private ApplyInventoryMovement $inventory,
        private OrderMoney $money,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Branch $branch, Order $requestedOrder, array $input): Order
    {
        $data = Validator::make($input, (new EditCommittedOrderRequest)->rules())->validate();
        $key = strtolower($data['idempotency_key']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $branch, $requestedOrder, $data, $key, $hash): Order {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
            }
            $actor = $this->access->authorize($actor, $branch);
            $replay = AuditLog::query()->where('idempotency_key', $key)->first();
            if ($replay !== null) {
                if ($replay->auditable_id !== $requestedOrder->id || $replay->user_id !== $actor->id || data_get($replay->metadata, 'request_hash') !== $hash) {
                    abort(409, 'This edit attempt has already been used with different details.');
                }

                return Order::query()->where('branch_id', $branch->id)->findOrFail($requestedOrder->id);
            }

            $session = StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Historical transactions are read-only.']);
            }
            $order = Order::query()->where('branch_id', $branch->id)->whereKey($requestedOrder->id)->lockForUpdate()->firstOrFail();
            if ($order->store_session_id !== $session->id || $order->committed_at === null || $order->commercial_status !== CommercialStatus::Active) {
                throw ValidationException::withMessages(['order' => 'Only an active order from the current store session can be edited.']);
            }
            if ($order->version !== $data['expected_version']) {
                abort(409, 'This order changed after you opened it. Refresh its details and try again.');
            }

            $order->load('items.modifiers', 'payments', 'adjustments');
            $before = $this->auditSnapshot($order);
            $beforeQuantities = $order->items->groupBy('product_id')->map->sum('quantity');
            $snapshotData = [
                'order_type' => (string) $data['order_type'],
                'branch_table_id' => isset($data['branch_table_id']) ? (string) $data['branch_table_id'] : null,
                'customer_label' => isset($data['customer_label']) ? (string) $data['customer_label'] : null,
                'items' => array_values(array_map(fn (array $line): array => [
                    'existing_order_item_id' => isset($line['existing_order_item_id']) ? (string) $line['existing_order_item_id'] : null,
                    'product_id' => (string) $line['product_id'], 'quantity' => (int) $line['quantity'],
                    'notes' => isset($line['notes']) ? (string) $line['notes'] : null,
                    'modifiers' => array_values(array_map(fn (array $modifier): array => [
                        'group_id' => (string) $modifier['group_id'], 'option_id' => (string) $modifier['option_id'],
                    ], $line['modifiers'])),
                ], $data['items'])),
            ];
            $snapshot = $this->snapshots->prepare($branch, $snapshotData, $order->id, true, $order->items);
            $afterQuantities = collect($snapshot['items'])->groupBy('product_id')->map->sum('quantity');
            $productIds = $beforeQuantities->keys()->merge($afterQuantities->keys())->filter()->unique()->sort()->values();

            $products = Product::query()->whereKey($productIds)->orderBy('id')->get()->keyBy('id');
            $configurations = BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $productIds)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $balances = BranchInventory::query()->where('branch_id', $branch->id)->whereIn('product_id', $productIds)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $deltas = [];
            foreach ($productIds as $productId) {
                $delta = (int) ($beforeQuantities[$productId] ?? 0) - (int) ($afterQuantities[$productId] ?? 0);
                if ($delta === 0) {
                    continue;
                }
                $tracked = (bool) ($configurations[$productId]?->tracks_inventory);
                if (! $tracked) {
                    continue;
                }
                $balance = $balances->get($productId);
                $onHand = $balance instanceof BranchInventory ? $balance->on_hand : 0;
                if ($delta < 0 && abs($delta) > $onHand) {
                    throw ValidationException::withMessages(['items' => 'Insufficient stock for one or more edited items. Refresh the catalog and try again.']);
                }
                $deltas[$productId] = $delta;
            }

            OrderItemModifier::query()->whereIn('order_item_id', $order->items->pluck('id'))->delete();
            OrderItem::query()->where('order_id', $order->id)->delete();
            $this->snapshots->persist($snapshot);

            $moneyBefore = $this->money->totals($order);
            $newTotal = ExactMoney::cents($snapshot['attributes']['total']);
            $adjustment = max(0, $moneyBefore['settled'] - $newTotal);
            $status = $adjustment > 0 ? PaymentStatus::Paid : match (true) {
                $moneyBefore['settled'] >= $newTotal => PaymentStatus::Paid,
                $moneyBefore['settled'] > 0 => PaymentStatus::Partial,
                default => PaymentStatus::Unpaid,
            };
            $order->update([
                ...$snapshot['attributes'],
                'original_total' => $order->original_total ?? $order->total,
                'payment_status' => $status,
                'edited_at' => now(),
                'version' => $order->version + 1,
            ]);
            if ($adjustment > 0) {
                OrderAdjustment::query()->create([
                    'branch_id' => $branch->id, 'store_session_id' => $session->id, 'order_id' => $order->id,
                    'type' => 'lower_total_correction', 'amount' => ExactMoney::decimal($adjustment),
                    'reason' => $data['reason'] ?? null, 'created_by_user_id' => $actor->id, 'idempotency_key' => $key,
                ]);
            }
            foreach ($deltas as $productId => $delta) {
                $this->inventory->execute($branch, $products[$productId], InventoryMovementType::OrderEditDelta, $delta, 'Committed order edit '.$order->order_number, $actor, $order->id);
            }

            $order->load('items.modifiers', 'payments', 'adjustments');
            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'transactions',
                action: 'committed_order_edited',
                auditableType: Order::class,
                auditableId: $order->id,
                before: $before,
                after: $this->auditSnapshot($order),
                metadata: ['request_hash' => $hash, 'reason' => $data['reason'] ?? null, 'inventory_deltas' => $deltas],
                idempotencyKey: $key,
            );
            OrderUpdated::dispatch($order, ['items', 'total', 'payment_status']);
            KitchenOrderUpdated::dispatch($order);
            CustomerTrackingChanged::dispatch($order);

            return $order;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(Order $order): array
    {
        return [
            'order_type' => $order->order_type->value, 'customer_label' => $order->customer_label,
            'branch_table_id' => $order->branch_table_id, 'subtotal' => $order->subtotal,
            'total' => $order->total, 'payment_status' => $order->payment_status->value,
            'version' => $order->version,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'product_id' => $item->product_id, 'name' => $item->product_name_snapshot,
                'unit_price' => $item->unit_price, 'quantity' => $item->quantity,
                'line_total' => $item->line_total, 'notes' => $item->notes,
                'modifier_option_ids' => $item->modifiers->pluck('modifier_option_id')->values()->all(),
            ])->values()->all(),
        ];
    }
}
