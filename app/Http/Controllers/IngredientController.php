<?php

namespace App\Http\Controllers;

use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Operations\SaveIngredient;
use App\Actions\Operations\SetIngredientArchived;
use App\Models\Ingredient;
use App\Support\ExactQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IngredientController extends Controller
{
    private const FIELDS = [
        'name', 'icon', 'base_unit', 'target_quantity', 'purchase_unit_name', 'purchase_unit_size', 'purchase_unit_cost',
        'replenishment_rule', 'reorder_point', 'plan_ids',
    ];

    public function store(Request $request, SaveIngredient $save): RedirectResponse
    {
        $ingredient = $save->execute($request->user(), null, $request->only([...self::FIELDS, 'initial_quantity']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $ingredient->name.' added.']);

        return back();
    }

    public function update(Request $request, Ingredient $ingredient, SaveIngredient $save): RedirectResponse
    {
        $ingredient = $save->execute($request->user(), $ingredient, $request->only(self::FIELDS));
        Inertia::flash('toast', ['type' => 'success', 'message' => $ingredient->name.' updated. Past movements and costs are unchanged.']);

        return back();
    }

    public function archive(Request $request, Ingredient $ingredient, SetIngredientArchived $archive): RedirectResponse
    {
        $ingredient = $archive->execute($request->user(), $ingredient, true);
        Inertia::flash('toast', ['type' => 'success', 'message' => $ingredient->name.' archived. Its history is kept.']);

        return back();
    }

    public function restore(Request $request, Ingredient $ingredient, SetIngredientArchived $archive): RedirectResponse
    {
        $ingredient = $archive->execute($request->user(), $ingredient, false);
        Inertia::flash('toast', ['type' => 'success', 'message' => $ingredient->name.' restored.']);

        return back();
    }

    public function adjust(Request $request, Ingredient $ingredient, AdjustIngredientStock $adjust): RedirectResponse
    {
        $movement = $adjust->execute($request->user(), $ingredient, $request->only(['mode', 'quantity', 'reason', 'note', 'idempotency_key']));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $ingredient->name.' stock is now '.ExactQuantity::display(ExactQuantity::parse($movement->balance_after)).' '.$ingredient->base_unit.'.',
        ]);

        return back();
    }
}
