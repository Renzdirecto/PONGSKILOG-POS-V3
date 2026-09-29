<?php

namespace App\Actions\Catalog;

use App\Events\ReportsChanged;
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

/**
 * Writes one Branch Product configuration (price, availability, Product stock tracking, low-stock threshold). A row is
 * the Product's membership in the Branch assortment: creating one adds the Product to that Branch, so only an explicit
 * add (Product create/edit with that Branch selected, Add products, Copy) may pass $createMembership.
 */
class UpsertBranchProduct
{
    /** @param array{price_override?: mixed, is_available?: mixed, tracks_inventory?: mixed, low_stock_threshold?: mixed} $attributes */
    public function execute(User $user, Branch $branch, Product $product, array $attributes, bool $createMembership = true): BranchProduct
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
        $existing = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->lockForUpdate()->first();
        if ($existing === null && ! $createMembership) {
            throw ValidationException::withMessages([
                'product' => $product->name.' is not in the '.$branch->code.' assortment. Add it to '.$branch->code.' first.',
            ]);
        }
        /**
         * A Product using an Ingredient recipe at this Branch consumes Ingredient stock here; it must never also deduct
         * Product stock for one sale. Other Branches may configure the same Product differently.
         */
        if ($validated['tracks_inventory'] && ($existing === null || ! $existing->tracks_inventory)
            && (Recipe::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->exists()
                || ProductModifierEffect::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->exists())) {
            throw ValidationException::withMessages([
                'tracks_inventory' => $product->name.' has an ingredient recipe or add-on ingredient effects at '.$branch->code.' in Operations. Remove them there before tracking Product stock at '.$branch->code.'.',
            ]);
        }

        if ($existing !== null) {
            $existing->update($validated);
            /** Product stock tracking is part of this Branch's recipe mode, so its open Operations pages refetch. */
            if ($existing->wasChanged('tracks_inventory')) {
                ReportsChanged::dispatch((string) $branch->id, 'recipe_changed');
            }

            return $existing;
        }

        $created = BranchProduct::query()->create([...$validated, 'branch_id' => $branch->id, 'product_id' => $product->id]);
        /** A new member joins this Branch's Plan pickers and Recipes, so its open Operations pages refetch. */
        ReportsChanged::dispatch((string) $branch->id, 'assortment_changed');

        return $created;
    }
}
