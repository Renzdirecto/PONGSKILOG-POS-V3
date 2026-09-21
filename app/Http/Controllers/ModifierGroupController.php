<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateModifierGroup;
use App\Actions\Catalog\SyncModifierGroupOptions;
use App\Actions\Catalog\UpdateModifierGroup;
use App\Http\Requests\SaveModifierGroupRequest;
use App\Models\ModifierGroup;
use App\Support\CatalogRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ModifierGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('catalog/modifiers', [
            'groups' => ModifierGroup::query()->select(['id', 'name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active'])
                ->with(['options' => fn ($query) => $query->select(['id', 'modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active'])->orderBy('sort_order')->orderBy('name')])
                ->orderBy('name')->get(),
        ]);
    }

    public function store(SaveModifierGroupRequest $request, CreateModifierGroup $create, SyncModifierGroupOptions $syncOptions): RedirectResponse
    {
        DB::transaction(function () use ($request, $create, $syncOptions): void {
            $group = $create->execute($request->user(), $request->safe()->only(['name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active']));
            $options = $request->validated('options');

            if (is_array($options)) {
                $syncOptions->execute($request->user(), $group, $options);
            }
        });

        return to_route('modifier-groups.index');
    }

    public function update(SaveModifierGroupRequest $request, ModifierGroup $modifierGroup, UpdateModifierGroup $update, SyncModifierGroupOptions $syncOptions, CatalogRealtime $realtime): RedirectResponse
    {
        $wasActive = $modifierGroup->is_active;
        $modifierGroup = DB::transaction(function () use ($request, $modifierGroup, $update, $syncOptions): ModifierGroup {
            $modifierGroup = $update->execute($request->user(), $modifierGroup, $request->safe()->only(['name', 'semantic_role', 'selection_type', 'min_select', 'max_select', 'is_active']));
            $options = $request->validated('options');

            if (is_array($options)) {
                $syncOptions->execute($request->user(), $modifierGroup, $options);
            }

            return $modifierGroup;
        });
        $realtime->productsChanged($modifierGroup->products()->get(), $wasActive !== $modifierGroup->is_active);

        return to_route('modifier-groups.index');
    }
}
