<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CustomerReviewImages
{
    public function __construct(private ProductImageProcessor $processor) {}

    /** @return array{path: string, kind: string, width: int, height: int, bytes: int} */
    public function store(UploadedFile $upload, string $id, string $kind): array
    {
        $bytes = $this->processor->rendition($upload, $kind === 'profile' ? 256 : 1200, 5120);
        $dimensions = getimagesizefromstring($bytes);
        if ($dimensions === false) {
            throw new RuntimeException('Review image encoding failed.');
        }
        $path = 'reviews/'.$id.'/'.Str::uuid().'.webp';
        if (! Storage::disk('s3')->put($path, $bytes, ['visibility' => 'private', 'ContentType' => 'image/webp', 'CacheControl' => 'private, no-store'])) {
            throw new RuntimeException('Review image storage failed.');
        }

        return ['path' => $path, 'kind' => $kind, 'width' => $dimensions[0], 'height' => $dimensions[1], 'bytes' => strlen($bytes)];
    }

    public function deleteDirectory(string $id): bool
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid review asset identifier.');
        }
        try {
            if (Storage::disk('s3')->deleteDirectory('reviews/'.$id)) {
                return true;
            }
        } catch (Throwable) {
            // The orphan sweep retries cleanup without customer data or provider credentials in logs.
        }
        Log::warning('Review image cleanup deferred to the orphan sweep.');

        return false;
    }
}
