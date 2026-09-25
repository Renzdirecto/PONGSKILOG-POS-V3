<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\User;
use App\Support\CatalogRealtime;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks an existing Product as No recipe needed (direct resale, such as bottled or canned drinks) or back to needing a
 * recipe. It changes future sales only; direct-resale sales keep using the existing Product stock mechanism.
 */
class SetProductRecipeMode
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit, private CatalogRealtime $realtime) {}

    public function execute(User $actor, Product $product, bool $noRecipeNeeded): Product
    {
        $actor = $this->access->authorizeDefinitions($actor);

        return DB::transaction(function () use ($actor, $product, $noRecipeNeeded): Product {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->no_recipe_needed === $noRecipeNeeded) {
                return $product;
            }
            if ($noRecipeNeeded && Recipe::query()->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product' => $product->name.' still has a recipe. Remove its recipes before marking it No recipe needed.']);
            }
            if ($noRecipeNeeded && ProductModifierEffect::query()->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product' => $product->name.' still has add-on ingredient effects. Remove them before marking it No recipe needed.']);
            }
            $product->update(['no_recipe_needed' => $noRecipeNeeded]);
            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'operations',
                action: 'recipe.mode_changed',
                auditableType: Product::class,
                auditableId: $product->id,
                before: ['no_recipe_needed' => ! $noRecipeNeeded],
                after: ['no_recipe_needed' => $noRecipeNeeded],
            );
            $this->realtime->ingredientsChanged(null, 'recipe_changed');

            return $product;
        });
    }
}
