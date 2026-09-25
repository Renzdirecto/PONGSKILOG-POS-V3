<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\AdjustInventory;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Requests\InventoryIndexRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\IngredientStockReport;
use App\Support\InventoryState;
use App\Support\OperationsWorkspace;
use App\Support\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Product (and Ingredient) stock. Business-wide Inventory reads All Branches or picks a Branch; a Branch-scoped
     * account reads only its selected assigned Branch (a browser-supplied branch_id is ignored for it).
     */
    public function index(InventoryIndexRequest $request, InventoryState $inventoryState, ProductImages $images, ActiveBranchContext $activeBranchContext, IngredientStockReport $ingredientStock, OperationsWorkspace $operations): Response|RedirectResponse
    {
        $filters = [
            'type' => $request->validated('type') ?? 'all',
            'search' => $request->validated('search') ?? '',
            'stock_status' => $request->validated('stock_status') ?? 'all',
            'category' => $request->validated('category') ?? '',
        ];
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $globalBranch = $activeBranchContext->managementBranch($user);
        if ($globalBranch === false) {
            return to_route('workspace');
        }
        $branches = $user->hasBusinessWideScope()
            ? Branch::query()->orderBy('code')->get(['id', 'name', 'code', 'status'])
            : Branch::query()->whereKey($globalBranch?->id)->get(['id', 'name', 'code', 'status']);
        $branch = $globalBranch ?? $branches->firstWhere('id', $request->validated('branch_id'));

        /** The current list is the Branch's assortment; a removed Product's movement history stays reachable by its link. */
        $baseQuery = Product::query()
            ->when($branch !== null, fn ($query) => $query->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branch?->id)))
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
            ->when($branch === null || $filters['type'] === 'ingredients', fn ($query) => $query->whereIn('products.id', []));

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

        /** Ingredients come from the same canonical Branch balances Operations › Ingredient Stock shows. */
        $ingredients = $branch === null || $filters['type'] === 'products' ? [] : array_values(array_filter(
            array_map(fn (array $row): array => $operations->presentIngredient($row), $ingredientStock->rows($branch)),
            fn (array $row): bool => $filters['search'] === '' || str_contains(mb_strtolower($row['name']), mb_strtolower($filters['search'])),
        ));
        /** The Products tab only needs the Ingredient tab count, not the full stock report. */
        $ingredientCount = $branch === null ? 0 : ($filters['type'] === 'products'
            ? Ingredient::query()->where('branch_id', $branch->id)->whereNull('archived_at')
                ->when($filters['search'] !== '', fn ($query) => $query->whereLike('name', '%'.$filters['search'].'%'))->count()
            : count($ingredients));

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
            'ingredients' => $ingredients,
            'ingredientCount' => $ingredientCount,
            'usesGlobalBranch' => $globalBranch !== null,
            'history' => $history,
        ]);
    }

    public function store(AdjustInventoryRequest $request, Branch $branch, Product $product, AdjustInventory $adjust): RedirectResponse
    {
        $this->authorizeBranch($request, $branch);
        $adjust->execute(
            $request->user(),
            $branch,
            $product,
            (int) $request->validated('quantity_delta'),
            $request->validated('reason'),
        );

        return back();
    }

    public function movements(Request $request, Branch $branch, Product $product): Response
    {
        $this->authorizeBranch($request, $branch);

        return Inertia::render('inventory/movements', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'product' => $product->only(['id', 'name']),
            'movements' => $this->movementHistory($branch, $product),
        ]);
    }

    /** A Branch-scoped Inventory manager reads and adjusts only its assigned Branches; business-wide reaches every Branch. */
    private function authorizeBranch(Request $request, Branch $branch): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessBranch($branch), 403);
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
