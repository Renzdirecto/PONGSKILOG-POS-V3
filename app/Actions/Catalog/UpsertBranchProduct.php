<?php

namespace App\Actions\Catalog;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpsertBranchProduct
{
    /** @param array{price_override?: mixed, is_available?: mixed, tracks_inventory?: mixed, low_stock_threshold?: mixed} $attributes */
    public function execute(User $user, Branch $branch, Product $product, array $attributes): BranchProduct
    {
        Gate::forUser($user)->authorize('products.manage');

        $validated = Validator::make($attributes, [
            'price_override' => [
                'bail', 'present', 'nullable', Rule::requiredIf(($attributes['price_override'] ?? null) !== null),
                'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/',
            ],
            'is_available' => ['required', 'boolean'],
            'tracks_inventory' => ['required', 'boolean'],
            'low_stock_threshold' => [
                'present', 'nullable', Rule::requiredIf(($attributes['low_stock_threshold'] ?? null) !== null),
                'integer', 'min:0', 'max:2147483647',
            ],
        ])->validate();

        $branch = Branch::query()->whereKey($branch->getKey())->firstOrFail();
        $product = Product::query()->whereKey($product->getKey())->firstOrFail();

        return BranchProduct::query()->updateOrCreate([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
        ], $validated);
    }
}
