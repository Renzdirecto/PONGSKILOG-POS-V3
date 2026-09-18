<?php

namespace App\Actions\Catalog;

use App\Enums\ModifierSelectionType;
use App\Models\ModifierGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateModifierGroup
{
    /** @param array{name?: mixed, selection_type?: mixed, min_select?: mixed, max_select?: mixed, is_active?: mixed} $attributes */
    public function execute(User $user, ModifierGroup $modifierGroup, array $attributes): ModifierGroup
    {
        Gate::forUser($user)->authorize('products.manage');

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'selection_type' => ['required', Rule::enum(ModifierSelectionType::class)],
            'min_select' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'max_select' => ['required', 'integer', 'min:0', 'max:2147483647', 'gte:min_select'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        $modifierGroup = ModifierGroup::query()->whereKey($modifierGroup->getKey())->firstOrFail();
        $modifierGroup->update($validated);

        return $modifierGroup;
    }
}
