<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class InventoryState
{
    /** @return array{tracked: bool, on_hand: int|null, low_stock_threshold: int|null, status: 'not_tracked'|'in_stock'|'low_stock'|'out_of_stock'} */
    public function resolve(?BranchProduct $configuration, ?BranchInventory $balance): array
    {
        $tracked = $configuration->tracks_inventory ?? false;
        $onHand = $tracked ? ($balance->on_hand ?? 0) : null;
        $threshold = $configuration?->low_stock_threshold;

        return [
            'tracked' => $tracked,
            'on_hand' => $onHand,
            'low_stock_threshold' => $threshold,
            'status' => match (true) {
                ! $tracked => 'not_tracked',
                $onHand <= 0 => 'out_of_stock',
                $threshold !== null && $onHand <= $threshold => 'low_stock',
                default => 'in_stock',
            },
        ];
    }

    /**
     * Out-of-stock and low-stock counts of active tracked Products for every Branch in one grouped query, with exactly
     * the rules of filterProducts(): tracked with no balance or on hand ≤ 0 is out of stock; on hand above 0 and at or
     * below a set threshold is low stock. Branches without tracked Products are absent (count them as 0).
     *
     * @param  list<string>  $branchIds
     * @return array<string, array{out_of_stock: int, low_stock: int}>
     */
    public function attentionCountsByBranch(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        return BranchProduct::query()
            ->join('products', 'products.id', '=', 'branch_products.product_id')
            ->leftJoin('branch_inventory as inventory_balance', function (JoinClause $join): void {
                $join->on('inventory_balance.product_id', '=', 'branch_products.product_id')
                    ->whereColumn('inventory_balance.branch_id', 'branch_products.branch_id');
            })
            ->whereIn('branch_products.branch_id', $branchIds)
            ->where('products.is_active', true)
            ->where('branch_products.tracks_inventory', true)
            ->groupBy('branch_products.branch_id')
            ->toBase()
            ->selectRaw('branch_products.branch_id AS branch_id')
            ->selectRaw('SUM(CASE WHEN inventory_balance.on_hand IS NULL OR inventory_balance.on_hand <= 0 THEN 1 ELSE 0 END) AS out_of_stock')
            ->selectRaw('SUM(CASE WHEN inventory_balance.on_hand > 0 AND branch_products.low_stock_threshold IS NOT NULL AND inventory_balance.on_hand <= branch_products.low_stock_threshold THEN 1 ELSE 0 END) AS low_stock')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->branch_id => [
                'out_of_stock' => (int) $row->out_of_stock,
                'low_stock' => (int) $row->low_stock,
            ]])
            ->all();
    }

    /** @param Builder<Product> $query */
    public function filterProducts(Builder $query, Branch $branch, string $status): void
    {
        $query->leftJoin('branch_products as inventory_configuration', function (JoinClause $join) use ($branch): void {
            $join->on('inventory_configuration.product_id', '=', 'products.id')
                ->where('inventory_configuration.branch_id', $branch->id);
        })->leftJoin('branch_inventory as inventory_balance', function (JoinClause $join) use ($branch): void {
            $join->on('inventory_balance.product_id', '=', 'products.id')
                ->where('inventory_balance.branch_id', $branch->id);
        });

        if ($status === 'not_tracked') {
            $query->where(fn ($query) => $query->whereNull('inventory_configuration.id')
                ->orWhere('inventory_configuration.tracks_inventory', false));

            return;
        }

        $query->where('inventory_configuration.tracks_inventory', true);

        if ($status === 'out_of_stock') {
            $query->where(fn ($query) => $query->whereNull('inventory_balance.on_hand')
                ->orWhere('inventory_balance.on_hand', '<=', 0));

            return;
        }

        $query->where('inventory_balance.on_hand', '>', 0);

        if ($status === 'low_stock') {
            $query->whereNotNull('inventory_configuration.low_stock_threshold')
                ->whereColumn('inventory_balance.on_hand', '<=', 'inventory_configuration.low_stock_threshold');

            return;
        }

        $query->where(fn ($query) => $query->whereNull('inventory_configuration.low_stock_threshold')
            ->orWhereColumn('inventory_balance.on_hand', '>', 'inventory_configuration.low_stock_threshold'));
    }
}
