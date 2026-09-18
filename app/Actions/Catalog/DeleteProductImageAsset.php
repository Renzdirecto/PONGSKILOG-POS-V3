<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Support\ProductImages;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DeleteProductImageAsset
{
    public function __construct(private ProductImages $images) {}

    /** Best-effort cleanup of an obsolete or uncommitted asset, never a user-facing mutation. */
    public function execute(Product $asset): void
    {
        try {
            $directory = $this->images->assetDirectory($asset);

            if ($directory !== null && ! Storage::disk('s3')->deleteDirectory($directory)) {
                throw new RuntimeException('Product image cleanup failed.');
            }
        } catch (Throwable $exception) {
            Log::warning('Product image asset cleanup failed.', [
                'product_id' => $asset->getKey(),
                'exception_type' => $exception::class,
            ]);
        }
    }
}
