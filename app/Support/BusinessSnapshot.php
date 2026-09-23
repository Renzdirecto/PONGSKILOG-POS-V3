<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Live, read-only operating state for the Owner Dashboard: the Kitchen right now, Inventory attention and the most
 * recent transactions, for one authorized Branch or All Branches.
 *
 * Kitchen figures are ticket counts of the currently OPEN Store Sessions and expose no money. Inventory attention uses
 * the canonical InventoryState per Branch: stock quantities are never summed across Branches, so All Branches reports
 * low and out-of-stock counts per Branch instead of a fabricated total.
 */
class BusinessSnapshot
{
    private const ATTENTION_LIMIT = 5;

    private const RECENT_LIMIT = 5;

    public function __construct(private InventoryState $inventory) {}

    /**
     * @return array{open_sessions: int, kitchen: int, preparing: int, ready: int, oldest: array{order_number: string, branch_code: string, status: string, waiting_seconds: int, since: string}|null, as_of: string}
     */
    public function kitchen(?Branch $branch): array
    {
        $now = CarbonImmutable::now();
        $open = fn (): QueryBuilder => DB::table('orders')
            ->join('store_sessions', function (JoinClause $join): void {
                $join->on('store_sessions.id', '=', 'orders.store_session_id')
                    ->on('store_sessions.branch_id', '=', 'orders.branch_id')
                    ->where('store_sessions.status', StoreSessionStatus::Open->value);
            })
            ->when($branch !== null, fn (QueryBuilder $query) => $query->where('orders.branch_id', $branch?->id))
            ->whereNotNull('orders.committed_at')
            ->whereIn('orders.commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value])
            ->whereExists(fn (QueryBuilder $tickets) => $tickets->select(DB::raw(1))->from('kitchen_tickets')->whereColumn('kitchen_tickets.order_id', 'orders.id'));
        $counts = $open()
            ->whereIn('orders.kitchen_status', [KitchenStatus::Kitchen->value, KitchenStatus::Preparing->value, KitchenStatus::Ready->value])
            ->groupBy('orders.kitchen_status')
            ->pluck(DB::raw('COUNT(*) AS aggregate'), 'orders.kitchen_status')
            ->map(fn (mixed $count): int => (int) $count);
        $oldest = $open()
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->whereIn('orders.kitchen_status', [KitchenStatus::Kitchen->value, KitchenStatus::Preparing->value])
            ->orderBy('orders.committed_at')
            ->orderBy('orders.id')
            ->first(['orders.order_number', 'orders.committed_at', 'orders.kitchen_status', 'branches.code']);
        $since = $oldest === null ? null : CarbonImmutable::parse((string) $oldest->committed_at, 'UTC');

        return [
            'open_sessions' => DB::table('store_sessions')
                ->where('status', StoreSessionStatus::Open->value)
                ->when($branch !== null, fn (QueryBuilder $query) => $query->where('branch_id', $branch?->id))
                ->count(),
            'kitchen' => $counts[KitchenStatus::Kitchen->value] ?? 0,
            'preparing' => $counts[KitchenStatus::Preparing->value] ?? 0,
            'ready' => $counts[KitchenStatus::Ready->value] ?? 0,
            'oldest' => $oldest === null || $since === null ? null : [
                'order_number' => (string) $oldest->order_number,
                'branch_code' => (string) $oldest->code,
                'status' => (string) $oldest->kitchen_status,
                'waiting_seconds' => max(0, (int) $since->diffInSeconds($now)),
                'since' => $since->setTimezone(ReportPeriod::TIMEZONE)->toIso8601String(),
            ],
            'as_of' => $now->setTimezone(ReportPeriod::TIMEZONE)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryAttention(?Branch $branch): array
    {
        if ($branch === null) {
            return [
                'mode' => 'branches',
                'branches' => array_values(Branch::query()
                    ->where('status', BranchStatus::Active)
                    ->orderBy('name')
                    ->orderBy('code')
                    ->get(['id', 'name', 'code'])
                    ->map(fn (Branch $item): array => [
                        'branch' => ['id' => $item->id, 'name' => $item->name, 'code' => $item->code],
                        'out_of_stock' => $this->count($item, 'out_of_stock'),
                        'low_stock' => $this->count($item, 'low_stock'),
                    ])
                    ->all()),
            ];
        }

        $items = [];
        foreach (['out_of_stock', 'low_stock'] as $status) {
            $query = $this->products($branch, $status)
                ->select(['products.id', 'products.name', 'inventory_balance.on_hand', 'inventory_configuration.low_stock_threshold'])
                ->orderBy($status === 'out_of_stock' ? 'products.name' : 'inventory_balance.on_hand')
                ->orderBy('products.name')
                ->limit(self::ATTENTION_LIMIT);
            foreach ($query->toBase()->get() as $product) {
                $items[] = [
                    'id' => (string) $product->id,
                    'name' => (string) $product->name,
                    'status' => $status,
                    'on_hand' => $product->on_hand === null ? 0 : (int) $product->on_hand,
                    'low_stock_threshold' => $product->low_stock_threshold === null ? null : (int) $product->low_stock_threshold,
                ];
            }
        }

        return [
            'mode' => 'branch',
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code],
            'out_of_stock' => $this->count($branch, 'out_of_stock'),
            'low_stock' => $this->count($branch, 'low_stock'),
            'items' => array_slice($items, 0, self::ATTENTION_LIMIT),
        ];
    }

    /**
     * The latest committed, non-voided Orders in scope, shaped like a Transaction History card summary.
     *
     * @return list<array<string, mixed>>
     */
    public function recentTransactions(?Branch $branch): array
    {
        return array_values(Order::query()
            ->with(['branch:id,name,code', 'payments:id,order_id,method'])
            ->when($branch !== null, fn (Builder $query) => $query->where('branch_id', $branch?->id))
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active, CommercialStatus::Completed])
            ->orderByDesc('committed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(function (Order $order): array {
                $methods = $order->payments->map(fn (Payment $payment): string => $payment->method->value)->unique();
                $committed = $order->committed_at === null ? null : CarbonImmutable::instance($order->committed_at)->setTimezone(ReportPeriod::TIMEZONE);

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_label' => $order->customer_label,
                    'table_name' => $order->table_name_snapshot,
                    'order_type' => $order->order_type->value,
                    'payment_status' => $order->payment_status->value,
                    'payment_method' => match (true) {
                        $methods->count() > 1 => 'split',
                        $methods->count() === 1 => (string) $methods->first(),
                        default => null,
                    },
                    'kitchen_status' => $order->kitchen_status->value,
                    'total' => $order->total,
                    'committed_at' => $committed?->toIso8601String(),
                    'time_label' => $committed?->format('M j · g:i A'),
                    'branch' => ['id' => $order->branch->id, 'name' => $order->branch->name, 'code' => $order->branch->code],
                ];
            })
            ->all());
    }

    /** @return Builder<Product> */
    private function products(Branch $branch, string $status): Builder
    {
        $query = Product::query()->where('products.is_active', true);
        $this->inventory->filterProducts($query, $branch, $status);

        return $query;
    }

    private function count(Branch $branch, string $status): int
    {
        return $this->products($branch, $status)->count('products.id');
    }
}
