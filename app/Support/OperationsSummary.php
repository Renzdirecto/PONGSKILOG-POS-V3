<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\IngredientMovementType;
use App\Enums\ModifierSemanticRole;
use App\Enums\RecipeState;
use App\Models\Branch;
use App\Models\Recipe;
use App\Models\StoreSession;
use Illuminate\Database\Query\Builder;
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
 * - Pamamalengke runs belong to their funding Store Session (Phase 20), whether it was the open session (a Store
 *   Purchase, already inside the session's expenses) or a closed one (an allocation with no expense row). Other
 *   expenses therefore exclude only the expense-backed runs, so no run is ever subtracted twice.
 * - Giveaways (free items recorded in the Store Session) are non-revenue stock-outs: never Net Sales, orders, COGS or
 *   expenses. Their estimated Ingredient cost is reported separately and is incomplete when any line has no cost.
 *
 * - Products Sold groups the lines by Catalog category, then Product and size. Names and sizes are the Order lines'
 *   own snapshots (never today's modifier definitions); the category is the Product's current Catalog category, as
 *   Order lines carry no category snapshot.
 *
 * @phpstan-type SoldLine array{product_id: string, name: string, size: string|null, quantity: int, sales_cents: int}
 * @phpstan-type SoldCategory array{category_id: string, name: string, quantity: int, sales_cents: int, sizes: list<array{name: string, quantity: int}>, lines: list<SoldLine>}
 * @phpstan-type GiveawayFigures array{count: int, items: int, cost_cents: int, uncosted: int}
 * @phpstan-type Figures array{sales_cents: int, orders: int, items: int, uncosted_sales_cents: int, cogs_cents: int, unknown_cost_lines: int, gross_profit_cents: int, pamamalengke_cents: int, non_stock_cents: int, other_expenses_cents: int, operating_profit_cents: int, incomplete: bool, products_sold: list<SoldCategory>}
 * @phpstan-type Line array{order_id: string, product_id: string, name: string, size: string|null, category_id: string, category: string, category_sort: int, quantity: int, cents: int, plan_id: string|null, state: string}
 */
class OperationsSummary
{
    public function __construct(private StoreSessionSalesReport $report) {}

    /** @return array{plans: array<string, Figures>, business: Figures, outside_plan_sales_cents: int, giveaways: GiveawayFigures, business_date: string} */
    public function today(?Branch $branch): array
    {
        $period = ReportPeriod::fromFilters(['date' => 'today']);
        $sessions = $this->report->sessions($branch, $period);
        $rows = $this->report->figures($sessions);
        $netSales = array_sum(array_column($rows, 'net_sales'));
        $expenses = array_sum(array_map(fn (array $row): int => $row['flows']['expenses']['cash'] + $row['flows']['expenses']['cashless'], $rows));
        $sessionIds = $sessions->map(fn (StoreSession $session): string => $session->id)->values()->all();

        /** A subquery, never a PHP id list, so a busy day or All Branches cannot outgrow the bind-parameter limit. */
        $orderIds = $sessionIds === [] ? null : DB::table('orders')
            ->select('id')
            ->whereIn('store_session_id', $sessionIds)
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value]);

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

        $expensedPurchases = 0;
        foreach ($this->purchases($sessionIds) as $planId => $purchase) {
            $business['pamamalengke_cents'] += $purchase['total'];
            $business['non_stock_cents'] += $purchase['manual'];
            $expensedPurchases += $purchase['expensed'];
            if ($planId !== '') {
                $plans[$planId] ??= $this->empty();
                $plans[$planId]['pamamalengke_cents'] += $purchase['total'];
                $plans[$planId]['non_stock_cents'] += $purchase['manual'];
            }
        }

        $business['sales_cents'] = $netSales;
        $business['orders'] = $orderIds === null ? 0 : (clone $orderIds)->count();
        $business['uncosted_sales_cents'] = max(0, $netSales - $costedSales);
        $business['other_expenses_cents'] = max(0, $expenses - $expensedPurchases);

        return [
            'plans' => array_map(fn (array $figures): array => $this->finish($figures), $plans),
            'business' => $this->finish($business, countOrders: false),
            'outside_plan_sales_cents' => $outsideSales,
            'giveaways' => $this->giveaways($sessionIds),
            'business_date' => $period->from->toDateString(),
        ];
    }

    /**
     * Today's Giveaways that were not reversed: count, items and their estimated Ingredient cost from the cost recorded
     * on their own movements. A direct-stock, untracked or unknown-cost Giveaway is counted as uncosted, never ₱0.
     *
     * @param  array<int, string>  $sessionIds
     * @return GiveawayFigures
     */
    private function giveaways(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return ['count' => 0, 'items' => 0, 'cost_cents' => 0, 'uncosted' => 0];
        }
        $active = fn () => DB::table('store_session_giveaways')
            ->whereIn('store_session_giveaways.store_session_id', $sessionIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('store_session_giveaway_reversals')
                ->whereColumn('store_session_giveaway_reversals.giveaway_id', 'store_session_giveaways.id'));
        $unknownCost = fn ($query) => $query->selectRaw('1')->from('ingredient_movements')
            ->whereColumn('ingredient_movements.store_session_giveaway_id', 'store_session_giveaways.id')
            ->where('ingredient_movements.movement_type', IngredientMovementType::Giveaway->value)
            ->whereNull('ingredient_movements.estimated_cost_cents');
        $totals = $active()->selectRaw('COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS items')->first();

        return [
            'count' => (int) ($totals->count ?? 0),
            'items' => (int) ($totals->items ?? 0),
            'cost_cents' => (int) DB::table('ingredient_movements')
                ->whereIn('store_session_giveaway_id', $active()->select('store_session_giveaways.id'))
                ->where('movement_type', IngredientMovementType::Giveaway->value)
                ->sum('estimated_cost_cents'),
            'uncosted' => $active()->where(fn ($query) => $query->where('stock_mode', '!=', 'recipe')->orWhereExists($unknownCost))->count(),
        ];
    }

    /**
     * Every eligible Order line with its historical Plan and recipe state, its size snapshot and its Product's category.
     * Lines committed before Operations existed have no snapshot and are reported outside every Plan and uncosted.
     * Four queries whatever the number of lines.
     *
     * @return array<int, Line>
     */
    private function lines(?Builder $orderIds): array
    {
        if ($orderIds === null) {
            return [];
        }
        $items = DB::table('order_items')->whereIn('order_id', clone $orderIds)->whereNotNull('product_id')
            ->get(['id', 'order_id', 'product_id', 'product_name_snapshot', 'quantity', DB::raw('CAST(ROUND(line_total * 100) AS BIGINT) AS cents')]);
        $sizes = DB::table('order_item_modifiers')
            ->whereIn('order_item_id', DB::table('order_items')->select('id')->whereIn('order_id', clone $orderIds))
            ->where('semantic_role_snapshot', ModifierSemanticRole::Size->value)
            ->get(['order_item_id', 'modifier_option_id', 'option_name_snapshot'])
            ->keyBy('order_item_id');
        $categories = DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('products.id', DB::table('order_items')->select('product_id')->whereIn('order_id', clone $orderIds))
            ->get(['products.id', 'categories.id AS category_id', 'categories.name AS category_name', 'categories.sort_order'])
            ->keyBy('id');
        $snapshots = DB::table('order_recipe_snapshots')->whereIn('order_id', clone $orderIds)
            ->get(['order_id', 'product_id', 'size_key', 'recipe_state', 'operation_plan_id'])
            ->keyBy(fn (object $row): string => $row->order_id.'|'.$row->product_id.'|'.$row->size_key);

        return $items->map(function (object $item) use ($sizes, $snapshots, $categories): array {
            $size = $sizes->get($item->id);
            $sizeKey = Recipe::sizeKey($size?->modifier_option_id === null ? null : (string) $size->modifier_option_id);
            $snapshot = $snapshots->get($item->order_id.'|'.$item->product_id.'|'.$sizeKey);
            $category = $categories->get($item->product_id);

            return [
                'order_id' => (string) $item->order_id,
                'product_id' => (string) $item->product_id,
                'name' => (string) $item->product_name_snapshot,
                'size' => $size === null ? null : (string) $size->option_name_snapshot,
                'category_id' => $category === null ? '' : (string) $category->category_id,
                'category' => $category === null ? 'Uncategorized' : (string) $category->category_name,
                'category_sort' => $category === null ? PHP_INT_MAX : (int) $category->sort_order,
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
     * @return array<int, array{plan_id: string|null, cents: int, unknown: int}>
     */
    private function costs(?Builder $orderIds): array
    {
        if ($orderIds === null) {
            return [];
        }

        return DB::table('ingredient_movements')
            ->whereIn('order_id', clone $orderIds)
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
     * Confirmed pamamalengke totals per Plan ('' for runs without a Plan) of the runs funded by these Store Sessions:
     * the whole run, its non-stock (manual) part and the part already recorded as a Store Purchase of the session.
     * A run's amount is the sum of its item line totals, which equals its expense when one exists.
     *
     * @param  array<int, string>  $sessionIds
     * @return array<string, array{total: int, manual: int, expensed: int}>
     */
    private function purchases(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('pamamalengke_purchase_items')
            ->join('pamamalengke_purchases', 'pamamalengke_purchases.id', '=', 'pamamalengke_purchase_items.pamamalengke_purchase_id')
            ->whereIn('pamamalengke_purchases.store_session_id', $sessionIds)
            ->groupBy('pamamalengke_purchases.operation_plan_id')
            ->get([
                'pamamalengke_purchases.operation_plan_id',
                DB::raw('CAST(ROUND(SUM(pamamalengke_purchase_items.line_total) * 100) AS BIGINT) AS total'),
                DB::raw("CAST(ROUND(SUM(CASE WHEN pamamalengke_purchase_items.line_type = 'manual' THEN pamamalengke_purchase_items.line_total ELSE 0 END) * 100) AS BIGINT) AS manual"),
                DB::raw('CAST(ROUND(SUM(CASE WHEN pamamalengke_purchases.store_session_expense_id IS NULL THEN 0 ELSE pamamalengke_purchase_items.line_total END) * 100) AS BIGINT) AS expensed'),
            ])
            ->mapWithKeys(fn (object $row): array => [(string) ($row->operation_plan_id ?? '') => [
                'total' => (int) $row->total,
                'manual' => (int) $row->manual,
                'expensed' => (int) $row->expensed,
            ]])
            ->all();
    }

    /**
     * Adds a line to Products Sold: its category's total and size count, and its Product × size row.
     *
     * @param  array<string, mixed>  $figures
     * @param  Line  $line
     * @return array<string, mixed>
     */
    private function addProduct(array $figures, array $line): array
    {
        $category = $figures['sold'][$line['category_id']] ?? [
            'category_id' => $line['category_id'], 'name' => $line['category'], 'sort' => $line['category_sort'],
            'quantity' => 0, 'sales_cents' => 0, 'sizes' => [], 'lines' => [],
        ];
        $category['quantity'] += $line['quantity'];
        $category['sales_cents'] += $line['cents'];
        if ($line['size'] !== null) {
            $size = $category['sizes']['size:'.$line['size']] ?? ['name' => $line['size'], 'quantity' => 0];
            $size['quantity'] += $line['quantity'];
            $category['sizes']['size:'.$line['size']] = $size;
        }
        $key = $line['product_id'].'|'.($line['size'] ?? '');
        $row = $category['lines'][$key] ?? ['product_id' => $line['product_id'], 'name' => $line['name'], 'size' => $line['size'], 'quantity' => 0, 'sales_cents' => 0];
        $row['quantity'] += $line['quantity'];
        $row['sales_cents'] += $line['cents'];
        $category['lines'][$key] = $row;
        $figures['sold'][$line['category_id']] = $category;

        return $figures;
    }

    /**
     * Categories in Catalog order; sizes by name; rows by quantity sold, then name and size.
     *
     * @param  array<string, array<string, mixed>>  $sold
     * @return list<SoldCategory>
     */
    private function productsSold(array $sold): array
    {
        uasort($sold, fn (array $left, array $right): int => [$left['sort'], $left['name']] <=> [$right['sort'], $right['name']]);

        return array_values(array_map(function (array $category): array {
            $sizes = array_values($category['sizes']);
            usort($sizes, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
            $lines = array_values($category['lines']);
            usort($lines, fn (array $left, array $right): int => [$right['quantity'], $left['name'], (string) $left['size']] <=> [$left['quantity'], $right['name'], (string) $right['size']]);

            return [
                'category_id' => $category['category_id'],
                'name' => $category['name'],
                'quantity' => $category['quantity'],
                'sales_cents' => $category['sales_cents'],
                'sizes' => $sizes,
                'lines' => $lines,
            ];
        }, $sold));
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'sales_cents' => 0, 'orders' => 0, 'items' => 0, 'uncosted_sales_cents' => 0, 'cogs_cents' => 0,
            'unknown_cost_lines' => 0, 'pamamalengke_cents' => 0, 'non_stock_cents' => 0, 'other_expenses_cents' => 0,
            'order_ids' => [], 'sold' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return Figures
     */
    private function finish(array $figures, bool $countOrders = true): array
    {
        $sold = $this->productsSold($figures['sold']);
        $gross = $figures['sales_cents'] - $figures['cogs_cents'];

        return [
            'sales_cents' => $figures['sales_cents'],
            'orders' => $countOrders ? count($figures['order_ids']) : $figures['orders'],
            'items' => $countOrders ? $figures['items'] : array_sum(array_column($sold, 'quantity')),
            'uncosted_sales_cents' => $figures['uncosted_sales_cents'],
            'cogs_cents' => $figures['cogs_cents'],
            'unknown_cost_lines' => $figures['unknown_cost_lines'],
            'gross_profit_cents' => $gross,
            'pamamalengke_cents' => $figures['pamamalengke_cents'],
            'non_stock_cents' => $figures['non_stock_cents'],
            'other_expenses_cents' => $figures['other_expenses_cents'],
            'operating_profit_cents' => $gross - $figures['non_stock_cents'] - $figures['other_expenses_cents'],
            'incomplete' => $figures['uncosted_sales_cents'] > 0 || $figures['unknown_cost_lines'] > 0,
            'products_sold' => $sold,
        ];
    }
}
