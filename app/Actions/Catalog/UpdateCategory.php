<?php

namespace App\Actions\Catalog;

use App\Enums\CategoryIcon;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateCategory
{
    /** @param array{name?: mixed, sort_order?: mixed, is_active?: mixed} $attributes */
    public function execute(User $user, Category $category, array $attributes): Category
    {
        Gate::forUser($user)->authorize('catalog.define');

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'icon_key' => ['nullable', Rule::enum(CategoryIcon::class)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        $category = Category::query()->whereKey($category->getKey())->firstOrFail();
        $category->update($validated);

        return $category;
    }
}
