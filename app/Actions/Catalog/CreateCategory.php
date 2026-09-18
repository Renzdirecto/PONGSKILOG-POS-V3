<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CreateCategory
{
    /** @param array{name?: mixed, sort_order?: mixed, is_active?: mixed} $attributes */
    public function execute(User $user, array $attributes): Category
    {
        Gate::forUser($user)->authorize('products.manage');

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        return Category::query()->create($validated);
    }
}
