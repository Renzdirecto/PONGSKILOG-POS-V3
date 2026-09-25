<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Events\CustomerCatalogChanged;
use App\Events\IngredientStockChanged;
use App\Events\ProductAvailabilityChanged;
use App\Events\ProductBranchConfigurationChanged;
use App\Events\ReportsChanged;
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

    /**
     * Several Products changed their configuration at one Branch (bulk assortment add or copy): one Customer QR catalog
     * invalidation plus the compact per-Product Branch events, resolved with one catalog load. Never another Branch.
     *
     * @param  list<string>  $productIds
     */
    public function branchProductsChanged(Branch $branch, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }
        $version = (int) now()->format('Uu');
        CustomerCatalogChanged::dispatch($branch->id);

        foreach ($this->catalog->productsForOrder($branch, $productIds) as $product) {
            $state = $this->catalog->resolveLoaded($product);
            ProductBranchConfigurationChanged::dispatch($branch->id, $product->id, $state['is_available'], $state['effective_price'], $version);
            ProductAvailabilityChanged::dispatch($branch->id, $product->id, $state['is_available'], $state['effective_price'], $version);
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
     * One Branch's configuration changed (assortment, recipe mode, Recipes, Add-on effects, Ingredients, Plans or a setup
     * copy): that Branch's POS and Customer QR catalogs and its open Operations pages refetch their authoritative state
     * (compact invalidation only, after commit). Another Branch is never signalled.
     */
    public function branchConfigurationChanged(Branch $branch, string $reason): void
    {
        $this->ingredientsChanged($branch, $reason);
        ReportsChanged::dispatch((string) $branch->id, $reason);
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
