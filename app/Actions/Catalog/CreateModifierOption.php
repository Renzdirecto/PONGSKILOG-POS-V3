<?php

namespace App\Actions\Catalog;

use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\User;
use App\Support\ExactMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateModifierOption
{
    /** @param array{modifier_group_id?: mixed, name?: mixed, price_delta?: mixed, is_active?: mixed, sort_order?: mixed} $attributes */
    public function execute(User $user, array $attributes): ModifierOption
    {
        Gate::forUser($user)->authorize('products.manage');

        $validated = Validator::make($attributes, [
            'modifier_group_id' => ['bail', 'required', 'uuid', Rule::exists(ModifierGroup::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'price_delta' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ])->validate();

        return DB::transaction(function () use ($validated): ModifierOption {
            $group = ModifierGroup::query()->whereKey($validated['modifier_group_id'])->lockForUpdate()->firstOrFail();
            if ($group->semantic_role === ModifierSemanticRole::Instruction && ExactMoney::cents($validated['price_delta']) !== 0) {
                throw ValidationException::withMessages(['price_delta' => 'Instruction options cannot change the price.']);
            }

            return ModifierOption::query()->create($validated);
        });
    }
}
