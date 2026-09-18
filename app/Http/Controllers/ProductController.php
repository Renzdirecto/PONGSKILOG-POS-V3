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
use App\Support\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ProductImages $images): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $branches = Branch::query()->orderBy('code')->get(['id', 'code', 'name']);
        $products = Product::query()->with(['category', 'modifierGroups', 'branchProducts'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereLike('name', '%'.$search.'%'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')->orderBy('id')->paginate(24)->withQueryString()
            ->through(function (Product $product) use ($images, $branches): array {
                $overrides = $product->branchProducts->keyBy('branch_id');

                return [
                    ...$product->only(['id', 'name', 'description', 'category_id', 'default_price', 'is_active']),
                    'category_name' => $product->category->name,
                    'category_active' => $product->category->is_active,
                    'image_url' => $images->cardUrl($product),
                    'has_image' => $product->image_path !== null,
                    'modifier_group_ids' => $product->modifierGroups->modelKeys(),
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
