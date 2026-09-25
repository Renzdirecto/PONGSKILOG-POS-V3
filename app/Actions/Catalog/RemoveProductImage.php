<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class RemoveProductImage
{
    public function __construct(private DeleteProductImageAsset $cleanup) {}

    public function execute(User $user, Product $product): Product
    {
        Gate::forUser($user)->authorize('catalog.define');

        [$product, $oldAsset] = DB::transaction(function () use ($product): array {
            $product = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $oldAsset = clone $product;
            $product->image_path = null;

            if (! $product->save()) {
                throw new RuntimeException('Could not remove the product image reference.');
            }

            return [$product, $oldAsset];
        });

        DB::afterCommit(fn () => $this->cleanup->execute($oldAsset));

        return $product;
    }
}
