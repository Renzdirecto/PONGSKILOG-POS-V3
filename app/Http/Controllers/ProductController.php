<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\SyncProductModifierGroups;
use App\Actions\Catalog\UpdateProduct;
use App\Http\Requests\SaveProductRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\InventoryState;
use App\Support\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ProductImages $images, ActiveBranchContext $activeBranchContext, InventoryState $inventoryState): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive,low_stock,out_of_stock'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $inventoryBranch = $activeBranchContext->current($user);
        $branches = Branch::query()->orderBy('code')->get(['id', 'code', 'name']);
        $productsQuery = Product::query()->select('products.*')->with([
            'category',
            'modifierGroups',
            'branchProducts',
            'inventoryBalances' => fn ($query) => $query->where('branch_id', $inventoryBranch?->id)
                ->select(['id', 'product_id', 'on_hand', 'updated_at']),
        ])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereLike('name', '%'.$search.'%'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->when(in_array($filters['status'] ?? null, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'active'));

        if (in_array($filters['status'] ?? null, ['low_stock', 'out_of_stock'], true)) {
            if ($inventoryBranch === null) {
                $productsQuery->whereIn('products.id', []);
            } else {
                $inventoryState->filterProducts($productsQuery, $inventoryBranch, $filters['status']);
            }
        }

        $products = $productsQuery
            ->orderBy('name')->orderBy('id')->paginate(24)->withQueryString()
            ->through(function (Product $product) use ($images, $branches, $inventoryBranch, $inventoryState): array {
                $overrides = $product->branchProducts->keyBy('branch_id');
                $inventoryConfiguration = $inventoryBranch === null ? null : $overrides->get($inventoryBranch->id);
                $inventory = $inventoryBranch === null
                    ? null
                    : $inventoryState->resolve($inventoryConfiguration, $product->inventoryBalances->first());

                return [
                    ...$product->only(['id', 'name', 'description', 'category_id', 'default_price', 'is_active']),
                    'category_name' => $product->category->name,
                    'category_active' => $product->category->is_active,
                    'image_url' => $images->cardUrl($product),
                    'has_image' => $product->image_path !== null,
                    'modifier_group_ids' => $product->modifierGroups->modelKeys(),
                    'modifier_group_count' => $product->modifierGroups->count(),
                    'inventory' => $inventory,
                    'branch_prices' => $branches->map(function (Branch $branch) use ($product, $overrides): array {
                        $override = $overrides->get($branch->id);

                        return [
                            'branch_id' => $branch->id,
                            'code' => $branch->code,
                            'name' => $branch->name,
                            'price_override' => $override?->price_override,
                            'effective_price' => $override->price_override ?? $product->default_price,
                            'is_available' => $override->is_available ?? true,
                            'tracks_inventory' => $override->tracks_inventory ?? false,
                            'low_stock_threshold' => $override?->low_stock_threshold,
                            'effective_available' => $product->is_active && $product->category->is_active && ($override->is_available ?? true),
                        ];
                    })->all(),
                ];
            });

        return Inertia::render('catalog/products', [
            'products' => $products,
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active']),
            'modifierGroups' => ModifierGroup::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'filters' => $filters,
        ]);
    }

    public function store(SaveProductRequest $request, CreateProduct $create, SyncProductModifierGroups $sync): RedirectResponse
    {
        DB::transaction(function () use ($request, $create, $sync): void {
            $product = $create->execute($request->user(), $request->only(['name', 'category_id', 'description', 'default_price', 'is_active']));
            $sync->execute($request->user(), $product, $request->validated('modifier_group_ids'));
        });

        return to_route('products.index');
    }

    public function update(SaveProductRequest $request, Product $product, UpdateProduct $update, SyncProductModifierGroups $sync): RedirectResponse
    {
        DB::transaction(function () use ($request, $product, $update, $sync): void {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $update->execute($request->user(), $product, $request->only(['name', 'category_id', 'description', 'default_price', 'is_active']));
            $sync->execute($request->user(), $product, $request->validated('modifier_group_ids'));
        });

        return back();
    }
}
