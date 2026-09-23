<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Read-only business analytics shared by the Owner Dashboard and Reports, built on the Phase 16A report authority.
 *
 * Every figure covers committed Active/Completed Orders of the Store Sessions whose business date (the Manila date the
 * session opened) falls in the period, for one authorized Branch or All Branches:
 *
 * - Total sales = the current final `orders.total` (Phase 16A Net Sales); transactions = those Orders; items sold = their
 *   Order Item quantities; average order = sales ÷ transactions. Voided Orders contribute nothing.
 * - Cash and Cashless are net collections from `StoreSessionReconciliation` (Payment.amount − allocated corrections −
 *   payments of voided Orders). A CLOSED session uses its close-time snapshot unless an order filter narrows the Orders.
 *   Split legs already sit inside Cash and Cashless, so Split is explanatory only and never added again. Tendered cash
 *   and change (`amount_received`) are never sales.
 * - Category and product figures sum immutable Order Item `line_total` snapshots and name products by their
 *   `product_name_snapshot`. Order Items do not snapshot a category, so categories group by each product's current
 *   category (a deleted product is "Uncategorized"); amounts are never recomputed from current prices.
 * - Hours are Manila clock hours of `committed_at`. Prep time runs from `committed_at` to `ready_at`.
 * - The previous period has the same length and granularity; drilling into one Store Session has no comparison.
 *
 * Order filters (order type, how the Order was paid and the attributed cashier) narrow every figure, collections
 * included. Money is exact integer cents until presented as a decimal string.
 *
 * @phpstan-import-type SessionRow from StoreSessionSalesReport
 *
 * @phpstan-type OrderFilters array{order_types?: list<string>, payment_methods?: list<string>, cashiers?: list<int>}
 * @phpstan-type Tally array{orders: int, sales: int, items: int}
 * @phpstan-type Aggregate array{
 *     totals: array{orders: int, sales: int, items: int, done: int, prep_total: float, prep_count: int},
 *     buckets: array<string, array{orders: int, sales: int}>,
 *     hours: array<int, array{orders: int, sales: int, prep_total: float, prep_count: int}>,
 *     types: array<string, Tally>,
 *     methods: array<string, array{orders: int, sales: int}>,
 *     cashiers: array<int, array{orders: int, sales: int, cash: int, cashless: int, split: int, unpaid: int}>
 * }
 * @phpstan-type Delta array{direction: 'up'|'down'|'flat', text: string, tone: 'good'|'bad'|'neutral'}
 */
class SalesAnalytics
{
    public const ORDER_TYPES = ['dine_in' => 'Dine in', 'take_out' => 'Take out'];

    public const PAYMENT_METHODS = ['cash' => 'Cash', 'cashless' => 'Cashless', 'split' => 'Split'];

    /** The default clock-hour window (6 AM–9 PM); it widens to include any real activity outside it. */
    private const HOUR_WINDOW = [6, 21];

    private const CATEGORY_LIMIT = 7;

    public function __construct(private StoreSessionSalesReport $report) {}

