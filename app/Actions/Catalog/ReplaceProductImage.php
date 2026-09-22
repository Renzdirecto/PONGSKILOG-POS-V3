<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Support\ProductImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReplaceProductImage
{
    public function __construct(
        private ProductImageProcessor $processor,
        private DeleteProductImageAsset $cleanup,
    ) {}

    public function execute(User $user, Product $product, UploadedFile $upload): Product
    {
        Gate::forUser($user)->authorize('products.manage');
        $product = Product::query()->whereKey($product->getKey())->firstOrFail();
        $variants = $this->processor->process($upload);
        $directory = 'catalog/products/'.$product->getKey().'/'.Str::uuid();
        $newAsset = clone $product;
        $newAsset->image_path = $directory.'/detail.webp';
        $disk = Storage::disk('s3');

        try {
            foreach ([
                'source.'.$variants['extension'] => $variants['source'],
                'card.webp' => $variants['card'],
                'detail.webp' => $variants['detail'],
            ] as $filename => $contents) {
                if (! $disk->put($directory.'/'.$filename, $contents)) {
                    throw new RuntimeException('Could not store the product image asset.');
                }
            }

            foreach (['source.'.$variants['extension'], 'card.webp', 'detail.webp'] as $filename) {
                if (! $disk->exists($directory.'/'.$filename)) {
                    throw new RuntimeException('The stored product image asset is incomplete.');
                }
            }

            [$product, $oldAsset] = DB::transaction(function () use ($product, $newAsset): array {
                $product = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                $oldAsset = clone $product;
                $product->image_path = $newAsset->image_path;

                if (! $product->save()) {
                    throw new RuntimeException('Could not update the product image reference.');
                }

                return [$product, $oldAsset];
            });
        } catch (Throwable $exception) {
            $this->cleanup->execute($newAsset);

            throw $exception;
        }

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $this->cleanup->execute($newAsset));
        }

        DB::afterCommit(fn () => $this->cleanup->execute($oldAsset));

        return $product;
    }
}
