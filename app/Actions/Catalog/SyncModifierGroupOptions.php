<?php

namespace App\Actions\Catalog;

use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SyncModifierGroupOptions
{
    /**
     * @param  list<array{id?: string|null, name: string, price_delta: string, sort_order: int, is_active: bool}>  $options
     */
    public function execute(User $user, ModifierGroup $modifierGroup, array $options): void
    {
        Gate::forUser($user)->authorize('products.manage');

        DB::transaction(function () use ($modifierGroup, $options): void {
            $modifierGroup = ModifierGroup::query()->whereKey($modifierGroup->getKey())->lockForUpdate()->firstOrFail();
            $existingOptions = $modifierGroup->options()->lockForUpdate()->get()->keyBy('id');
            $submittedIds = collect($options)->pluck('id')->filter()->values();

            if ($submittedIds->diff($existingOptions->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'options' => 'One or more options do not belong to this Group.',
                ]);
            }

            $removedIds = $existingOptions->keys()->diff($submittedIds);
            if ($removedIds->isNotEmpty()) {
                $modifierGroup->options()->whereKey($removedIds)->delete();
            }

            foreach ($options as $index => $option) {
                $attributes = [
                    'name' => $option['name'],
                    'price_delta' => $modifierGroup->semantic_role === ModifierSemanticRole::Instruction
                        ? '0.00'
                        : $option['price_delta'],
                    'sort_order' => $index,
                    'is_active' => $option['is_active'],
                ];

                $existing = isset($option['id']) ? $existingOptions->get($option['id']) : null;
                if ($existing instanceof ModifierOption) {
                    $existing->update($attributes);
                } else {
                    $modifierGroup->options()->create($attributes);
                }
            }

        });
    }
}
