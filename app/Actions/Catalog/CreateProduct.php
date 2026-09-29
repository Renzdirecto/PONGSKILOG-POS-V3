<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateProduct
{
    /** @param array{category_id?: mixed, name?: mixed, description?: mixed, default_price?: mixed, is_active?: mixed} $attributes */
    public function execute(User $user, array $attributes): Product
    {
        Gate::forUser($user)->authorize('catalog.define');

        $validated = Validator::make($attributes, [
            'category_id' => ['bail', 'required', 'uuid', Rule::exists(Category::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'default_price' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        return Product::query()->create($validated);
    }
}
