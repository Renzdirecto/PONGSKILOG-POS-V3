<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateModifierOption;
use App\Actions\Catalog\UpdateModifierOption;
use App\Models\ModifierOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ModifierOptionController extends Controller
{
    public function store(Request $request, CreateModifierOption $create): RedirectResponse
    {
        $create->execute($request->user(), $request->only(['modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active']));

        return to_route('modifier-groups.index');
    }

    public function update(Request $request, ModifierOption $modifierOption, UpdateModifierOption $update): RedirectResponse
    {
        $update->execute($request->user(), $modifierOption, $request->only(['modifier_group_id', 'name', 'price_delta', 'sort_order', 'is_active']));

        return to_route('modifier-groups.index');
    }
}
