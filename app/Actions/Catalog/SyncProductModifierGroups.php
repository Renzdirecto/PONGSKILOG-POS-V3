<?php

namespace App\Actions\Catalog;

use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SyncProductModifierGroups
{
    /** @param array<array-key, mixed> $modifierGroupIds */
    public function execute(User $user, Product $product, array $modifierGroupIds): void
    {
        Gate::forUser($user)->authorize('products.manage');

        Validator::make(['modifier_group_ids' => $modifierGroupIds], [
            'modifier_group_ids' => ['present', 'array', 'list'],
            'modifier_group_ids.*' => ['bail', 'required', 'uuid', Rule::exists(ModifierGroup::class, 'id')],
        ])->validate();

        DB::transaction(function () use ($product, $modifierGroupIds): void {
            $product = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            /** Only one active Size group may define a Product's base recipe variants; never guess between two. */
            $sizeGroups = ModifierGroup::query()->whereKey(array_values(array_unique($modifierGroupIds)))
                ->where('semantic_role', ModifierSemanticRole::Size->value)->where('is_active', true)
                ->orderBy('name')->pluck('name');
            if ($sizeGroups->count() > 1) {
                throw ValidationException::withMessages([
                    'modifier_group_ids' => $product->name.' can have only one active Size group. Keep one of: '.$sizeGroups->implode(', ').'.',
                ]);
            }
            $product->modifierGroups()->sync($modifierGroupIds);
        });
    }
}
