<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\StoreSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type MoneyCents array{cash: int, cashless: int}
 * @phpstan-type CountedCents array{cash: int, cashless: int, count: int}
 * @phpstan-type ReconciliationCents array{
 *     opening: MoneyCents,
 *     sales: array{cash: int, cashless: int, total: int, count: int},
 *     split: CountedCents,
 *     expenses: CountedCents,
 *     corrections: CountedCents,
 *     voids: CountedCents,
 *     expected: MoneyCents
 * }
 *
 * The single authority for Close Store pre-checks and exact Cash/Cashless reconciliation.
 *
 * Expected Closing = Opening + Payment rows − Store Expenses − corrections on non-voided Orders − payments of voided Orders,
 * computed independently for Cash and Cashless in integer cents. Split legs are already Payment rows, so the split
 * breakdown is explanatory only. A voided Order's reversal covers all of its payments, so its earlier corrections are
 * not subtracted a second time.
 */
class StoreSessionReconciliation
{
    private const LIST_LIMIT = 25;

    public function __construct(private PaymentCorrectionAllocation $allocation) {}

    /**
     * @return array{
     *     ready: bool,
     *     blockers: array<string, array<string, mixed>>,
     *     qr: array{unclaimed_count: int},
     *     reconciliation: array<string, array<string, int|string>>|null
     * }
     */
    public function preview(Branch $branch, StoreSession $session): array
    {
        $blockers = $this->blockers($branch, $session);
        $ready = array_sum($this->blockerCounts($blockers)) === 0;

        return [
            'ready' => $ready,
            'blockers' => $blockers,
            'qr' => ['unclaimed_count' => $this->unclaimedQrOrders($branch, $session)->count()],
            'reconciliation' => $ready ? $this->present($this->calculate($branch, $session)) : null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function blockers(Branch $branch, StoreSession $session): array
    {
        $payments = $this->centsSubquery('payments', 'amount');
        $adjustments = $this->centsSubquery('order_adjustments', 'amount');
        $total = $this->cents('orders.total');
        $settled = "CASE WHEN {$payments} > {$adjustments} THEN {$payments} - {$adjustments} ELSE 0 END";
        $activeKitchen = [KitchenStatus::Kitchen->value, KitchenStatus::Preparing->value, KitchenStatus::Ready->value];

        $orders = DB::table('orders')
            ->leftJoin('branch_tables', 'branch_tables.id', '=', 'orders.branch_table_id')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.store_session_id', $session->id)
            ->whereNotNull('orders.committed_at')
            ->whereIn('orders.commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value])
            ->where(fn ($query) => $query
                ->whereIn('orders.kitchen_status', $activeKitchen)
                ->orWhereRaw("{$total} - {$settled} > 0"))
            ->orderBy('orders.committed_at')
            ->orderBy('orders.id')
            ->get([
                'orders.id', 'orders.order_number', 'orders.customer_label', 'orders.kitchen_status',
                'orders.payment_term', DB::raw('COALESCE(orders.table_name_snapshot, branch_tables.name) AS table_name'),
                DB::raw("{$total} AS total_cents"), DB::raw("{$settled} AS settled_cents"),
            ])
            ->map(fn (object $row): array => (array) $row);

        $outstanding = $orders
            ->map(function (array $row): array {
                $outstanding = max(0, (int) $row['total_cents'] - (int) $row['settled_cents']);

                return [
                    'id' => (string) $row['id'],
                    'order_number' => $row['order_number'],
                    'customer_label' => $row['customer_label'],
                    'table_name' => $row['table_name'],
                    'outstanding_cents' => $outstanding,
                    'payment_status' => ((int) $row['settled_cents'] > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid)->value,
                    'payment_term' => $row['payment_term'],
                ];
            })
            ->filter(fn (array $row): bool => $row['outstanding_cents'] > 0)
            ->values();
        $kitchen = $orders
            ->filter(fn (array $row): bool => in_array($row['kitchen_status'], $activeKitchen, true))
            ->map(fn (array $row): array => [
                'id' => (string) $row['id'],
                'order_number' => $row['order_number'],
                'customer_label' => $row['customer_label'],
                'table_name' => $row['table_name'],
                'kitchen_status' => $row['kitchen_status'],
            ])
            ->values();
        $loadedQr = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.loaded_by_user_id')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.store_session_id', $session->id)
            ->where('orders.source', OrderSource::CustomerQr->value)
            ->where('orders.commercial_status', CommercialStatus::Submitted->value)
            ->whereNull('orders.committed_at')
            ->whereNotNull('orders.loaded_by_user_id')
            ->orderBy('orders.qr_sequence')
            ->get(['orders.id', 'orders.qr_sequence', 'orders.customer_label', 'users.name AS loaded_by'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'qr_number' => CustomerQrNumber::display($row->qr_sequence === null ? null : (int) $row->qr_sequence),
                'customer_label' => $row->customer_label,
                'loaded_by' => $row->loaded_by,
            ]);
        $corrections = $this->ambiguousCorrections($branch, $session);

        return [
            'outstanding' => [
                'count' => $outstanding->count(),
                'total' => ExactMoney::decimal($outstanding->sum('outstanding_cents')),
                'orders' => $outstanding->take(self::LIST_LIMIT)->map(fn (array $row): array => [
                    ...array_diff_key($row, ['outstanding_cents' => true]),
                    'outstanding' => ExactMoney::decimal($row['outstanding_cents']),
                ])->all(),
            ],
            'kitchen' => [
                'count' => $kitchen->count(),
                'orders' => $kitchen->take(self::LIST_LIMIT)->all(),
            ],
            'loaded_qr' => [
                'count' => $loadedQr->count(),
                'orders' => $loadedQr->take(self::LIST_LIMIT)->all(),
            ],
            'corrections' => [
                'count' => count($corrections),
                'items' => array_slice($corrections, 0, self::LIST_LIMIT),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $blockers
     * @return array{outstanding: int, kitchen: int, loaded_qr: int, corrections: int}
     */
    public function blockerCounts(array $blockers): array
    {
        return [
            'outstanding' => (int) $blockers['outstanding']['count'],
            'kitchen' => (int) $blockers['kitchen']['count'],
            'loaded_qr' => (int) $blockers['loaded_qr']['count'],
            'corrections' => (int) $blockers['corrections']['count'],
        ];
    }

    /**
     * Submitted, unclaimed and uncommitted Customer QR orders that Store Close archives.
     *
     * @return Builder<Order>
     */
    public function unclaimedQrOrders(Branch $branch, StoreSession $session): Builder
    {
        return Order::query()
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id)
            ->where('source', OrderSource::CustomerQr)
            ->where('commercial_status', CommercialStatus::Submitted)
            ->whereNull('loaded_by_user_id')
            ->whereNull('committed_at')
            ->whereNull('archived_at');
    }

    /**
     * Exact reconciliation in integer cents. Callers must first confirm that no blocker remains.
     *
     * @return ReconciliationCents
     */
    public function calculate(Branch $branch, StoreSession $session): array
    {
        $amount = $this->cents('payments.amount');
        $voided = "CASE WHEN orders.commercial_status = 'voided' THEN 1 ELSE 0 END";
        $sales = ['cash' => 0, 'cashless' => 0];
        $voids = ['cash' => 0, 'cashless' => 0];
        $paymentCount = 0;
        DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.branch_id', $branch->id)
            ->where('payments.store_session_id', $session->id)
            ->groupByRaw("payments.method, {$voided}")
            ->get([DB::raw('payments.method AS method'), DB::raw("{$voided} AS voided"), DB::raw("COALESCE(SUM({$amount}), 0) AS cents"), DB::raw('COUNT(*) AS payment_count')])
            ->each(function (object $row) use (&$sales, &$voids, &$paymentCount): void {
                $method = $row->method === 'cash' ? 'cash' : 'cashless';
                $sales[$method] = ExactMoney::add($sales[$method], (int) $row->cents);
                $paymentCount += (int) $row->payment_count;
                if ((int) $row->voided === 1) {
                    $voids[$method] = ExactMoney::add($voids[$method], (int) $row->cents);
                }
            });
        $voidedOrders = (int) DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.branch_id', $branch->id)
            ->where('payments.store_session_id', $session->id)
            ->where('orders.commercial_status', CommercialStatus::Voided->value)
            ->distinct()
            ->count('payments.order_id');

        $groupKey = "COALESCE(CAST(payments.payment_group_id AS VARCHAR(64)), REPLACE(REPLACE(payments.idempotency_key, ':cashless', ''), ':cash', ''))";
        $splitGroups = DB::table('payments')
            ->where('payments.branch_id', $branch->id)
            ->where('payments.store_session_id', $session->id)
            ->groupByRaw($groupKey)
            ->havingRaw('COUNT(DISTINCT payments.method) = 2')
            ->select([
                DB::raw("{$groupKey} AS group_key"),
                DB::raw("SUM(CASE WHEN payments.method = 'cash' THEN {$amount} ELSE 0 END) AS cash_cents"),
                DB::raw("SUM(CASE WHEN payments.method = 'cashless' THEN {$amount} ELSE 0 END) AS cashless_cents"),
            ]);
        $split = DB::query()->fromSub($splitGroups, 'split_groups')
            ->first([DB::raw('COUNT(*) AS split_count'), DB::raw('COALESCE(SUM(cash_cents), 0) AS cash_cents'), DB::raw('COALESCE(SUM(cashless_cents), 0) AS cashless_cents')]);

        $expenseAmount = $this->cents('amount');
        $expenses = DB::table('store_session_expenses')
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id)
            ->first([
                DB::raw("COALESCE(SUM(CASE WHEN payment_source = 'cash' THEN {$expenseAmount} ELSE 0 END), 0) AS cash_cents"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_source = 'cashless' THEN {$expenseAmount} ELSE 0 END), 0) AS cashless_cents"),
                DB::raw('COUNT(*) AS expense_count'),
            ]);

        $corrections = $this->corrections($branch, $session);
        $opening = [
            'cash' => ExactMoney::cents($session->opening_cash_amount),
            'cashless' => ExactMoney::cents($session->opening_cashless_amount),
        ];
        $expenseCents = ['cash' => (int) $expenses?->cash_cents, 'cashless' => (int) $expenses?->cashless_cents];
        $expected = [
            'cash' => $opening['cash'] + $sales['cash'] - $expenseCents['cash'] - $corrections['cash'] - $voids['cash'],
            'cashless' => $opening['cashless'] + $sales['cashless'] - $expenseCents['cashless'] - $corrections['cashless'] - $voids['cashless'],
        ];

        return [
            'opening' => $opening,
            'sales' => [...$sales, 'total' => ExactMoney::add($sales['cash'], $sales['cashless']), 'count' => $paymentCount],
            'split' => ['cash' => (int) $split?->cash_cents, 'cashless' => (int) $split?->cashless_cents, 'count' => (int) $split?->split_count],
            'expenses' => [...$expenseCents, 'count' => (int) $expenses?->expense_count],
            'corrections' => $corrections,
            'voids' => [...$voids, 'count' => $voidedOrders],
            'expected' => $expected,
        ];
    }

    /**
     * @param  ReconciliationCents  $cents
     * @return array<string, array<string, int|string>>
     */
    public function present(array $cents): array
    {
        $presented = [];
        foreach ($cents as $section => $values) {
            foreach ($values as $key => $value) {
                $presented[$section][$key] = $key === 'count' ? $value : ExactMoney::signedDecimal($value);
            }
        }

        return $presented;
    }

    /**
     * variance = actual − expected; negative is a shortage and positive an overage.
     *
     * @param  array{cash: int, cashless: int}  $expected
     * @param  array{cash: int, cashless: int}  $actual
     * @return array{cash: int, cashless: int}
     */
    public function variances(array $expected, array $actual): array
    {
        return [
            'cash' => $actual['cash'] - $expected['cash'],
            'cashless' => $actual['cashless'] - $expected['cashless'],
        ];
    }

    /** @return array{cash: int, cashless: int, count: int} */
    private function corrections(Branch $branch, StoreSession $session): array
    {
        $cash = $this->cents('order_adjustments.cash_amount');
        $cashless = $this->cents('order_adjustments.cashless_amount');
        $amount = $this->cents('order_adjustments.amount');
        $methodCount = '(SELECT COUNT(DISTINCT p.method) FROM payments p WHERE p.order_id = order_adjustments.order_id)';
        $soleMethod = '(SELECT MIN(p.method) FROM payments p WHERE p.order_id = order_adjustments.order_id)';
        $totals = ['cash' => 0, 'cashless' => 0, 'count' => 0];

        $this->currentCorrections($branch, $session)
            ->get([
                DB::raw("CASE WHEN order_adjustments.cash_amount IS NULL THEN NULL ELSE {$cash} END AS cash_cents"),
                DB::raw("CASE WHEN order_adjustments.cashless_amount IS NULL THEN NULL ELSE {$cashless} END AS cashless_cents"),
                DB::raw("{$amount} AS amount_cents"),
                DB::raw("{$methodCount} AS method_count"),
                DB::raw("{$soleMethod} AS sole_method"),
            ])
            ->each(function (object $row) use (&$totals): void {
                $totals['count']++;
                if ($row->cash_cents !== null && $row->cashless_cents !== null) {
                    $totals['cash'] = ExactMoney::add($totals['cash'], (int) $row->cash_cents);
                    $totals['cashless'] = ExactMoney::add($totals['cashless'], (int) $row->cashless_cents);

                    return;
                }
                if ((int) $row->method_count !== 1) {
                    throw new \LogicException('A payment correction requires a Cash/Cashless allocation before reconciliation.');
                }
                if ($row->sole_method === 'cash') {
                    $totals['cash'] = ExactMoney::add($totals['cash'], (int) $row->amount_cents);
                } else {
                    $totals['cashless'] = ExactMoney::add($totals['cashless'], (int) $row->amount_cents);
                }
            });

        return $totals;
    }

    /** @return list<array<string, mixed>> */
    private function ambiguousCorrections(Branch $branch, StoreSession $session): array
    {
        $rows = $this->currentCorrections($branch, $session)
            ->whereNull('order_adjustments.cash_amount')
            ->whereRaw('(SELECT COUNT(DISTINCT p.method) FROM payments p WHERE p.order_id = order_adjustments.order_id) <> 1')
            ->orderBy('order_adjustments.created_at')
            ->orderBy('order_adjustments.id')
            ->get(['order_adjustments.id', 'order_adjustments.order_id']);
        if ($rows->isEmpty()) {
            return [];
        }
        $orders = Order::query()->whereKey($rows->pluck('order_id')->unique())->with('payments', 'adjustments')->get()->keyBy('id');

        return array_values($rows->map(function (object $row) use ($orders): array {
            /** @var Order $order */
            $order = $orders->get($row->order_id);
            $adjustment = $order->adjustments->firstWhere('id', $row->id);
            $refund = ExactMoney::cents($adjustment->amount);
            $available = $this->allocation->available($order, $adjustment->id);
            $bounds = $this->allocation->bounds($available, $refund);

            return [
                'id' => (string) $adjustment->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_label' => $order->customer_label,
                'amount' => ExactMoney::decimal($refund),
                'cash_available' => ExactMoney::decimal($available['cash']),
                'cashless_available' => ExactMoney::decimal($available['cashless']),
                'min_cash' => ExactMoney::decimal($bounds['min_cash']),
                'max_cash' => ExactMoney::decimal($bounds['max_cash']),
                'created_at' => $adjustment->created_at?->toIso8601String(),
            ];
        })->all());
    }

    private function currentCorrections(Branch $branch, StoreSession $session): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_adjustments')
            ->join('orders', 'orders.id', '=', 'order_adjustments.order_id')
            ->where('order_adjustments.branch_id', $branch->id)
            ->where('order_adjustments.store_session_id', $session->id)
            ->where('orders.commercial_status', '<>', CommercialStatus::Voided->value);
    }

    /**
     * Exact integer cents from numeric(14,2) on PostgreSQL and SQLite alike.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function cents(string $column): string
    {
        return "CAST(ROUND({$column} * 100) AS BIGINT)";
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $column
     * @return literal-string
     */
    private function centsSubquery(string $table, string $column): string
    {
        return "(SELECT COALESCE(SUM(CAST(ROUND(t.{$column} * 100) AS BIGINT)), 0) FROM {$table} t WHERE t.order_id = orders.id)";
    }
}