    /**
     * The Phase 16A Sales & Store Session report and the analytics of the same period, sharing one set of queries.
     *
     * @param  array<string, mixed>  $filters  validated report filters
     * @return array{report: array<string, mixed>, analytics: array<string, mixed>}
     */
    public function for(?Branch $branch, array $filters, ?ReportPeriod $period = null): array
    {
        /** @var array{date?: string|null, from?: string|null, to?: string|null, session?: string|null} $periodFilters */
        $periodFilters = array_intersect_key($filters, array_flip(['date', 'from', 'to', 'session']));
        $period ??= ReportPeriod::fromFilters($periodFilters);
        $orderFilters = $this->orderFilters($filters);
        $scope = $orderFilters === [] ? null : fn (QueryBuilder $query) => $this->applyOrderFilters($query, $orderFilters);

        $sessions = $this->report->sessions($branch, $period);
        $selected = $this->report->selectedSessions($sessions, $periodFilters, $period);
        $drillDown = $selected !== $sessions;
        $rows = $this->report->figures($selected);
        $report = $this->report->present($branch, $periodFilters, $period, $sessions, $rows);
        $collectionRows = $scope === null ? $rows : $this->report->figures($selected, $scope);

        $current = $this->aggregate($selected, $period, $orderFilters);
        $previous = null;
        $previousCollections = null;
        if (! $drillDown) {
            $previousSessions = $this->report->sessions($branch, $period, true);
            $previous = $this->aggregate($previousSessions, $period, $orderFilters, true);
            $previousCollections = $this->collectionTotals($this->report->figures($previousSessions, $scope));
        }
        $collections = $this->collectionTotals($collectionRows);
        $products = $this->products($selected, $orderFilters);

        return [
            'report' => $report,
            'analytics' => [
                'comparison' => [
                    'available' => ! $drillDown,
                    'description' => $period->comparisonLabel,
                    'label' => $period->present()['comparison']['label'],
                ],
                'filters' => [
                    'order_types' => $orderFilters['order_types'] ?? [],
                    'payment_methods' => $orderFilters['payment_methods'] ?? [],
                    'cashiers' => $orderFilters['cashiers'] ?? [],
                    'active' => $orderFilters !== [],
                ],
                'filter_options' => $this->filterOptions($sessions),
                'kpis' => $this->kpis($current, $previous, $collections, $previousCollections),
                'trend' => $this->trend($period, $current, $previous),
                'collections' => $this->presentCollections($collections, $current),
                'categories' => $this->categories($products, $current['totals']['sales']),
                'order_types' => $this->orderTypes($current),
                ...$this->hours($current, $previous),
                'products' => $this->presentProducts($products, $current['totals']['sales']),
                'cashiers' => $this->cashiers($current),
                'kitchen' => $this->kitchen($current, $previous),
                'highlights' => $this->highlights($period, $current, $products, $collections),
                'branches' => $branch === null ? $this->branches($collectionRows, $current, $scope !== null) : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return OrderFilters
     */
    public function orderFilters(array $filters): array
    {
        $pick = fn (string $key, array $allowed): array => array_values(array_unique(array_filter(
            is_array($filters[$key] ?? null) ? array_map('strval', $filters[$key]) : [],
            fn (string $value): bool => in_array($value, $allowed, true),
        )));
        $types = $pick('order_types', array_keys(self::ORDER_TYPES));
        $methods = $pick('payment_methods', array_keys(self::PAYMENT_METHODS));
        $cashiers = array_values(array_unique(array_map('intval', array_filter(
            is_array($filters['cashiers'] ?? null) ? $filters['cashiers'] : [],
            fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        ))));

        return array_filter([
            'order_types' => count($types) === count(self::ORDER_TYPES) ? [] : $types,
            'payment_methods' => count($methods) === count(self::PAYMENT_METHODS) ? [] : $methods,
            'cashiers' => $cashiers,
        ], fn (array $values): bool => $values !== []);
    }

    /**
     * Human-readable labels of the active order filters, e.g. "Order type: Dine in".
     *
     * @param  array<string, mixed>  $analytics
     * @return list<string>
     */
    public function filterLabels(array $analytics): array
    {
        $labels = [];
        foreach (['order_types' => 'Order type', 'payment_methods' => 'Payment', 'cashiers' => 'Cashier'] as $group => $title) {
            $values = $analytics['filters'][$group] ?? [];
            if (! is_array($values) || $values === []) {
                continue;
            }
            $names = array_map(function (mixed $value) use ($analytics, $group): string {
                foreach ($analytics['filter_options'][$group] ?? [] as $option) {
                    if ($option['value'] === $value) {
                        return (string) $option['label'];
                    }
                }

                return 'Not in this period';
            }, $values);
            $labels[] = $title.': '.implode(', ', $names);
        }

        return $labels;
    }

    /**
     * Narrows an `orders` query to the Orders the report filters keep, on the same qualified `orders.` columns in
     * every report and reconciliation query.
     *
     * @param  OrderFilters  $filters
     */
    public function applyOrderFilters(QueryBuilder $query, array $filters): void
    {
        if (($filters['order_types'] ?? []) !== []) {
            $query->whereIn('orders.order_type', $filters['order_types']);
        }
        if (($filters['payment_methods'] ?? []) !== []) {
            $methods = $filters['payment_methods'];
            $query->whereRaw(self::paymentClassSql().' IN ('.implode(', ', array_fill(0, count($methods), '?')).')', $methods);
        }
        if (($filters['cashiers'] ?? []) !== []) {
            $cashiers = $filters['cashiers'];
            $query->whereRaw(self::cashierSql().' IN ('.implode(', ', array_fill(0, count($cashiers), '?')).')', $cashiers);
        }
    }

    /**
     * How an Order was paid, from all of its Payment legs: both channels = split, one channel = that channel, none =
     * unpaid (an outstanding Pay Later Order).
     *
     * @return literal-string
     */
    public static function paymentClassSql(): string
    {
        $cash = "EXISTS (SELECT 1 FROM payments pm WHERE pm.order_id = orders.id AND pm.method = 'cash')";
        $cashless = "EXISTS (SELECT 1 FROM payments pm WHERE pm.order_id = orders.id AND pm.method = 'cashless')";

        return "(CASE WHEN {$cash} AND {$cashless} THEN 'split' WHEN {$cash} THEN 'cash' WHEN {$cashless} THEN 'cashless' ELSE 'unpaid' END)";
    }

    /**
     * The cashier an Order is attributed to: its POS creator, or the cashier who loaded a Customer QR Order.
     *
     * @return literal-string
     */
    public static function cashierSql(): string
    {
        return 'COALESCE(orders.created_by_user_id, orders.loaded_by_user_id)';
    }

    /** @param Collection<int, StoreSession> $sessions */
    private function eligibleOrders(Collection $sessions): QueryBuilder
    {
        return DB::table('orders')
            ->join('store_sessions', function (JoinClause $join): void {
                $join->on('store_sessions.id', '=', 'orders.store_session_id')
                    ->on('store_sessions.branch_id', '=', 'orders.branch_id');
            })
            ->whereIn('orders.store_session_id', $sessions->modelKeys())
            ->whereNotNull('orders.committed_at')
            ->whereIn('orders.commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value]);
    }

    /**
     * One grouped pass over the eligible Orders.
     *
     * @param  Collection<int, StoreSession>  $sessions
     * @param  OrderFilters  $filters
     * @return Aggregate
     */
    private function aggregate(Collection $sessions, ReportPeriod $period, array $filters, bool $previous = false): array
    {
        $aggregate = [
            'totals' => ['orders' => 0, 'sales' => 0, 'items' => 0, 'done' => 0, 'prep_total' => 0.0, 'prep_count' => 0],
            'buckets' => array_fill_keys($period->granularity === 'hour' ? [] : $period->bucketKeys($previous), ['orders' => 0, 'sales' => 0]),
            'hours' => [],
            'types' => [],
            'methods' => [],
            'cashiers' => [],
        ];
        if ($sessions->isEmpty()) {
            return $aggregate;
        }
        $businessDates = $sessions->mapWithKeys(fn (StoreSession $session): array => [$session->id => $this->report->businessDate($session)])->all();
        $prep = ManilaSql::secondsBetween('orders.committed_at', 'orders.ready_at');
        $perOrder = $this->eligibleOrders($sessions)->select([
            DB::raw('orders.store_session_id AS session_id'),
            DB::raw(ManilaSql::hour('orders.committed_at').' AS hour'),
            DB::raw('orders.order_type AS order_type'),
            DB::raw(self::cashierSql().' AS cashier_id'),
            DB::raw(self::paymentClassSql().' AS payment_class'),
            DB::raw('CAST(ROUND(orders.total * 100) AS BIGINT) AS cents'),
            DB::raw('(SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = orders.id) AS items'),
            DB::raw("CASE WHEN orders.kitchen_status = '".KitchenStatus::Done->value."' THEN 1 ELSE 0 END AS done"),
            DB::raw("CASE WHEN orders.ready_at IS NOT NULL AND orders.ready_at >= orders.committed_at THEN {$prep} ELSE NULL END AS prep_seconds"),
        ]);
        $this->applyOrderFilters($perOrder, $filters);

        $groups = DB::query()->fromSub($perOrder, 'o')
            ->groupBy('session_id', 'hour', 'order_type', 'cashier_id', 'payment_class')
            ->get([
                'session_id', 'hour', 'order_type', 'cashier_id', 'payment_class',
                DB::raw('COUNT(*) AS orders'), DB::raw('COALESCE(SUM(cents), 0) AS sales'), DB::raw('COALESCE(SUM(items), 0) AS items'),
                DB::raw('COALESCE(SUM(done), 0) AS done'), DB::raw('COALESCE(SUM(prep_seconds), 0) AS prep_total'), DB::raw('COUNT(prep_seconds) AS prep_count'),
            ]);

        foreach ($groups as $group) {
            $orders = (int) $group->orders;
            $sales = (int) $group->sales;
            $items = (int) $group->items;
            $hour = (int) $group->hour;
            $prepTotal = (float) $group->prep_total;
            $prepCount = (int) $group->prep_count;
            $class = (string) $group->payment_class;
            $cashier = (int) ($group->cashier_id ?? 0);
            $totals = &$aggregate['totals'];
            $totals['orders'] += $orders;
            $totals['sales'] = ExactMoney::add($totals['sales'], $sales);
            $totals['items'] += $items;
            $totals['done'] += (int) $group->done;
            $totals['prep_total'] += $prepTotal;
            $totals['prep_count'] += $prepCount;
            unset($totals);

            $bucket = $period->granularity === 'hour'
                ? (string) $hour
                : $period->bucketKeyOf($businessDates[(string) $group->session_id] ?? '');
            $aggregate['buckets'][$bucket] ??= ['orders' => 0, 'sales' => 0];
            $aggregate['buckets'][$bucket]['orders'] += $orders;
            $aggregate['buckets'][$bucket]['sales'] += $sales;

            $aggregate['hours'][$hour] ??= ['orders' => 0, 'sales' => 0, 'prep_total' => 0.0, 'prep_count' => 0];
            $aggregate['hours'][$hour]['orders'] += $orders;
            $aggregate['hours'][$hour]['sales'] += $sales;
            $aggregate['hours'][$hour]['prep_total'] += $prepTotal;
            $aggregate['hours'][$hour]['prep_count'] += $prepCount;

            $type = (string) $group->order_type;
            $aggregate['types'][$type] ??= ['orders' => 0, 'sales' => 0, 'items' => 0];
            $aggregate['types'][$type]['orders'] += $orders;
            $aggregate['types'][$type]['sales'] += $sales;
            $aggregate['types'][$type]['items'] += $items;

            $aggregate['methods'][$class] ??= ['orders' => 0, 'sales' => 0];
            $aggregate['methods'][$class]['orders'] += $orders;
            $aggregate['methods'][$class]['sales'] += $sales;

            $aggregate['cashiers'][$cashier] ??= ['orders' => 0, 'sales' => 0, 'cash' => 0, 'cashless' => 0, 'split' => 0, 'unpaid' => 0];
            $aggregate['cashiers'][$cashier]['orders'] += $orders;
            $aggregate['cashiers'][$cashier]['sales'] += $sales;
            $aggregate['cashiers'][$cashier][in_array($class, ['cash', 'cashless', 'split'], true) ? $class : 'unpaid'] += $sales;
        }
        ksort($aggregate['hours']);

        return $aggregate;
    }

    /**
     * Product rows from immutable Order Item snapshots, grouped by product and snapshot name.
     *
     * @param  Collection<int, StoreSession>  $sessions
     * @param  OrderFilters  $filters
     * @return list<array{key: string, name: string, category: string, category_id: string|null, quantity: int, orders: int, sales: int}>
     */
    private function products(Collection $sessions, array $filters): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }
        $query = $this->eligibleOrders($sessions)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id');
        $this->applyOrderFilters($query, $filters);

        return array_values($query
            ->groupBy('order_items.product_id', 'order_items.product_name_snapshot', 'categories.id', 'categories.name')
            ->get([
                DB::raw('order_items.product_id AS product_id'),
                DB::raw('order_items.product_name_snapshot AS name'),
                DB::raw('categories.id AS category_id'),
                DB::raw('categories.name AS category_name'),
                DB::raw('COALESCE(SUM(order_items.quantity), 0) AS quantity'),
                DB::raw('COALESCE(SUM(CAST(ROUND(order_items.line_total * 100) AS BIGINT)), 0) AS sales'),
                DB::raw('COUNT(DISTINCT order_items.order_id) AS orders'),
            ])
            ->map(fn (object $row): array => [
                'key' => ($row->product_id ?? 'deleted').'|'.$row->name,
                'name' => (string) $row->name,
                'category' => $row->category_name === null ? 'Uncategorized' : (string) $row->category_name,
                'category_id' => $row->category_id === null ? null : (string) $row->category_id,
                'quantity' => (int) $row->quantity,
                'orders' => (int) $row->orders,
                'sales' => (int) $row->sales,
            ])
            ->sort(fn (array $a, array $b): int => [$b['sales'], $b['quantity'], $a['name']] <=> [$a['sales'], $a['quantity'], $b['name']])
            ->all());
    }

    /**
     * @param  list<SessionRow>  $rows
     * @return array{cash: int, cashless: int, split: array{count: int, cash: int, cashless: int}, unallocated: int, expenses: int}
     */
    private function collectionTotals(array $rows): array
    {
        $totals = ['cash' => 0, 'cashless' => 0, 'split' => ['count' => 0, 'cash' => 0, 'cashless' => 0], 'unallocated' => 0, 'expenses' => 0];
        foreach ($rows as $row) {
            $net = $this->report->collections($row['flows']);
            $totals['cash'] += $net['cash'];
            $totals['cashless'] += $net['cashless'];
            $totals['split']['count'] += $row['flows']['split']['count'];
            $totals['split']['cash'] += $row['flows']['split']['cash'];
            $totals['split']['cashless'] += $row['flows']['split']['cashless'];
            $totals['unallocated'] += $row['flows']['corrections']['unallocated'];
            $totals['expenses'] += $row['flows']['expenses']['cash'] + $row['flows']['expenses']['cashless'];
        }

        return $totals;
    }

    /**
     * @param  Aggregate  $current
     * @param  Aggregate|null  $previous
     * @param  array{cash: int, cashless: int}  $collections
     * @param  array{cash: int, cashless: int}|null  $previousCollections
     * @return array<string, array{value: string|int|null, previous: string|int|null, delta: Delta|null}>
     */
    private function kpis(array $current, ?array $previous, array $collections, ?array $previousCollections): array
    {
        $average = fn (array $totals): ?int => $totals['orders'] === 0 ? null : intdiv(2 * $totals['sales'] + $totals['orders'], 2 * $totals['orders']);
        $share = $this->share($collections['cashless'], $collections['cash'] + $collections['cashless']);
        $previousShare = $previousCollections === null ? null : $this->share($previousCollections['cashless'], $previousCollections['cash'] + $previousCollections['cashless']);
        $currentAverage = $average($current['totals']);
        $previousAverage = $previous === null ? null : $average($previous['totals']);
        $metric = fn (int $value, ?int $prior): array => [
            'value' => $value,
            'previous' => $prior,
            'delta' => $prior === null ? null : $this->percentDelta($value, $prior),
        ];

        return [
            'sales' => [
                'value' => ExactMoney::decimal($current['totals']['sales']),
                'previous' => $previous === null ? null : ExactMoney::decimal($previous['totals']['sales']),
                'delta' => $previous === null ? null : $this->percentDelta($current['totals']['sales'], $previous['totals']['sales']),
            ],
            'transactions' => $metric($current['totals']['orders'], $previous['totals']['orders'] ?? null),
            'average_order' => [
                'value' => $currentAverage === null ? null : ExactMoney::decimal($currentAverage),
                'previous' => $previousAverage === null ? null : ExactMoney::decimal($previousAverage),
                'delta' => $currentAverage === null || $previousAverage === null ? null : $this->percentDelta($currentAverage, $previousAverage),
            ],
            'items' => $metric($current['totals']['items'], $previous['totals']['items'] ?? null),
            'cashless_share' => [
                'value' => $share,
                'previous' => $previousShare,
                'delta' => $share === null || $previousShare === null ? null : $this->pointsDelta($share, $previousShare),
            ],
        ];
    }

    /**
     * @param  Aggregate  $current
     * @param  Aggregate|null  $previous
     * @return array{granularity: string, buckets: list<array<string, mixed>>, peak: array{sales: string, transactions: int}}
     */
    private function trend(ReportPeriod $period, array $current, ?array $previous): array
    {
        if ($period->granularity === 'hour') {
            [$first, $last] = $this->hourWindow($current, $previous);
            $keys = array_map('strval', range($first, $last));
            $previousKeys = $keys;
        } else {
            $keys = $period->bucketKeys();
            $previousKeys = $period->bucketKeys(true);
        }
        $buckets = [];
        foreach ($keys as $index => $key) {
            $bucket = $current['buckets'][$key] ?? ['orders' => 0, 'sales' => 0];
            $prior = $previous === null ? null : ($previous['buckets'][$previousKeys[$index] ?? ''] ?? ['orders' => 0, 'sales' => 0]);
            $labels = $period->granularity === 'hour' ? $this->hourLabels((int) $key) : $period->bucketLabels($key);
            $buckets[] = [
                'key' => $key,
                'label' => $labels['label'],
                'full' => $labels['full'],
                'sales' => ExactMoney::decimal($bucket['sales']),
                'sales_cents' => $bucket['sales'],
                'transactions' => $bucket['orders'],
                'previous' => $prior === null ? null : [
                    'sales' => ExactMoney::decimal($prior['sales']),
                    'sales_cents' => $prior['sales'],
                    'transactions' => $prior['orders'],
                ],
            ];
        }
        $peak = array_reduce($buckets, fn (?array $best, array $bucket): array => $best === null || $bucket['sales_cents'] > $best['sales_cents'] ? $bucket : $best);

        return [
            'granularity' => $period->granularity,
            'buckets' => $buckets,
            'peak' => [
                'sales' => ExactMoney::decimal($peak['sales_cents'] ?? 0),
                'transactions' => (int) max(array_column($buckets, 'transactions') ?: [0]),
            ],
        ];
    }

    /**
     * @param  array{cash: int, cashless: int, split: array{count: int, cash: int, cashless: int}, unallocated: int, expenses: int}  $collections
     * @param  Aggregate  $current
     * @return array<string, mixed>
     */
    private function presentCollections(array $collections, array $current): array
    {
        $total = $collections['cash'] + $collections['cashless'];
        $orders = fn (string $class): int => $current['methods'][$class]['orders'] ?? 0;

        return [
            'cash' => ExactMoney::signedDecimal($collections['cash']),
            'cashless' => ExactMoney::signedDecimal($collections['cashless']),
            'total' => ExactMoney::signedDecimal($total),
            'cash_cents' => $collections['cash'],
            'cashless_cents' => $collections['cashless'],
            'cash_share' => $this->share($collections['cash'], $total),
            'cashless_share' => $this->share($collections['cashless'], $total),
            'orders' => ['cash' => $orders('cash'), 'cashless' => $orders('cashless'), 'split' => $orders('split'), 'unpaid' => $orders('unpaid')],
            'split' => [
                'count' => $collections['split']['count'],
                'total' => ExactMoney::signedDecimal($collections['split']['cash'] + $collections['split']['cashless']),
                'cash' => ExactMoney::signedDecimal($collections['split']['cash']),
                'cashless' => ExactMoney::signedDecimal($collections['split']['cashless']),
            ],
            'unallocated' => ExactMoney::signedDecimal($collections['unallocated']),
        ];
    }

    /**
     * @param  list<array{key: string, name: string, category: string, category_id: string|null, quantity: int, orders: int, sales: int}>  $products
     * @return list<array{name: string, sales: string, sales_cents: int, items: int, share: int|null}>
     */
    private function categories(array $products, int $totalSales): array
    {
        $categories = [];
        foreach ($products as $product) {
            $key = $product['category_id'] ?? 'uncategorized';
            $categories[$key] ??= ['name' => $product['category'], 'sales' => 0, 'items' => 0];
            $categories[$key]['sales'] += $product['sales'];
            $categories[$key]['items'] += $product['quantity'];
        }
        usort($categories, fn (array $a, array $b): int => [$b['sales'], $b['items'], $a['name']] <=> [$a['sales'], $a['items'], $b['name']]);
        if (count($categories) > self::CATEGORY_LIMIT) {
            $others = array_slice($categories, self::CATEGORY_LIMIT - 1);
            $categories = [...array_slice($categories, 0, self::CATEGORY_LIMIT - 1), [
                'name' => count($others).' other categories',
                'sales' => array_sum(array_column($others, 'sales')),
                'items' => array_sum(array_column($others, 'items')),
            ]];
        }

        return array_map(fn (array $category): array => [
            'name' => $category['name'],
            'sales' => ExactMoney::decimal($category['sales']),
            'sales_cents' => $category['sales'],
            'items' => $category['items'],
            'share' => $this->share($category['sales'], $totalSales),
        ], $categories);
    }

    /**
     * @param  Aggregate  $current
     * @return list<array<string, mixed>>
     */
    private function orderTypes(array $current): array
    {
        return array_map(function (string $type, string $label) use ($current): array {
            $tally = $current['types'][$type] ?? ['orders' => 0, 'sales' => 0, 'items' => 0];

            return [
                'type' => $type,
                'label' => $label,
                'sales' => ExactMoney::decimal($tally['sales']),
                'sales_cents' => $tally['sales'],
                'transactions' => $tally['orders'],
                'items' => $tally['items'],
                'average' => $tally['orders'] === 0 ? null : ExactMoney::decimal(intdiv(2 * $tally['sales'] + $tally['orders'], 2 * $tally['orders'])),
                'share' => $this->share($tally['sales'], $current['totals']['sales']),
            ];
        }, array_keys(self::ORDER_TYPES), self::ORDER_TYPES);
    }

    /**
     * Clock-hour sales, two-hour dayparts and the busiest hour.
     *
     * @param  Aggregate  $current
     * @param  Aggregate|null  $previous
     * @return array{hours: list<array<string, mixed>>, dayparts: list<array<string, mixed>>, peak_hour: array<string, mixed>|null}
     */
    private function hours(array $current, ?array $previous): array
    {
        [$first, $last] = $this->hourWindow($current, $previous);
        $hours = [];
        foreach (range($first, $last) as $hour) {
            $tally = $current['hours'][$hour] ?? ['orders' => 0, 'sales' => 0];
            $hours[] = [
                'hour' => $hour,
                ...$this->hourLabels($hour),
                'sales' => ExactMoney::decimal($tally['sales']),
                'sales_cents' => $tally['sales'],
                'transactions' => $tally['orders'],
            ];
        }
        $dayparts = [];
        for ($start = $first - ($first % 2); $start <= $last; $start += 2) {
            $sales = 0;
            $orders = 0;
            foreach ([$start, $start + 1] as $hour) {
                $sales += $current['hours'][$hour]['sales'] ?? 0;
                $orders += $current['hours'][$hour]['orders'] ?? 0;
            }
            $dayparts[] = [
                'hour' => $start,
                'label' => $this->hourLabels($start)['long'],
                'full' => $this->hourLabels($start)['long'].' – '.$this->hourLabels(($start + 2) % 24)['long'],
                'sales' => ExactMoney::decimal($sales),
                'sales_cents' => $sales,
                'transactions' => $orders,
            ];
        }
        $peak = null;
        foreach ($current['hours'] as $hour => $tally) {
            if ($tally['orders'] > 0 && ($peak === null || $tally['sales'] > $peak['sales_cents'])) {
                $peak = ['hour' => $hour, 'label' => $this->hourLabels($hour)['full'], 'sales' => ExactMoney::decimal($tally['sales']), 'sales_cents' => $tally['sales'], 'transactions' => $tally['orders']];
            }
        }

        return ['hours' => $hours, 'dayparts' => $dayparts, 'peak_hour' => $peak];
    }

    /**
     * @param  list<array{key: string, name: string, category: string, category_id: string|null, quantity: int, orders: int, sales: int}>  $products
     * @return list<array<string, mixed>>
     */
    private function presentProducts(array $products, int $totalSales): array
    {
        return array_map(fn (array $product): array => [
            'key' => $product['key'],
            'name' => $product['name'],
            'category' => $product['category'],
            'quantity' => $product['quantity'],
            'orders' => $product['orders'],
            'sales' => ExactMoney::decimal($product['sales']),
            'sales_cents' => $product['sales'],
            'share' => $this->share($product['sales'], $totalSales),
            'average_price' => $product['quantity'] === 0 ? null : ExactMoney::decimal(intdiv(2 * $product['sales'] + $product['quantity'], 2 * $product['quantity'])),
        ], $products);
    }

    /**
     * @param  Aggregate  $current
     * @return list<array<string, mixed>>
     */
    private function cashiers(array $current): array
    {
        $names = User::query()->whereKey(array_keys(array_filter($current['cashiers'], fn (array $tally, int $id): bool => $id > 0, ARRAY_FILTER_USE_BOTH)))->pluck('name', 'id');
        $rows = [];
        foreach ($current['cashiers'] as $id => $tally) {
            $rows[] = [
                'id' => $id > 0 ? $id : null,
                'name' => $id > 0 ? (string) ($names[$id] ?? 'Former staff') : 'Unattributed',
                'transactions' => $tally['orders'],
                'sales' => ExactMoney::decimal($tally['sales']),
                'sales_cents' => $tally['sales'],
                'average' => $tally['orders'] === 0 ? null : ExactMoney::decimal(intdiv(2 * $tally['sales'] + $tally['orders'], 2 * $tally['orders'])),
                'cash' => ExactMoney::decimal($tally['cash']),
                'cashless' => ExactMoney::decimal($tally['cashless']),
                'split' => ExactMoney::decimal($tally['split']),
                'unpaid' => ExactMoney::decimal($tally['unpaid']),
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['sales_cents'], $b['transactions'], $a['name']] <=> [$a['sales_cents'], $a['transactions'], $b['name']]);

        return $rows;
    }

    /**
     * Completed Orders and the average committed-to-ready preparation time, overall and per Manila clock hour.
     *
     * @param  Aggregate  $current
     * @param  Aggregate|null  $previous
     * @return array<string, mixed>
     */
    private function kitchen(array $current, ?array $previous): array
    {
        $average = fn (array $tally): ?int => $tally['prep_count'] === 0 ? null : (int) round($tally['prep_total'] / $tally['prep_count']);
        $prep = $average($current['totals']);
        $previousPrep = $previous === null ? null : $average($previous['totals']);
        [$first, $last] = $this->hourWindow($current, $previous);
        $byHour = [];
        foreach (range($first, $last) as $hour) {
            $tally = $current['hours'][$hour] ?? ['prep_total' => 0.0, 'prep_count' => 0];
            $byHour[] = [
                'hour' => $hour,
                ...$this->hourLabels($hour),
                'average_seconds' => $average($tally),
                'orders' => $tally['prep_count'],
            ];
        }
        $timed = array_values(array_filter($byHour, fn (array $hour): bool => $hour['average_seconds'] !== null));
        $pick = fn (int $direction): ?array => $timed === [] ? null : array_reduce($timed, fn (?array $best, array $hour): array => $best === null || $direction * ($hour['average_seconds'] <=> $best['average_seconds']) > 0 ? $hour : $best);

        return [
            'completed' => $current['totals']['done'],
            'completed_delta' => $previous === null ? null : $this->percentDelta($current['totals']['done'], $previous['totals']['done']),
            'average_prep_seconds' => $prep,
            'prep_delta' => $prep === null || $previousPrep === null ? null : $this->secondsDelta($prep, $previousPrep),
            'timed_orders' => $current['totals']['prep_count'],
            'by_hour' => $byHour,
            'fastest' => $pick(-1),
            'slowest' => count($timed) > 1 ? $pick(1) : null,
        ];
    }

    /**
     * Plain selections from the figures above — no estimates or projections.
     *
     * @param  Aggregate  $current
     * @param  list<array{key: string, name: string, category: string, category_id: string|null, quantity: int, orders: int, sales: int}>  $products
     * @param  array{cash: int, cashless: int}  $collections
     * @return list<array{label: string, value: string, amount: string|null, detail: string}>
     */
    private function highlights(ReportPeriod $period, array $current, array $products, array $collections): array
    {
        $total = $current['totals']['sales'];
        $categories = $this->categories($products, $total);
        $topCategory = $categories[0] ?? null;
        $topProduct = $products[0] ?? null;
        $peak = $this->hours($current, null)['peak_hour'];
        $share = $this->share($collections['cashless'], $collections['cash'] + $collections['cashless']);
        $highlights = [
            [
                'label' => 'Top category',
                'value' => $topCategory['name'] ?? '—',
                'amount' => $topCategory === null ? null : $topCategory['sales'],
                'detail' => $topCategory === null ? 'No sales in this period' : $this->shareText($topCategory['share']).' of sales',
            ],
            [
                'label' => 'Peak hour',
                'value' => $peak['label'] ?? '—',
                'amount' => $peak['sales'] ?? null,
                'detail' => $peak === null ? 'No sales in this period' : 'in that hour · '.$peak['transactions'].' transactions',
            ],
            [
                'label' => 'Top product',
                'value' => $topProduct['name'] ?? '—',
                'amount' => $topProduct === null ? null : ExactMoney::decimal($topProduct['sales']),
                'detail' => $topProduct === null ? 'No sales in this period' : $topProduct['quantity'].' sold',
            ],
            [
                'label' => 'Cashless share',
                'value' => $share === null ? '—' : $this->shareText($share),
                'amount' => $share === null ? null : ExactMoney::signedDecimal($collections['cashless']),
                'detail' => $share === null ? 'No collections in this period' : 'of collected sales',
            ],
        ];
        if ($period->granularity !== 'hour') {
            $strongest = null;
            foreach ($current['buckets'] as $key => $bucket) {
                if ($bucket['orders'] > 0 && ($strongest === null || $bucket['sales'] > $strongest[1]['sales'])) {
                    $strongest = [$key, $bucket];
                }
            }
            $highlights[] = [
                'label' => $period->granularity === 'month' ? 'Strongest month' : 'Strongest day',
                'value' => $strongest === null ? '—' : $period->bucketLabels((string) $strongest[0])['full'],
                'amount' => $strongest === null ? null : ExactMoney::decimal($strongest[1]['sales']),
                'detail' => $strongest === null ? 'No sales in this period' : $strongest[1]['orders'].' transactions',
            ];
        }

        return $highlights;
    }

    /**
     * Factual per-Branch figures for All Branches: no ranking language and no summed stock.
     *
     * @param  list<SessionRow>  $rows
     * @param  Aggregate  $current
     * @return list<array<string, mixed>>
     */
    private function branches(array $rows, array $current, bool $filtered): array
    {
        $figures = [];
        foreach ($rows as $row) {
            $branchId = (string) $row['session']->branch_id;
            $net = $this->report->collections($row['flows']);
            $figures[$branchId] ??= ['sessions' => 0, 'orders' => 0, 'sales' => 0, 'cash' => 0, 'cashless' => 0, 'expenses' => 0];
            $figures[$branchId]['sessions']++;
            $figures[$branchId]['orders'] += $row['orders'];
            $figures[$branchId]['sales'] += $row['net_sales'];
            $figures[$branchId]['cash'] += $net['cash'];
            $figures[$branchId]['cashless'] += $net['cashless'];
            $figures[$branchId]['expenses'] += $row['flows']['expenses']['cash'] + $row['flows']['expenses']['cashless'];
        }

        return array_values(Branch::query()
            ->where(fn ($query) => $query->where('status', BranchStatus::Active)->orWhereKey(array_keys($figures)))
            ->orderBy('name')
            ->orderBy('code')
            ->get(['id', 'name', 'code', 'status'])
            ->map(function (Branch $branch) use ($figures, $filtered, $current): array {
                $figure = $figures[$branch->id] ?? ['sessions' => 0, 'orders' => 0, 'sales' => 0, 'cash' => 0, 'cashless' => 0, 'expenses' => 0];

                return [
                    'branch' => ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code, 'status' => $branch->status->value],
                    'sessions' => $figure['sessions'],
                    'orders' => $figure['orders'],
                    'sales' => ExactMoney::decimal($figure['sales']),
                    'sales_cents' => $figure['sales'],
                    'share' => $this->share($figure['sales'], $current['totals']['sales']),
                    'cash' => ExactMoney::signedDecimal($figure['cash']),
                    'cashless' => ExactMoney::signedDecimal($figure['cashless']),
                    /** Expenses belong to the drawer, not to an Order, so an order filter cannot narrow them. */
                    'expenses' => $filtered ? null : ExactMoney::decimal($figure['expenses']),
                ];
            })
            ->all());
    }

    /**
     * Values offered by the report filter, from the whole period and scope so a narrowed report can widen again.
     *
     * @param  Collection<int, StoreSession>  $sessions
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    private function filterOptions(Collection $sessions): array
    {
        $cashierIds = $sessions->isEmpty() ? [] : $this->eligibleOrders($sessions)
            ->whereRaw(self::cashierSql().' IS NOT NULL')
            ->distinct()
            ->pluck(DB::raw(self::cashierSql().' AS cashier_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'order_types' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::ORDER_TYPES), self::ORDER_TYPES),
            'payment_methods' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::PAYMENT_METHODS), self::PAYMENT_METHODS),
            'cashiers' => array_values(User::query()->whereKey($cashierIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])->all()),
        ];
    }

    /**
     * The clock-hour window shown by every hour chart: 6 AM–9 PM, widened to include any real activity.
     *
     * @param  Aggregate  $current
     * @param  Aggregate|null  $previous
     * @return array{0: int, 1: int}
     */
    private function hourWindow(array $current, ?array $previous): array
    {
        $active = [...array_keys($current['hours']), ...array_keys($previous['hours'] ?? [])];

        return [min([self::HOUR_WINDOW[0], ...$active]), max([self::HOUR_WINDOW[1], ...$active])];
    }

    /** @return array{label: string, long: string, full: string} */
    private function hourLabels(int $hour): array
    {
        $twelve = fn (int $value): int => $value % 12 === 0 ? 12 : $value % 12;
        $long = fn (int $value): string => $twelve($value).($value < 12 ? ' AM' : ' PM');

        return [
            'label' => $twelve($hour).($hour < 12 ? 'a' : 'p'),
            'long' => $long($hour),
            'full' => $long($hour).' – '.$long(($hour + 1) % 24),
        ];
    }

    /** A share in basis points (hundredths of a percent), or null when the whole is not positive. */
    private function share(int $part, int $whole): ?int
    {
        return $whole <= 0 ? null : intdiv(2 * $part * 10000 + $whole, 2 * $whole);
    }

    private function shareText(?int $basisPoints): string
    {
        return $basisPoints === null ? '—' : number_format($basisPoints / 100, 1).'%';
    }

    /** @return Delta */
    private function percentDelta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return $current > 0
                ? ['direction' => 'up', 'text' => 'New', 'tone' => 'good']
                : ['direction' => 'flat', 'text' => '0.0%', 'tone' => 'neutral'];
        }
        $tenths = (int) round(($current - $previous) * 1000 / $previous);
        $direction = abs($tenths) < 2 ? 'flat' : ($tenths > 0 ? 'up' : 'down');

