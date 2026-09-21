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
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return Inertia::render('catalog/categories', [
            'categories' => Category::query()
                ->select(['id', 'name', 'icon_key', 'sort_order', 'is_active'])
                ->withCount('products')
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereLike('name', '%'.$search.'%'))
                ->when(in_array($filters['status'] ?? null, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')
                ->paginate(24)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, CreateCategory $create): RedirectResponse
    {
        $create->execute($request->user(), $request->only(['name', 'icon_key', 'sort_order', 'is_active']));

        return to_route('categories.index');
    }

    public function update(Request $request, Category $category, UpdateCategory $update): RedirectResponse
    {
        $update->execute($request->user(), $category, $request->only(['name', 'icon_key', 'sort_order', 'is_active']));

        return to_route('categories.index');
    }
}
