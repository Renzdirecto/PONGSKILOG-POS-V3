<?php

namespace App\Http\Controllers;

use App\Actions\Operations\SaveModifierEffect;
use App\Actions\Operations\SaveRecipe;
use App\Actions\Operations\SetProductRecipeMode;
use App\Models\ModifierOption;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecipeController extends Controller
{
    public function update(Request $request, Product $product, SaveRecipe $save): RedirectResponse
    {
        $recipe = $save->execute($request->user(), $product, $request->only(['size_option_id', 'lines']));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $recipe === null ? 'Recipe removed. Future sales of this size are not costed.' : 'Recipe saved. It applies to future sales; past sales keep their recipe.',
        ]);

        return back();
    }

    public function effect(Request $request, Product $product, ModifierOption $option, SaveModifierEffect $save): RedirectResponse
    {
        $effect = $save->execute($request->user(), $product, $option, $request->only(['lines']));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $effect === null
                ? $option->name.' now has no ingredient effect on '.$product->name.'.'
                : $option->name.' ingredient effect saved. It applies to future sales; past sales keep theirs.',
        ]);

        return back();
    }

    public function mode(Request $request, Product $product, SetProductRecipeMode $mode): RedirectResponse
    {
        $request->validate(['no_recipe_needed' => ['required', 'boolean']]);
        $product = $mode->execute($request->user(), $product, $request->boolean('no_recipe_needed'));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $product->no_recipe_needed
                ? $product->name.' marked as No recipe needed. It keeps using Product stock.'
                : $product->name.' now uses an ingredient recipe. Set up its recipe below.',
        ]);

        return back();
    }
}
