<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateModifierGroup;
use App\Actions\Catalog\UpdateModifierGroup;
use App\Models\ModifierGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ModifierGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('catalog/modifiers', [
            'groups' => ModifierGroup::query()->select(['id', 'name', 'selection_type', 'min_select', 'max_select', 'is_active'])
                ->with(['options' => fn ($query) => $query->select(['id', 'modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active'])->orderBy('sort_order')->orderBy('name')])
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateModifierGroup $create): RedirectResponse
    {
        $create->execute($request->user(), $request->only(['name', 'selection_type', 'min_select', 'max_select', 'is_active']));

        return to_route('modifier-groups.index');
    }

    public function update(Request $request, ModifierGroup $modifierGroup, UpdateModifierGroup $update): RedirectResponse
    {
        $update->execute($request->user(), $modifierGroup, $request->only(['name', 'selection_type', 'min_select', 'max_select', 'is_active']));

        return to_route('modifier-groups.index');
    }
}
