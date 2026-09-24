<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\IngredientMovementType;
use App\Enums\ModifierSemanticRole;
use App\Enums\RecipeState;
use App\Models\Branch;
use App\Models\Recipe;
use App\Models\StoreSession;
use Illuminate\Support\Facades\DB;

/**
 * Today's Operations sales, estimated COGS, pamamalengke and profit for one Branch or All Branches, server-side.
 *
 * "Today" is the Phase 16 business date: the Store Sessions that opened on today's Asia/Manila date. Business Net
 * Sales and Store expenses come from StoreSessionSalesReport (the Reports authority), so Operations never re-derives
 * them. Plan figures use each Order line's historical recipe snapshot (Plan, recipe state) and the cost snapshotted on
 * its Ingredient movements, so moving a Product or editing a recipe or cost never rewrites past figures.
 *
 * - Estimated COGS counts only recipe-backed sales; missing-recipe and direct-resale sales are reported as uncosted
 *   (there is no trustworthy direct Product cost), never as ₱0 cost.
 * - Store-wide expenses are never allocated to a Plan; only the business summary subtracts them, once.
 * - Cash after purchases = Sales − Pamamalengke − other Store expenses. It is not profit.
 *
 * @phpstan-type ProductFigures array{product_id: string, name: string, quantity: int, sales_cents: int, state: string}
 * @phpstan-type Figures array{sales_cents: int, orders: int, items: int, uncosted_sales_cents: int, cogs_cents: int, unknown_cost_lines: int, gross_profit_cents: int, pamamalengke_cents: int, non_stock_cents: int, other_expenses_cents: int, cash_after_cents: int, operating_profit_cents: int, incomplete: bool, products: list<ProductFigures>}
 */
class OperationsSummary
{
    public function __construct(private StoreSessionSalesReport $report) {}

