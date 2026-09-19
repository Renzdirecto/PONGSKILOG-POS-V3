<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\AdjustInventory;
use App\Enums\BranchStatus;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Requests\InventoryIndexRequest;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\ActiveBranchContext;
use App\Support\InventoryState;
use App\Support\ProductImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(InventoryIndexRequest $request, InventoryState $inventoryState, ProductImages $images): Response
    {
        $filters = [
            'search' => $request->validated('search') ?? '',
            'stock_status' => $request->validated('stock_status') ?? 'all',
        ];
        $branches = Branch::query()->orderBy('code')->get(['id', 'name', 'code', 'status']);
        $branch = $branches->firstWhere('id', $request->validated('branch_id'))
            ?? $branches->firstWhere('id', $request->session()->get(ActiveBranchContext::SESSION_KEY))
            ?? $branches->firstWhere('status', BranchStatus::Active)
            ?? $branches->first();

        $query = Product::query()->select('products.*')
            ->with([
                'category:id,name',
                'branchProducts' => fn ($query) => $query->where('branch_id', $branch?->id)
                    ->select(['id', 'product_id', 'tracks_inventory', 'low_stock_threshold']),
                'inventoryBalances' => fn ($query) => $query->where('branch_id', $branch?->id)
                    ->select(['id', 'product_id', 'on_hand']),
            ])
            ->when($branch === null, fn ($query) => $query->whereIn('products.id', []))
            ->when($filters['search'] !== '', fn ($query) => $query->whereLike('products.name', '%'.$filters['search'].'%'));

        if ($branch !== null && $filters['stock_status'] !== 'all') {
            $this->filterStockStatus($query, $branch, $filters['stock_status']);
        }

        $products = $query->orderBy('products.name')->orderBy('products.id')
            ->paginate(24)->withQueryString()->appends(['branch_id' => $branch?->id])
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'category_name' => $product->category->name,
                'image_url' => $images->cardUrl($product),
                ...$inventoryState->resolve($product->branchProducts->first(), $product->inventoryBalances->first()),
            ]);

        return Inertia::render('inventory/index', [
            'branches' => $branches->map(fn (Branch $branch): array => $branch->only(['id', 'name', 'code'])),
            'selectedBranch' => $branch?->only(['id', 'name', 'code']),
            'filters' => $filters,
            'products' => $products,
        ]);
    }

    public function store(AdjustInventoryRequest $request, Branch $branch, Product $product, AdjustInventory $adjust): RedirectResponse
    {
        $adjust->execute(
            $request->user(),
            $branch,
            $product,
            (int) $request->validated('quantity_delta'),
            $request->validated('reason'),
        );

        return back();
    }

    public function movements(Branch $branch, Product $product): Response
    {
        $movements = InventoryMovement::query()
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(30)
            ->through(fn (InventoryMovement $movement): array => [
                'id' => $movement->id,
                'created_at' => $movement->created_at->toIso8601String(),
                'movement_type' => $movement->movement_type->value,
                'movement_label' => $movement->movement_type->label(),
                'quantity_delta' => $movement->quantity_delta,
                'reason' => $movement->reason,
                'created_by_name' => $movement->createdBy?->name,
            ]);

        return Inertia::render('inventory/movements', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'product' => $product->only(['id', 'name']),
            'movements' => $movements,
        ]);
    }

    /** @param Builder<Product> $query */
    private function filterStockStatus(Builder $query, Branch $branch, string $status): void
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
