<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateCategory;
use App\Actions\Catalog\UpdateCategory;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('catalog/categories', [
            'categories' => Category::query()->select(['id', 'name', 'sort_order', 'is_active'])->withCount('products')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateCategory $create): RedirectResponse
    {
        $create->execute($request->user(), $request->only(['name', 'sort_order', 'is_active']));

        return to_route('categories.index');
    }

    public function update(Request $request, Category $category, UpdateCategory $update): RedirectResponse
    {
        $update->execute($request->user(), $category, $request->only(['name', 'sort_order', 'is_active']));

        return to_route('categories.index');
    }
}
