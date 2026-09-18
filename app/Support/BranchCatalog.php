<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Product;

class BranchCatalog
{
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
