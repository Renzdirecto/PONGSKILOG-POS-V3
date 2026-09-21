<?php

namespace App\Actions\Catalog;

use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SyncProductModifierGroups
{
    /** @param array<array-key, mixed> $modifierGroupIds */
    public function execute(User $user, Product $product, array $modifierGroupIds): void
    {
        Gate::forUser($user)->authorize('products.manage');

        Validator::make(['modifier_group_ids' => $modifierGroupIds], [
            'modifier_group_ids' => ['present', 'array', 'list'],
            'modifier_group_ids.*' => ['bail', 'required', 'uuid', 'distinct', Rule::exists(ModifierGroup::class, 'id')],
        ])->validate();

        DB::transaction(function () use ($product, $modifierGroupIds): void {
            $product = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $product->modifierGroups()->sync($modifierGroupIds);
        });
    }
}
