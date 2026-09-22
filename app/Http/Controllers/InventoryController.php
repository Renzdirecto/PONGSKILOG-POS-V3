<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\AdjustInventory;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Requests\InventoryIndexRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\InventoryState;
use App\Support\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(InventoryIndexRequest $request, InventoryState $inventoryState, ProductImages $images, ActiveBranchContext $activeBranchContext): Response
    {
        $filters = [
            'search' => $request->validated('search') ?? '',
            'stock_status' => $request->validated('stock_status') ?? 'all',
            'category' => $request->validated('category') ?? '',
        ];
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branches = Branch::query()->orderBy('code')->get(['id', 'name', 'code', 'status']);
        $globalBranch = $activeBranchContext->current($user);
        $branch = $globalBranch ?? $branches->firstWhere('id', $request->validated('branch_id'));

        $baseQuery = Product::query()
            ->when($filters['search'] !== '', fn ($query) => $query->whereLike('products.name', '%'.$filters['search'].'%'))
            ->when($filters['category'] !== '', fn ($query) => $query->where('products.category_id', $filters['category']));

        $summary = collect(['in_stock', 'low_stock', 'out_of_stock', 'not_tracked'])
            ->mapWithKeys(function (string $status) use ($baseQuery, $branch, $inventoryState): array {
                if ($branch === null) {
                    return [$status => 0];
                }

                $statusQuery = clone $baseQuery;
                $inventoryState->filterProducts($statusQuery, $branch, $status);

                return [$status => $statusQuery->count('products.id')];
            })->all();

        $query = (clone $baseQuery)->select('products.*')
            ->with([
                'category:id,name',
                'branchProducts' => fn ($query) => $query->where('branch_id', $branch?->id)
                    ->select(['id', 'product_id', 'tracks_inventory', 'low_stock_threshold']),
                'inventoryBalances' => fn ($query) => $query->where('branch_id', $branch?->id)
                    ->select(['id', 'product_id', 'on_hand', 'updated_at']),
            ])
            ->when($branch === null, fn ($query) => $query->whereIn('products.id', []));

        if ($branch !== null && $filters['stock_status'] !== 'all') {
            $inventoryState->filterProducts($query, $branch, $filters['stock_status']);
        }

        $products = $query->orderBy('products.name')->orderBy('products.id')
            ->paginate(24)->withQueryString()->appends(['branch_id' => $branch?->id])
            ->through(function (Product $product) use ($images, $inventoryState): array {
                $balance = $product->inventoryBalances->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category_name' => $product->category->name,
                    'image_url' => $images->safeCardUrl($product),
                    'last_updated_at' => $balance?->updated_at?->toIso8601String(),
                    ...$inventoryState->resolve($product->branchProducts->first(), $balance),
                ];
            });

        $historyProduct = $branch === null || $request->validated('history_product') === null
            ? null
            : Product::query()->whereKey($request->validated('history_product'))->first();
        $history = $historyProduct === null ? null : [
            'branch' => $branch->only(['id', 'name', 'code']),
            'product' => $historyProduct->only(['id', 'name']),
            'movements' => $this->movementHistory($branch, $historyProduct, 'history_page'),
        ];

        return Inertia::render('inventory/index', [
            'branches' => $branches->map(fn (Branch $branch): array => $branch->only(['id', 'name', 'code'])),
            'selectedBranch' => $branch?->only(['id', 'name', 'code']),
            'filters' => $filters,
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'summary' => $summary,
            'products' => $products,
            'usesGlobalBranch' => $globalBranch !== null,
            'history' => $history,
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
        return Inertia::render('inventory/movements', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'product' => $product->only(['id', 'name']),
            'movements' => $this->movementHistory($branch, $product),
        ]);
    }

    /** @return LengthAwarePaginator<int, covariant array<string, mixed>> */
    private function movementHistory(Branch $branch, Product $product, string $pageName = 'page'): LengthAwarePaginator
    {
        return InventoryMovement::query()
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(30, ['*'], $pageName)
            ->through(fn (InventoryMovement $movement): array => [
                'id' => $movement->id,
                'created_at' => $movement->created_at->toIso8601String(),
                'movement_type' => $movement->movement_type->value,
                'movement_label' => $movement->movement_type->label(),
                'quantity_delta' => $movement->quantity_delta,
                'reason' => $movement->reason,
                'created_by_name' => $movement->createdBy?->name,
            ]);
    }
}
