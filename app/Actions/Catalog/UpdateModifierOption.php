<?php

namespace App\Actions\Catalog;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateModifierOption
{
    /** @param array{modifier_group_id?: mixed, name?: mixed, price_delta?: mixed, is_active?: mixed, sort_order?: mixed} $attributes */
    public function execute(User $user, ModifierOption $modifierOption, array $attributes): ModifierOption
    {
        Gate::forUser($user)->authorize('products.manage');

        $validated = Validator::make($attributes, [
            'modifier_group_id' => ['bail', 'required', 'uuid', Rule::exists(ModifierGroup::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'price_delta' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ])->validate();

        $modifierOption = ModifierOption::query()->whereKey($modifierOption->getKey())->firstOrFail();
        $modifierOption->update($validated);

        return $modifierOption;
    }
}
