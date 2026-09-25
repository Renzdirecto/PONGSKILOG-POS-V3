<?php

namespace App\Actions\Catalog;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpsertBranchProduct
{
    /** @param array{price_override?: mixed, is_available?: mixed, tracks_inventory?: mixed, low_stock_threshold?: mixed} $attributes */
    public function execute(User $user, Branch $branch, Product $product, array $attributes): BranchProduct
    {
        Gate::forUser($user)->authorize('products.manage');
        /** A Branch-scoped Product manager configures only its own assigned Branches; business-wide reaches every Branch. */
        if (! $user->canAccessBranch($branch)) {
            throw new AuthorizationException('This account may not configure Products at this Branch.');
        }

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
        /** A recipe-backed Product consumes Ingredient stock; it must never also deduct Product stock for one sale. */
        if ($validated['tracks_inventory']
            && (Recipe::query()->where('product_id', $product->id)->exists() || ProductModifierEffect::query()->where('product_id', $product->id)->exists())
            && ! BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->where('tracks_inventory', true)->exists()) {
            throw ValidationException::withMessages([
                'tracks_inventory' => $product->name.' has an ingredient recipe or add-on ingredient effects in Operations. Remove them before tracking Product stock.',
            ]);
        }

        return BranchProduct::query()->updateOrCreate([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
        ], $validated);
    }
}
