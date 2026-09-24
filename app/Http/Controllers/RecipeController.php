<?php

namespace App\Http\Controllers;

use App\Actions\Operations\SaveRecipe;
use App\Actions\Operations\SetProductRecipeMode;
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

    public function mode(Request $request, Product $product, SetProductRecipeMode $mode): RedirectResponse
    {
        $request->validate(['no_recipe_needed' => ['required', 'boolean']]);
        $product = $mode->execute($request->user(), $product, $request->boolean('no_recipe_needed'));
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $product->no_recipe_needed
                ? $product->name.' marked as No recipe needed. It keeps using Product stock.'
                : $product->name.' now needs a recipe. Until one is added, its sales are not costed.',
        ]);

        return back();
    }
}
