<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stable signed object-storage URLs (Phase 20 performance). A presigned URL differs on every signing, so a screen that
 * refetches its catalog after every sale re-downloaded every image. Here the URL of an object is signed once per time
 * window and reused by every response in that window — same URL, so the browser cache works — and it stays valid for
 * at least one more window after it is handed out. A whole list is resolved with one cache read and at most one write.
 */
class SignedUrls
{
    /**
     * @param  list<string>  $paths
     * @return array<string, string> path => signed URL
     *
     * @throws RuntimeException when the disk cannot sign URLs
     */
    public function many(array $paths, int $minutes): array
    {
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return [];
        }
        $disk = Storage::disk('s3');
        if (! $disk->providesTemporaryUrls()) {
            throw new RuntimeException('The image disk does not support temporary URLs.');
        }
        $window = max(1, $minutes) * 60;
        $index = intdiv(CarbonImmutable::now()->getTimestamp(), $window);
        $expiresAt = CarbonImmutable::createFromTimestamp(($index + 2) * $window);
        $keys = [];
        foreach ($paths as $path) {
            $keys[$path] = 'signed-url:'.sha1($path).':'.$window.':'.$index;
        }
        $cached = Cache::many(array_values($keys));
        $urls = [];
        $fresh = [];
        foreach ($keys as $path => $key) {
            $url = $cached[$key] ?? null;
            if (! is_string($url)) {
                $url = $disk->temporaryUrl($path, $expiresAt);
                $fresh[$key] = $url;
            }
            $urls[$path] = $url;
        }
        if ($fresh !== []) {
            Cache::putMany($fresh, CarbonImmutable::createFromTimestamp(($index + 1) * $window));
        }

        return $urls;
    }

    public function one(string $path, int $minutes): string
    {
        return $this->many([$path], $minutes)[$path];
    }

    /** Whole minutes from now until `$expiresAt` (at least one), for callers that think in expiry times. */
    public static function minutesUntil(?\DateTimeInterface $expiresAt, int $default): int
    {
        if ($expiresAt === null) {
            return $default;
        }

        return max(1, (int) ceil(($expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp()) / 60));
    }
}
