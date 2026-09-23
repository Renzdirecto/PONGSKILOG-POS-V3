<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StoreSession;
use Illuminate\Support\Facades\DB;

/**
 * Read-only operational projection for the Cashier Dashboard: the active Branch and its current OPEN Store Session.
 *
 * Sales come from actual Payment rows of non-voided Orders in the current Store Session, net of lower-total
 * corrections on those Orders. Split payments are already stored as separate Cash and Cashless Payment rows,
 * so the Split breakdown is explanatory only and is never added to Sales again.
 *
 * @phpstan-type SessionSummary array{
 *     orders: int,
 *     sales: string,
 *     cash: string,
 *     cashless: string,
 *     corrections: string,
 *     split: array{count: int, cash: string, cashless: string}
 * }
 */
class CashierDashboard
{
    public function __construct(private KitchenBoard $kitchenBoard, private InventoryState $inventoryState) {}

    /**
     * @return array{
     *     store: array{is_open: bool, session: array{id: string, opened_at: string, opened_by: string}|null},
     *     summary: SessionSummary|null,
     *     kitchen: array{kitchen: int, preparing: int, ready: int}|null,
     *     payments: array{pending: int, balance_due: string}|null,
     *     expenses: array{count: int, total: string}|null,
     *     inventory: array{low_stock: int, out_of_stock: int}
     * }
     */
    public function for(Branch $branch): array
    {
        $session = StoreSession::query()
            ->with('openedBy:id,name')
            ->whereBelongsTo($branch)
            ->where('status', StoreSessionStatus::Open)
            ->latest('opened_at')
            ->first();

        return [
            'store' => [
                'is_open' => $session !== null,
                'session' => $session === null ? null : [
                    'id' => (string) $session->getKey(),
                    'opened_at' => $session->opened_at->toIso8601String(),
                    'opened_by' => $session->openedBy->name,
                ],
            ],
            'summary' => $session === null ? null : $this->summary($branch, $session),
            'kitchen' => $session === null ? null : $this->kitchen($branch),
            'payments' => $session === null ? null : $this->outstanding($branch, $session),
            'expenses' => $session === null ? null : $this->expenses($branch, $session),
            'inventory' => $this->inventory($branch),
        ];
    }

    /** @return SessionSummary */
    private function summary(Branch $branch, StoreSession $session): array
    {
        $amount = $this->cents('payments.amount');
        $collected = ['cash' => 0, 'cashless' => 0];

        DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.branch_id', $branch->id)
            ->where('payments.store_session_id', $session->id)
            ->where('orders.commercial_status', '<>', CommercialStatus::Voided->value)
            ->groupBy('payments.method')
            ->get([DB::raw('payments.method AS method'), DB::raw("COALESCE(SUM({$amount}), 0) AS cents")])
            ->each(function (object $row) use (&$collected): void {
                $method = $row->method === 'cash' ? 'cash' : 'cashless';
                $collected[$method] = ExactMoney::add($collected[$method], (int) $row->cents);
            });

        $corrections = (int) DB::table('order_adjustments')
            ->join('orders', 'orders.id', '=', 'order_adjustments.order_id')
            ->where('order_adjustments.branch_id', $branch->id)
            ->where('order_adjustments.store_session_id', $session->id)
            ->where('orders.commercial_status', '<>', CommercialStatus::Voided->value)
            ->sum(DB::raw($this->cents('order_adjustments.amount')));

        $groupKey = "COALESCE(CAST(payments.payment_group_id AS VARCHAR(64)), REPLACE(REPLACE(payments.idempotency_key, ':cashless', ''), ':cash', ''))";
        $splitGroups = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.branch_id', $branch->id)
            ->where('payments.store_session_id', $session->id)
            ->where('orders.commercial_status', '<>', CommercialStatus::Voided->value)
            ->groupByRaw($groupKey)
            ->havingRaw('COUNT(DISTINCT payments.method) = 2')
            ->select([
                DB::raw("SUM(CASE WHEN payments.method = 'cash' THEN {$amount} ELSE 0 END) AS cash_cents"),
                DB::raw("SUM(CASE WHEN payments.method = 'cashless' THEN {$amount} ELSE 0 END) AS cashless_cents"),
            ]);
        $split = DB::query()->fromSub($splitGroups, 'split_groups')
            ->first([DB::raw('COUNT(*) AS split_count'), DB::raw('COALESCE(SUM(cash_cents), 0) AS cash_cents'), DB::raw('COALESCE(SUM(cashless_cents), 0) AS cashless_cents')]);

        $orders = DB::table('orders')
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id)
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value])
            ->count();

        $gross = ExactMoney::add($collected['cash'], $collected['cashless']);

        return [
            'orders' => $orders,
            'sales' => ExactMoney::decimal(max(0, $gross - $corrections)),
            'cash' => ExactMoney::decimal($collected['cash']),
            'cashless' => ExactMoney::decimal($collected['cashless']),
            'corrections' => ExactMoney::decimal($corrections),
            'split' => [
                'count' => (int) $split?->split_count,
                'cash' => ExactMoney::decimal((int) $split?->cash_cents),
                'cashless' => ExactMoney::decimal((int) $split?->cashless_cents),
            ],
        ];
    }

    /** @return array{kitchen: int, preparing: int, ready: int} */
    private function kitchen(Branch $branch): array
    {
        $counts = $this->kitchenBoard->counts($branch);

        return [
            'kitchen' => $counts['kitchen'],
            'preparing' => $counts['preparing'],
            'ready' => $counts['ready'],
        ];
    }

    /** @return array{pending: int, balance_due: string} */
    private function outstanding(Branch $branch, StoreSession $session): array
    {
        $total = $this->cents('orders.total');
        $payments = $this->centsSubquery('payments', 'amount');
        $adjustments = $this->centsSubquery('order_adjustments', 'amount');
        $settled = "CASE WHEN {$payments} > {$adjustments} THEN {$payments} - {$adjustments} ELSE 0 END";
        $outstanding = "{$total} - {$settled}";

        $row = DB::table('orders')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.store_session_id', $session->id)
            ->whereNotNull('orders.committed_at')
            ->whereIn('orders.commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value])
            ->whereRaw("{$outstanding} > 0")
            ->first([DB::raw('COUNT(*) AS pending'), DB::raw("COALESCE(SUM({$outstanding}), 0) AS balance_cents")]);

        return [
            'pending' => (int) $row?->pending,
            'balance_due' => ExactMoney::decimal((int) $row?->balance_cents),
        ];
    }

    /** @return array{count: int, total: string} */
    private function expenses(Branch $branch, StoreSession $session): array
    {
        $row = DB::table('store_session_expenses')
            ->where('branch_id', $branch->id)
            ->where('store_session_id', $session->id)
            ->first([DB::raw('COUNT(*) AS expense_count'), DB::raw('COALESCE(SUM('.$this->cents('amount').'), 0) AS total_cents')]);

        return [
            'count' => (int) $row?->expense_count,
            'total' => ExactMoney::decimal((int) $row?->total_cents),
        ];
    }

    /** @return array{low_stock: int, out_of_stock: int} */
    private function inventory(Branch $branch): array
    {
        $count = function (string $status) use ($branch): int {
            $query = Product::query()->where('products.is_active', true);
            $this->inventoryState->filterProducts($query, $branch, $status);

            return $query->count('products.id');
        };

        return [
            'low_stock' => $count('low_stock'),
            'out_of_stock' => $count('out_of_stock'),
        ];
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