        return [
            'direction' => $direction,
            'text' => ($tenths > 0 ? '+' : ($tenths < 0 ? '−' : '')).number_format(abs($tenths) / 10, 1).'%',
            'tone' => match ($direction) {
                'up' => 'good',
                'down' => 'bad',
                default => 'neutral',
            },
        ];
    }

    /** @return Delta */
    private function pointsDelta(int $currentBasisPoints, int $previousBasisPoints): array
    {
        $tenths = (int) round(($currentBasisPoints - $previousBasisPoints) / 10);
        $direction = abs($tenths) < 2 ? 'flat' : ($tenths > 0 ? 'up' : 'down');

        return [
            'direction' => $direction,
            'text' => ($tenths > 0 ? '+' : ($tenths < 0 ? '−' : '')).number_format(abs($tenths) / 10, 1).' pts',
            'tone' => 'neutral',
        ];
    }

    /**
     * Lower preparation time is better.
     *
     * @return Delta
     */
    private function secondsDelta(int $current, int $previous): array
    {
        $difference = $current - $previous;
        $direction = abs($difference) < 3 ? 'flat' : ($difference > 0 ? 'up' : 'down');

        return [
            'direction' => $direction,
            'text' => ($difference > 0 ? '+' : ($difference < 0 ? '−' : '')).intdiv(abs($difference), 60).'m '.str_pad((string) (abs($difference) % 60), 2, '0', STR_PAD_LEFT).'s',
            'tone' => match ($direction) {
                'up' => 'bad',
                'down' => 'good',
                default => 'neutral',
            },
        ];
    }
}
