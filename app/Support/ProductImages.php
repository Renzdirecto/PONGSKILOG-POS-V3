<?php

namespace App\Support;

use App\Models\Product;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImages
{
    public function assetDirectory(Product $product): ?string
    {
        $path = $product->image_path;

        if ($path === null) {
            return null;
        }

        $segments = explode('/', $path);

        if (count($segments) !== 5 || $segments[0] !== 'catalog' || $segments[1] !== 'products'
            || $segments[2] !== $product->getKey() || ! Str::isUuid($segments[2])
            || ! Str::isUuid($segments[3]) || $segments[4] !== 'detail.webp') {
            throw new RuntimeException('Invalid product image asset path.');
        }

        return dirname($path);
    }

    public function cardPath(Product $product): ?string
    {
        $directory = $this->assetDirectory($product);

        return $directory === null ? null : $directory.'/card.webp';
    }

    public function detailPath(Product $product): ?string
    {
        $directory = $this->assetDirectory($product);

        return $directory === null ? null : $directory.'/detail.webp';
    }

    public function cardUrl(Product $product, ?DateTimeInterface $expiresAt = null): ?string
    {
        return $this->temporaryUrl($this->cardPath($product), $expiresAt);
    }

    public function detailUrl(Product $product, ?DateTimeInterface $expiresAt = null): ?string
    {
        return $this->temporaryUrl($this->detailPath($product), $expiresAt);
    }

    private function temporaryUrl(?string $path, ?DateTimeInterface $expiresAt): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('s3');

        if (! $disk->providesTemporaryUrls()) {
            throw new RuntimeException('The product image disk does not support temporary URLs.');
        }

        return $disk->temporaryUrl($path, $expiresAt ?? now()->addMinutes(5));
    }
}
