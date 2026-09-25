<?php

namespace App\Actions\Catalog;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateModifierGroup
{
    /** @param array{name?: mixed, semantic_role?: mixed, selection_type?: mixed, min_select?: mixed, max_select?: mixed, is_active?: mixed} $attributes */
    public function execute(User $user, ModifierGroup $modifierGroup, array $attributes): ModifierGroup
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

        return DB::transaction(function () use ($modifierGroup, $validated): ModifierGroup {
            $modifierGroup = ModifierGroup::query()->whereKey($modifierGroup->getKey())->lockForUpdate()->firstOrFail();
            if (($validated['semantic_role'] ?? null) === ModifierSemanticRole::Size->value && (bool) $validated['is_active']) {
                $this->guardSingleSizeGroup($modifierGroup);
            }
            $modifierGroup->update($validated);

            if ($modifierGroup->semantic_role === ModifierSemanticRole::Instruction) {
                $modifierGroup->options()->where('price_delta', '!=', 0)->update(['price_delta' => '0.00']);
            }

            return $modifierGroup;
        });
    }

    /** A Product may have one active Size group; making this group an active Size group must not give any a second. */
    private function guardSingleSizeGroup(ModifierGroup $modifierGroup): void
    {
        $products = Product::query()
            ->whereHas('modifierGroups', fn ($query) => $query->whereKey($modifierGroup->id))
            ->whereHas('modifierGroups', fn ($query) => $query->whereKeyNot($modifierGroup->id)
                ->where('semantic_role', ModifierSemanticRole::Size->value)->where('is_active', true))
            ->orderBy('name')->limit(3)->pluck('name');
        if ($products->isNotEmpty()) {
            throw ValidationException::withMessages([
                'semantic_role' => $products->implode(', ').' already '.($products->count() === 1 ? 'has' : 'have').' an active Size group. A product can have only one Size group, so unassign one first.',
            ]);
        }
    }
}
