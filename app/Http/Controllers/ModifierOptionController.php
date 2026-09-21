<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateModifierOption;
use App\Actions\Catalog\UpdateModifierOption;
use App\Models\ModifierOption;
use App\Support\CatalogRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ModifierOptionController extends Controller
{
    public function store(Request $request, CreateModifierOption $create, CatalogRealtime $realtime): RedirectResponse
    {
        $option = $create->execute($request->user(), $request->only(['modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active']));
        $realtime->productsChanged($option->modifierGroup->products()->get());

        return to_route('modifier-groups.index');
    }

    public function update(Request $request, ModifierOption $modifierOption, UpdateModifierOption $update, CatalogRealtime $realtime): RedirectResponse
    {
        $products = $modifierOption->modifierGroup->products()->get();
        $wasActive = $modifierOption->is_active;
        $modifierOption = $update->execute($request->user(), $modifierOption, $request->only(['modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active']));
        $products = $products->merge($modifierOption->modifierGroup->products()->get())->unique('id');
        $realtime->productsChanged($products, $wasActive !== $modifierOption->is_active);

        return to_route('modifier-groups.index');
    }
}
