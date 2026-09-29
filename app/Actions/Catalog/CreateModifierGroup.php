<?php

namespace App\Actions\Catalog;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateModifierGroup
{
    /** @param array{name?: mixed, semantic_role?: mixed, selection_type?: mixed, min_select?: mixed, max_select?: mixed, is_active?: mixed} $attributes */
    public function execute(User $user, array $attributes): ModifierGroup
    {
        Gate::forUser($user)->authorize('catalog.define');

        if (($attributes['semantic_role'] ?? null) === ModifierSemanticRole::Instruction->value) {
            $attributes['selection_type'] = ModifierSelectionType::Multiple->value;
            $attributes['min_select'] = 0;
            $attributes['max_select'] = max(2, (int) ($attributes['max_select'] ?? 3));
        }

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'semantic_role' => ['nullable', Rule::enum(ModifierSemanticRole::class)],
            'selection_type' => ['required', Rule::enum(ModifierSelectionType::class)],
            'min_select' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'max_select' => ['required', 'integer', 'min:0', 'max:2147483647', 'gte:min_select'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        return ModifierGroup::query()->create($validated);
    }
}
