<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;

class BranchCatalog
{
    public function __construct(private ProductImages $images) {}

    /**
     * @return array{
     *     categories: list<array{id: string, name: string}>,
     *     products: list<array{id: string, name: string, category_id: string, category_name: string, effective_price: string, is_available: bool, image_url: string|null, has_modifiers: bool}>
     * }
     */
    public function browse(Branch $branch): array
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->with(['products' => fn ($query) => $query
                ->select(['id', 'category_id', 'name', 'default_price', 'image_path'])
                ->where('is_active', true)
                ->orderBy('name')->orderBy('id')
                ->withExists(['modifierGroups as has_modifiers' => fn ($query) => $query->where('is_active', true)])
                ->with(['branchProducts' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->select(['id', 'product_id', 'price_override', 'is_available'])]),
            ])
            ->get(['id', 'name']);

        $products = [];

        foreach ($categories as $category) {
            foreach ($category->products as $product) {
                $override = $product->branchProducts->first();
                $products[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'effective_price' => $override->price_override ?? $product->default_price,
                    'is_available' => $override?->is_available !== false,
                    'image_url' => $this->images->cardUrl($product),
                    'has_modifiers' => (bool) $product->getAttribute('has_modifiers'),
                ];
            }
        }

        return [
            'categories' => array_values($categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])->all()),
            'products' => $products,
        ];
    }

    public function effectivePrice(Product $product, Branch $branch): string
    {
        $product = Product::query()->whereKey($product->getKey())->firstOrFail();
        $override = $product->branchProducts()->where('branch_id', $branch->getKey())->first();

        return $override->price_override ?? $product->default_price;
    }

    public function isAvailable(Product $product, Branch $branch): bool
    {
        $product = Product::query()->whereKey($product->getKey())->firstOrFail();

        if (! $product->is_active || ! $product->category()->where('is_active', true)->exists()) {
            return false;
        }

        return ! $product->branchProducts()
            ->where('branch_id', $branch->getKey())
            ->where('is_available', false)
            ->exists();
    }
}
