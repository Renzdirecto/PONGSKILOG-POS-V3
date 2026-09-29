<?php

namespace App\Support;

use App\Models\Product;
use DateTimeInterface;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImages
{
    /** Signed image links are stable for this long (and valid at least this long after they are handed out). */
    public const URL_MINUTES = 30;

    public function __construct(private SignedUrls $signedUrls) {}

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
        $path = $this->cardPath($product);

        return $path === null ? null : $this->signedUrls->one($path, SignedUrls::minutesUntil($expiresAt, self::URL_MINUTES));
    }

    public function safeCardUrl(Product $product, ?DateTimeInterface $expiresAt = null): ?string
    {
        return $this->safeCardUrls([$product], $expiresAt)[$product->getKey()] ?? null;
    }

    /**
     * The card image URLs of a list of Products in one pass (one cache read for the whole list). A Product with an
     * invalid stored path or an unavailable disk gets null (reported), never an error for the whole page.
     *
     * @param  iterable<Product>  $products
     * @return array<string, string|null> Product id => URL
     */
    public function safeCardUrls(iterable $products, ?DateTimeInterface $expiresAt = null): array
    {
        $paths = [];
        $urls = [];
        foreach ($products as $product) {
            $urls[(string) $product->getKey()] = null;
            try {
                $path = $this->cardPath($product);
            } catch (RuntimeException $exception) {
                report($exception);

                continue;
            }
            if ($path !== null) {
                $paths[(string) $product->getKey()] = $path;
            }
        }
        if ($paths === []) {
            return $urls;
        }
        try {
            $signed = $this->signedUrls->many(array_values($paths), SignedUrls::minutesUntil($expiresAt, self::URL_MINUTES));
        } catch (RuntimeException $exception) {
            report($exception);

            return $urls;
        }
        foreach ($paths as $id => $path) {
            $urls[$id] = $signed[$path] ?? null;
        }

        return $urls;
    }

    public function detailUrl(Product $product, ?DateTimeInterface $expiresAt = null): ?string
    {
        $path = $this->detailPath($product);

        return $path === null ? null : $this->signedUrls->one($path, SignedUrls::minutesUntil($expiresAt, self::URL_MINUTES));
    }
}
