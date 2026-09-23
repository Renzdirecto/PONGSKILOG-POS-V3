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
 * @phpstan-type CorrectionCents array{cash: int, cashless: int, unallocated: int, count: int}
 * @phpstan-type SessionFlows array{
 *     sales: array{cash: int, cashless: int, total: int, count: int},
 *     split: CountedCents,
 *     expenses: CountedCents,
 *     corrections: CorrectionCents,
 *     voids: CountedCents
 * }
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
        $flows = $this->flows([$session->id => $branch->id])[$session->id];
        if ($flows['corrections']['unallocated'] > 0) {
            throw new \LogicException('A payment correction requires a Cash/Cashless allocation before reconciliation.');
        }
        $opening = $this->opening($session);
        $corrections = [
            'cash' => $flows['corrections']['cash'],
            'cashless' => $flows['corrections']['cashless'],
            'count' => $flows['corrections']['count'],
        ];

        return [
            'opening' => $opening,
            'sales' => $flows['sales'],
            'split' => $flows['split'],
            'expenses' => $flows['expenses'],
            'corrections' => $corrections,
            'voids' => $flows['voids'],
            'expected' => $this->expected($opening, $flows),
        ];
    }

    /**
     * Payment, Split, Store Expense, correction and Void flows in integer cents for many Store Sessions at once, so
     * read-only reporting shares this authority without a per-session query loop. Every flow counts only rows whose
     * Branch is the Store Session's own Branch. Corrections keep any unallocated amount separate and never guess it.
     *
     * @param  array<string, string>  $sessionBranches  Store Session id => Branch id
     * @return array<string, SessionFlows>
     */
    public function flows(array $sessionBranches): array
    {
        if ($sessionBranches === []) {
            return [];
        }
        $sessionIds = array_keys($sessionBranches);
        $branchIds = array_values(array_unique($sessionBranches));
        $owns = fn (\stdClass $row): bool => ($sessionBranches[(string) $row->store_session_id] ?? null) === (string) $row->branch_id;
        $noSales = ['cash' => 0, 'cashless' => 0, 'total' => 0, 'count' => 0];
        $none = ['cash' => 0, 'cashless' => 0, 'count' => 0];
        $sales = [];
        $voids = [];
        $split = [];
        $expenses = [];

        $amount = $this->cents('payments.amount');
        $voided = "CASE WHEN orders.commercial_status = 'voided' THEN 1 ELSE 0 END";
        $payments = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('payments.branch_id', $branchIds)
            ->whereIn('payments.store_session_id', $sessionIds)
            ->groupBy('payments.store_session_id', 'payments.branch_id', 'payments.method')
            ->groupByRaw($voided)
            ->get([
                DB::raw('payments.store_session_id AS store_session_id'), DB::raw('payments.branch_id AS branch_id'),
                DB::raw('payments.method AS method'), DB::raw("{$voided} AS voided"),
                DB::raw("COALESCE(SUM({$amount}), 0) AS cents"), DB::raw('COUNT(*) AS payment_count'),
            ])
            ->filter($owns);
        foreach ($payments as $row) {
            $id = (string) $row->store_session_id;
            $channel = $row->method === 'cash' ? 'cash' : 'cashless';
            $cents = (int) $row->cents;
            $session = $sales[$id] ?? $noSales;
            $session[$channel] = ExactMoney::add($session[$channel], $cents);
            $session['total'] = ExactMoney::add($session['total'], $cents);
            $session['count'] += (int) $row->payment_count;
            $sales[$id] = $session;
            if ((int) $row->voided === 1) {
                $reversal = $voids[$id] ?? $none;
                $reversal[$channel] = ExactMoney::add($reversal[$channel], $cents);
                $voids[$id] = $reversal;
            }
        }
        $voidedOrders = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('payments.branch_id', $branchIds)
            ->whereIn('payments.store_session_id', $sessionIds)
            ->where('orders.commercial_status', CommercialStatus::Voided->value)
            ->groupBy('payments.store_session_id', 'payments.branch_id')
            ->get([
                DB::raw('payments.store_session_id AS store_session_id'), DB::raw('payments.branch_id AS branch_id'),
                DB::raw('COUNT(DISTINCT payments.order_id) AS voided_orders'),
            ])
            ->filter($owns);
        foreach ($voidedOrders as $row) {
            $reversal = $voids[(string) $row->store_session_id] ?? $none;
            $reversal['count'] = (int) $row->voided_orders;
            $voids[(string) $row->store_session_id] = $reversal;
        }

        $groupKey = "COALESCE(CAST(payments.payment_group_id AS VARCHAR(64)), REPLACE(REPLACE(payments.idempotency_key, ':cashless', ''), ':cash', ''))";
        $splitGroups = DB::table('payments')
            ->whereIn('payments.branch_id', $branchIds)
            ->whereIn('payments.store_session_id', $sessionIds)
            ->groupBy('payments.store_session_id', 'payments.branch_id')
            ->groupByRaw($groupKey)
            ->havingRaw('COUNT(DISTINCT payments.method) = 2')
            ->select([
                DB::raw('payments.store_session_id AS store_session_id'), DB::raw('payments.branch_id AS branch_id'),
                DB::raw("SUM(CASE WHEN payments.method = 'cash' THEN {$amount} ELSE 0 END) AS cash_cents"),
                DB::raw("SUM(CASE WHEN payments.method = 'cashless' THEN {$amount} ELSE 0 END) AS cashless_cents"),
            ]);
        $splitTotals = DB::query()->fromSub($splitGroups, 'split_groups')
            ->groupBy('store_session_id', 'branch_id')
            ->get([
                'store_session_id', 'branch_id', DB::raw('COUNT(*) AS split_count'),
                DB::raw('COALESCE(SUM(cash_cents), 0) AS cash_cents'), DB::raw('COALESCE(SUM(cashless_cents), 0) AS cashless_cents'),
            ])
            ->filter($owns);
        foreach ($splitTotals as $row) {
            $split[(string) $row->store_session_id] = [
                'cash' => (int) $row->cash_cents,
                'cashless' => (int) $row->cashless_cents,
                'count' => (int) $row->split_count,
            ];
        }

        $expenseAmount = $this->cents('amount');
        $expenseTotals = DB::table('store_session_expenses')
            ->whereIn('branch_id', $branchIds)
            ->whereIn('store_session_id', $sessionIds)
            ->groupBy('store_session_id', 'branch_id')
            ->get([
                'store_session_id', 'branch_id',
                DB::raw("COALESCE(SUM(CASE WHEN payment_source = 'cash' THEN {$expenseAmount} ELSE 0 END), 0) AS cash_cents"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_source = 'cashless' THEN {$expenseAmount} ELSE 0 END), 0) AS cashless_cents"),
                DB::raw('COUNT(*) AS expense_count'),
            ])
            ->filter($owns);
        foreach ($expenseTotals as $row) {
            $expenses[(string) $row->store_session_id] = [
                'cash' => (int) $row->cash_cents,
                'cashless' => (int) $row->cashless_cents,
                'count' => (int) $row->expense_count,
            ];
        }

        $corrections = $this->correctionChannelsFor($sessionBranches);
        $flows = [];
        foreach ($sessionIds as $sessionId) {
            $flows[$sessionId] = [
                'sales' => $sales[$sessionId] ?? $noSales,
                'split' => $split[$sessionId] ?? $none,
                'expenses' => $expenses[$sessionId] ?? $none,
                'corrections' => $corrections[$sessionId] ?? ['cash' => 0, 'cashless' => 0, 'unallocated' => 0, 'count' => 0],
                'voids' => $voids[$sessionId] ?? $none,
            ];
        }

        return $flows;
    }

    /** @return MoneyCents */
    public function opening(StoreSession $session): array
    {
        return [
            'cash' => ExactMoney::cents($session->opening_cash_amount),
            'cashless' => ExactMoney::cents($session->opening_cashless_amount),
        ];
    }

    /**
     * Expected = Opening + Payment rows − Store Expenses − allocated corrections − payments of voided Orders, per channel.
     * The result keeps exact signed math and may be negative.
     *
     * @param  MoneyCents  $opening
     * @param  SessionFlows  $flows
     * @return MoneyCents
     */
    public function expected(array $opening, array $flows): array
    {
        return [
            'cash' => $opening['cash'] + $flows['sales']['cash'] - $flows['expenses']['cash'] - $flows['corrections']['cash'] - $flows['voids']['cash'],
            'cashless' => $opening['cashless'] + $flows['sales']['cashless'] - $flows['expenses']['cashless'] - $flows['corrections']['cashless'] - $flows['voids']['cashless'],
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

    /**
     * Corrections on non-voided Orders attributed to the channel that funded them. A mixed-method correction without
     * an allocation is never guessed: it is reported as unallocated so read-only views stay truthful before Close Store.
     *
     * @return CorrectionCents
     */
    public function correctionChannels(Branch $branch, StoreSession $session): array
    {
        return $this->correctionChannelsFor([$session->id => $branch->id])[$session->id];
    }

    /**
     * @param  array<string, string>  $sessionBranches  Store Session id => Branch id
     * @return array<string, CorrectionCents>
     */
    private function correctionChannelsFor(array $sessionBranches): array
    {
        $cash = $this->cents('order_adjustments.cash_amount');
        $cashless = $this->cents('order_adjustments.cashless_amount');
        $amount = $this->cents('order_adjustments.amount');
        $methodCount = '(SELECT COUNT(DISTINCT p.method) FROM payments p WHERE p.order_id = order_adjustments.order_id)';
        $soleMethod = '(SELECT MIN(p.method) FROM payments p WHERE p.order_id = order_adjustments.order_id)';
        $totals = array_map(fn (): array => ['cash' => 0, 'cashless' => 0, 'unallocated' => 0, 'count' => 0], $sessionBranches);

        $this->currentCorrections($sessionBranches)
            ->get([
                DB::raw('order_adjustments.store_session_id AS store_session_id'),
                DB::raw('order_adjustments.branch_id AS branch_id'),
                DB::raw("CASE WHEN order_adjustments.cash_amount IS NULL THEN NULL ELSE {$cash} END AS cash_cents"),
                DB::raw("CASE WHEN order_adjustments.cashless_amount IS NULL THEN NULL ELSE {$cashless} END AS cashless_cents"),
                DB::raw("{$amount} AS amount_cents"),
                DB::raw("{$methodCount} AS method_count"),
                DB::raw("{$soleMethod} AS sole_method"),
            ])
            ->filter(fn (object $row): bool => ($sessionBranches[(string) $row->store_session_id] ?? null) === (string) $row->branch_id)
            ->each(function (object $row) use (&$totals): void {
                $session = &$totals[(string) $row->store_session_id];
                $session['count']++;
                if ($row->cash_cents !== null && $row->cashless_cents !== null) {
                    $session['cash'] = ExactMoney::add($session['cash'], (int) $row->cash_cents);
                    $session['cashless'] = ExactMoney::add($session['cashless'], (int) $row->cashless_cents);

                    return;
                }
                if ((int) $row->method_count !== 1) {
                    $session['unallocated'] = ExactMoney::add($session['unallocated'], (int) $row->amount_cents);
                } elseif ($row->sole_method === 'cash') {
                    $session['cash'] = ExactMoney::add($session['cash'], (int) $row->amount_cents);
                } else {
                    $session['cashless'] = ExactMoney::add($session['cashless'], (int) $row->amount_cents);
                }
            });

        return $totals;
    }

    /** @return list<array<string, mixed>> */
    private function ambiguousCorrections(Branch $branch, StoreSession $session): array
    {
        $rows = $this->currentCorrections([$session->id => $branch->id])
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

    /** @param array<string, string> $sessionBranches Store Session id => Branch id */
    private function currentCorrections(array $sessionBranches): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_adjustments')
            ->join('orders', 'orders.id', '=', 'order_adjustments.order_id')
            ->whereIn('order_adjustments.branch_id', array_values(array_unique($sessionBranches)))
            ->whereIn('order_adjustments.store_session_id', array_keys($sessionBranches))
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
