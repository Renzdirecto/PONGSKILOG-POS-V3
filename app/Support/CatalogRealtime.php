<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Events\CustomerCatalogChanged;
use App\Events\IngredientStockChanged;
use App\Events\ProductAvailabilityChanged;
use App\Events\ProductBranchConfigurationChanged;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class CatalogRealtime
{
    public function __construct(private BranchCatalog $catalog) {}

    public function productChanged(Product $product, ?Branch $branch = null, bool $availabilityChanged = false): void
    {
        $branches = $branch === null
            ? Branch::query()->orderBy('id')->get()
            : new Collection([$branch]);
        $version = (int) now()->format('Uu');

        foreach ($branches as $targetBranch) {
            CustomerCatalogChanged::dispatch($targetBranch->id);
            $loadedProduct = $this->catalog->productsForOrder($targetBranch, [$product->getKey()])->first();

            if ($loadedProduct === null) {
                continue;
            }

            $state = $this->catalog->resolveLoaded($loadedProduct);
            ProductBranchConfigurationChanged::dispatch(
                $targetBranch->id,
                $product->id,
                $state['is_available'],
                $state['effective_price'],
                $version,
            );

            if ($availabilityChanged) {
                ProductAvailabilityChanged::dispatch(
                    $targetBranch->id,
                    $product->id,
                    $state['is_available'],
                    $state['effective_price'],
                    $version,
                );
            }
        }
    }

    /** @param iterable<Product> $products */
    public function productsChanged(iterable $products, bool $availabilityChanged = false): void
    {
        foreach ($products as $product) {
            $this->productChanged($product, availabilityChanged: $availabilityChanged);
        }
    }

    /**
     * Branch Ingredient stock or a recipe changed, so Recipe-based availability may have changed: Cashier POS and
     * Customer QR refetch their authoritative catalog (after commit, invalidation only). A null Branch means every Branch.
     */
    public function ingredientsChanged(?Branch $branch, string $reason): void
    {
        /** A business-wide change reaches only active Branches: inactive ones have no POS or Customer QR clients. */
        $branchIds = $branch === null ? Branch::query()->where('status', BranchStatus::Active)->orderBy('id')->pluck('id')->all() : [$branch->id];
        foreach ($branchIds as $branchId) {
            IngredientStockChanged::dispatch($branchId, $reason);
            CustomerCatalogChanged::dispatch($branchId);
        }
    }
}
