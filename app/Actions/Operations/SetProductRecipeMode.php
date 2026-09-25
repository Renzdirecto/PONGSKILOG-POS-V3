<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\User;
use App\Support\BranchConfiguration;
use App\Support\CatalogRealtime;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the selected Branch's recipe mode of one Product in its assortment: direct / No recipe needed (direct resale,
 * such as bottled or canned drinks, optionally tracking Product stock) or back to needing an Ingredient recipe. Another
 * Branch may use a different mode for the same Product. It changes future sales only.
 */
class SetProductRecipeMode
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit, private CatalogRealtime $realtime) {}

    public function execute(User $actor, Product $product, bool $noRecipeNeeded): BranchProduct
    {
        $actor = $this->access->authorize($actor);
        $branch = $this->access->configurationBranch($actor);

        return DB::transaction(function () use ($actor, $branch, $product, $noRecipeNeeded): BranchProduct {
            $branch = BranchConfiguration::lock($branch);
            $configuration = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->lockForUpdate()->first();
            if ($configuration === null) {
                throw ValidationException::withMessages(['product' => $product->name.' is not in the '.$branch->code.' assortment.']);
            }
            if ($configuration->no_recipe_needed === $noRecipeNeeded) {
                return $configuration;
            }
            if ($noRecipeNeeded && Recipe::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product' => $product->name.' still has a recipe at '.$branch->code.'. Remove its recipes before marking it No recipe needed.']);
            }
            if ($noRecipeNeeded && ProductModifierEffect::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product' => $product->name.' still has add-on ingredient effects at '.$branch->code.'. Remove them before marking it No recipe needed.']);
            }
            $configuration->update(['no_recipe_needed' => $noRecipeNeeded]);
            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'operations',
                action: 'recipe.mode_changed',
                auditableType: Product::class,
                auditableId: $product->id,
                before: ['branch_code' => $branch->code, 'no_recipe_needed' => ! $noRecipeNeeded],
                after: ['branch_code' => $branch->code, 'no_recipe_needed' => $noRecipeNeeded],
            );
            $this->realtime->branchConfigurationChanged($branch, 'recipe_changed');

            return $configuration;
        });
    }
}