    /** @return array{plans: array<string, Figures>, business: Figures, outside_plan_sales_cents: int, business_date: string} */
    public function today(?Branch $branch): array
    {
        $period = ReportPeriod::fromFilters(['date' => 'today']);
        $sessions = $this->report->sessions($branch, $period);
        $rows = $this->report->figures($sessions);
        $netSales = array_sum(array_column($rows, 'net_sales'));
        $expenses = array_sum(array_map(fn (array $row): int => $row['flows']['expenses']['cash'] + $row['flows']['expenses']['cashless'], $rows));
        $sessionIds = $sessions->map(fn (StoreSession $session): string => $session->id)->values()->all();

        $orderIds = $sessionIds === [] ? [] : DB::table('orders')
            ->whereIn('store_session_id', $sessionIds)
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value])
            ->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();

        $plans = [];
        $business = $this->empty();
        $costedSales = 0;
        $outsideSales = 0;
        foreach ($this->lines($orderIds) as $line) {
            $plan = $line['plan_id'];
            $costed = $line['state'] === RecipeState::Recipe->value;
            if ($costed) {
                $costedSales += $line['cents'];
            }
            $business = $this->addProduct($business, $line);
            if ($plan === null) {
                $outsideSales += $line['cents'];

                continue;
            }
            $figures = $plans[$plan] ?? $this->empty();
            $figures['sales_cents'] += $line['cents'];
            $figures['items'] += $line['quantity'];
            $figures['uncosted_sales_cents'] += $costed ? 0 : $line['cents'];
            $figures['order_ids'][$line['order_id']] = true;
            $plans[$plan] = $this->addProduct($figures, $line);
        }

        foreach ($this->costs($orderIds) as $row) {
            $key = $row['plan_id'];
            $business['cogs_cents'] += $row['cents'];
            $business['unknown_cost_lines'] += $row['unknown'];
            if ($key !== null) {
                $plans[$key] ??= $this->empty();
                $plans[$key]['cogs_cents'] += $row['cents'];
                $plans[$key]['unknown_cost_lines'] += $row['unknown'];
            }
        }

        $purchases = $this->purchases($sessionIds);
        foreach ($purchases as $planId => $purchase) {
            $business['pamamalengke_cents'] += $purchase['total'];
            $business['non_stock_cents'] += $purchase['manual'];
            if ($planId !== '') {
                $plans[$planId] ??= $this->empty();
                $plans[$planId]['pamamalengke_cents'] += $purchase['total'];
                $plans[$planId]['non_stock_cents'] += $purchase['manual'];
            }
        }

        $business['sales_cents'] = $netSales;
        $business['orders'] = count($orderIds);
        $business['uncosted_sales_cents'] = max(0, $netSales - $costedSales);
        $business['other_expenses_cents'] = max(0, $expenses - $business['pamamalengke_cents']);

        return [
            'plans' => array_map(fn (array $figures): array => $this->finish($figures), $plans),
            'business' => $this->finish($business, countOrders: false),
            'outside_plan_sales_cents' => $outsideSales,
            'business_date' => $period->from->toDateString(),
        ];
    }

    /**
     * Every eligible Order line with its historical Plan and recipe state. Lines committed before Operations existed
     * have no snapshot and are reported outside every Plan and uncosted.
     *
     * @param  array<int, string>  $orderIds
     * @return array<int, array{order_id: string, product_id: string, name: string, quantity: int, cents: int, plan_id: string|null, state: string}>
     */
    private function lines(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $items = DB::table('order_items')->whereIn('order_id', $orderIds)->whereNotNull('product_id')
            ->get(['id', 'order_id', 'product_id', 'product_name_snapshot', 'quantity', DB::raw('CAST(ROUND(line_total * 100) AS BIGINT) AS cents')]);
        $sizes = DB::table('order_item_modifiers')->whereIn('order_item_id', $items->pluck('id'))
            ->where('semantic_role_snapshot', ModifierSemanticRole::Size->value)->whereNotNull('modifier_option_id')
            ->pluck('modifier_option_id', 'order_item_id');
        $snapshots = DB::table('order_recipe_snapshots')->whereIn('order_id', $orderIds)
            ->get(['order_id', 'product_id', 'size_key', 'recipe_state', 'operation_plan_id'])
            ->keyBy(fn (object $row): string => $row->order_id.'|'.$row->product_id.'|'.$row->size_key);

        return $items->map(function (object $item) use ($sizes, $snapshots): array {
            $sizeKey = Recipe::sizeKey(isset($sizes[$item->id]) ? (string) $sizes[$item->id] : null);
            $snapshot = $snapshots->get($item->order_id.'|'.$item->product_id.'|'.$sizeKey);

            return [
                'order_id' => (string) $item->order_id,
                'product_id' => (string) $item->product_id,
                'name' => (string) $item->product_name_snapshot,
                'quantity' => (int) $item->quantity,
                'cents' => (int) $item->cents,
                'plan_id' => $snapshot?->operation_plan_id === null ? null : (string) $snapshot->operation_plan_id,
                'state' => $snapshot === null ? RecipeState::Missing->value : (string) $snapshot->recipe_state,
            ];
        })->values()->all();
    }

    /**
     * Net snapshotted consumption cost per historical Plan (sale, edit and void movements of the eligible Orders).
     *
     * @param  array<int, string>  $orderIds
     * @return array<int, array{plan_id: string|null, cents: int, unknown: int}>
     */
    private function costs(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('ingredient_movements')
            ->whereIn('order_id', $orderIds)
            ->whereIn('movement_type', [
                IngredientMovementType::SaleConsumption->value,
                IngredientMovementType::OrderEditAdjustment->value,
                IngredientMovementType::VoidRestoration->value,
            ])
            ->groupBy('operation_plan_id')
            ->get([
                'operation_plan_id',
                DB::raw('COALESCE(SUM(estimated_cost_cents), 0) AS cents'),
                DB::raw('SUM(CASE WHEN estimated_cost_cents IS NULL THEN 1 ELSE 0 END) AS unknown'),
            ])
            ->map(fn (object $row): array => [
                'plan_id' => $row->operation_plan_id === null ? null : (string) $row->operation_plan_id,
                'cents' => (int) $row->cents,
                'unknown' => (int) $row->unknown,
            ])->all();
    }

    /**
     * Confirmed pamamalengke totals per Plan ('' for runs without a Plan) from the canonical expense amounts.
     *
     * @param  array<int, string>  $sessionIds
     * @return array<string, array{total: int, manual: int}>
     */
    private function purchases(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $totals = [];
        DB::table('pamamalengke_purchases')
            ->join('store_session_expenses', 'store_session_expenses.id', '=', 'pamamalengke_purchases.store_session_expense_id')
            ->whereIn('pamamalengke_purchases.store_session_id', $sessionIds)
            ->groupBy('pamamalengke_purchases.operation_plan_id')
            ->get([
                'pamamalengke_purchases.operation_plan_id',
                DB::raw('CAST(ROUND(SUM(store_session_expenses.amount) * 100) AS BIGINT) AS cents'),
            ])
            ->each(function (object $row) use (&$totals): void {
                $totals[(string) ($row->operation_plan_id ?? '')] = ['total' => (int) $row->cents, 'manual' => 0];
            });
        DB::table('pamamalengke_purchase_items')
            ->join('pamamalengke_purchases', 'pamamalengke_purchases.id', '=', 'pamamalengke_purchase_items.pamamalengke_purchase_id')
            ->whereIn('pamamalengke_purchases.store_session_id', $sessionIds)
            ->where('pamamalengke_purchase_items.line_type', 'manual')
            ->groupBy('pamamalengke_purchases.operation_plan_id')
            ->get([
                'pamamalengke_purchases.operation_plan_id',
                DB::raw('CAST(ROUND(SUM(pamamalengke_purchase_items.line_total) * 100) AS BIGINT) AS cents'),
            ])
            ->each(function (object $row) use (&$totals): void {
                $key = (string) ($row->operation_plan_id ?? '');
                $totals[$key] ??= ['total' => 0, 'manual' => 0];
                $totals[$key]['manual'] = (int) $row->cents;
            });

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $figures
     * @param  array{order_id: string, product_id: string, name: string, quantity: int, cents: int, plan_id: string|null, state: string}  $line
     * @return array<string, mixed>
     */
    private function addProduct(array $figures, array $line): array
    {
        $product = $figures['by_product'][$line['product_id']] ?? ['product_id' => $line['product_id'], 'name' => $line['name'], 'quantity' => 0, 'sales_cents' => 0, 'state' => $line['state']];
        $product['quantity'] += $line['quantity'];
        $product['sales_cents'] += $line['cents'];
        $figures['by_product'][$line['product_id']] = $product;

        return $figures;
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'sales_cents' => 0, 'orders' => 0, 'items' => 0, 'uncosted_sales_cents' => 0, 'cogs_cents' => 0,
            'unknown_cost_lines' => 0, 'pamamalengke_cents' => 0, 'non_stock_cents' => 0, 'other_expenses_cents' => 0,
            'order_ids' => [], 'by_product' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return Figures
     */
    private function finish(array $figures, bool $countOrders = true): array
    {
        $products = array_values($figures['by_product']);
        usort($products, fn (array $left, array $right): int => [$right['sales_cents'], $left['name']] <=> [$left['sales_cents'], $right['name']]);
        $gross = $figures['sales_cents'] - $figures['cogs_cents'];

        return [
            'sales_cents' => $figures['sales_cents'],
            'orders' => $countOrders ? count($figures['order_ids']) : $figures['orders'],
            'items' => $countOrders ? $figures['items'] : array_sum(array_column($products, 'quantity')),
            'uncosted_sales_cents' => $figures['uncosted_sales_cents'],
            'cogs_cents' => $figures['cogs_cents'],
            'unknown_cost_lines' => $figures['unknown_cost_lines'],
            'gross_profit_cents' => $gross,
            'pamamalengke_cents' => $figures['pamamalengke_cents'],
            'non_stock_cents' => $figures['non_stock_cents'],
            'other_expenses_cents' => $figures['other_expenses_cents'],
            'cash_after_cents' => $figures['sales_cents'] - $figures['pamamalengke_cents'] - $figures['other_expenses_cents'],
            'operating_profit_cents' => $gross - $figures['non_stock_cents'] - $figures['other_expenses_cents'],
            'incomplete' => $figures['uncosted_sales_cents'] > 0 || $figures['unknown_cost_lines'] > 0,
            'products' => $products,
        ];
    }
}
