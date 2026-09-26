<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CustomerScreenMedia;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Branch advertisement media for customer screens (Phase 19.6A), on the same private `s3` disk and signed-URL model as
 * Product images. Paths are generated here (`customer-screen/{branch}/{uuid}/display.webp|video.mp4`); a client file
 * name, path or MIME claim is never stored or trusted.
 *
 * - Images (JPEG/PNG/WebP ≤ 10 MB) are decoded and re-encoded as one WebP ≤ 1920 px by `ProductImageProcessor`, so a
 *   malformed or disguised file is rejected and the screen never downloads a giant original.
 * - Videos are MP4 only (the one format every target browser plays), ≤ 50 MB and ≤ 60 seconds. The container is
 *   checked for an `ftyp` signature and its `mvhd` duration is read from the file itself; HEVC-only files are
 *   rejected because many browsers cannot play them. There is no transcoding pipeline: export H.264 MP4.
 */
class CustomerScreenMediaLibrary
{
    public const MAX_ITEMS = 30;

    public const IMAGE_MAX_KILOBYTES = 10240;

    public const IMAGE_BOUND = 1920;

    public const VIDEO_MAX_KILOBYTES = 51200;

    public const VIDEO_MAX_SECONDS = 60;

    public const IMAGE_DEFAULT_SECONDS = 8;

    public const URL_MINUTES = 60;

    public function __construct(private ProductImageProcessor $images) {}

    /**
     * Validates and stores an upload; returns the attributes of the new media row (not yet saved).
     *
     * @return array{media_type: 'image'|'video', path: string, mime_type: string, size_bytes: int, duration_seconds: int|null}
     */
    public function store(Branch $branch, UploadedFile $upload): array
    {
        /** Decided from the file's own bytes (finfo), never from the client's name or Content-Type. */
        $mime = (string) $upload->getMimeType();
        $isVideo = str_starts_with($mime, 'video/') || $mime === 'application/mp4';
        $directory = 'customer-screen/'.$branch->getKey().'/'.Str::uuid();

        if ($isVideo) {
            $duration = $this->validateVideo($upload);
            $path = $directory.'/video.mp4';
            $stream = fopen($upload->getRealPath(), 'rb');
            if ($stream === false) {
                throw new RuntimeException('Could not read the uploaded video.');
            }
            try {
                $stored = Storage::disk('s3')->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $stored) {
                throw new RuntimeException('Could not store the advertisement video.');
            }

            return ['media_type' => 'video', 'path' => $path, 'mime_type' => 'video/mp4', 'size_bytes' => (int) $upload->getSize(), 'duration_seconds' => $duration];
        }

        try {
            $contents = $this->images->rendition($upload, self::IMAGE_BOUND, self::IMAGE_MAX_KILOBYTES);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['file' => $exception->validator->errors()->all()]);
        }
        $path = $directory.'/display.webp';
        if (! Storage::disk('s3')->put($path, $contents)) {
            throw new RuntimeException('Could not store the advertisement image.');
        }

        return ['media_type' => 'image', 'path' => $path, 'mime_type' => 'image/webp', 'size_bytes' => strlen($contents), 'duration_seconds' => null];
    }

    public function delete(string $path): void
    {
        rescue(fn () => Storage::disk('s3')->deleteDirectory(dirname($path)));
    }

    public function url(CustomerScreenMedia $media, DateTimeInterface $expiresAt): ?string
    {
        if (preg_match('#\Acustomer-screen/'.preg_quote($media->branch_id, '#').'/[0-9a-f-]{36}/(display\.webp|video\.mp4)\z#', $media->path) !== 1) {
            report(new RuntimeException('Invalid customer screen media path.'));

            return null;
        }

        try {
            $disk = Storage::disk('s3');

            return $disk->providesTemporaryUrls() ? $disk->temporaryUrl($media->path, $expiresAt) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The playlist of a Branch's screens: active media in their sequence, customer-safe fields only.
     *
     * @return array{items: list<array{key: string, type: 'image'|'video', url: string, duration_ms: int}>, expires_at: string}
     */
    public function playlist(Branch $branch): array
    {
        $expiresAt = now()->addMinutes(self::URL_MINUTES);
        $items = [];
        foreach (CustomerScreenMedia::query()->where('branch_id', $branch->getKey())->where('is_active', true)->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->limit(self::MAX_ITEMS)->get() as $media) {
            $url = $this->url($media, $expiresAt);
            if ($url === null) {
                continue;
            }
            $items[] = [
                'key' => substr(hash('sha256', $media->id.'|'.$media->updated_at?->toIso8601String()), 0, 16),
                'type' => $media->media_type,
                'url' => $url,
                'duration_ms' => $media->duration_seconds * 1000,
            ];
        }

        return ['items' => $items, 'expires_at' => $expiresAt->toIso8601String()];
    }

    private function validateVideo(UploadedFile $upload): int
    {
        Validator::make(['file' => $upload], [
            'file' => ['bail', 'required', 'file', 'max:'.self::VIDEO_MAX_KILOBYTES, 'mimes:mp4', 'mimetypes:video/mp4,application/mp4'],
        ], [
            'file.max' => 'Videos can be at most 50 MB.',
            'file.mimes' => 'Upload the video as an MP4 (H.264) file.',
            'file.mimetypes' => 'Upload the video as an MP4 (H.264) file.',
        ])->validate();

        $inspection = $this->inspectMp4((string) $upload->getRealPath());
        if ($inspection === null) {
            throw ValidationException::withMessages(['file' => 'The video could not be read. Export it as an MP4 (H.264) file and try again.']);
        }
        if ($inspection['hevc_only']) {
            throw ValidationException::withMessages(['file' => 'HEVC (H.265) videos do not play on every screen. Export the video as H.264 MP4.']);
        }
        if ($inspection['seconds'] > self::VIDEO_MAX_SECONDS) {
            throw ValidationException::withMessages(['file' => 'Videos can be at most 60 seconds long.']);
        }

        return max(1, (int) ceil($inspection['seconds']));
    }

    /**
     * Reads the ISO-BMFF box structure: `ftyp` first, then `moov` → `mvhd` (timescale and duration). Bounded reads only.
     *
     * @return array{seconds: float, hevc_only: bool}|null
     */
    public function inspectMp4(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $size = (int) (fstat($handle)['size'] ?? 0);
            $header = fread($handle, 12);
            if (! is_string($header) || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
                return null;
            }
            $offset = 0;
            while ($offset + 8 <= $size) {
                fseek($handle, $offset);
                $box = fread($handle, 16);
                if (! is_string($box) || strlen($box) < 8) {
                    return null;
                }
                $boxSize = $this->uint32($box, 0);
                $type = substr($box, 4, 4);
                $headerSize = 8;
                if ($boxSize === 1) {
                    if (strlen($box) < 16) {
                        return null;
                    }
                    $boxSize = $this->uint64($box, 8);
                    $headerSize = 16;
                } elseif ($boxSize === 0) {
                    $boxSize = $size - $offset;
                }
                if ($boxSize < $headerSize || $boxSize > $size - $offset) {
                    return null;
                }
                if ($type === 'moov') {
                    $length = min($boxSize - $headerSize, 16 * 1024 * 1024);
                    fseek($handle, $offset + $headerSize);
                    $moov = $length > 0 ? fread($handle, $length) : '';

                    return is_string($moov) ? $this->movieHeader($moov) : null;
                }
                $offset += $boxSize;
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /** A big-endian unsigned 32-bit value, or -1 when the bytes are not there. */
    private function uint32(string $bytes, int $offset): int
    {
        $value = strlen($bytes) >= $offset + 4 ? unpack('N', $bytes, $offset) : false;

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : -1;
    }

    /** A big-endian 64-bit value (negative when it does not fit a signed integer or the bytes are not there). */
    private function uint64(string $bytes, int $offset): int
    {
        $value = strlen($bytes) >= $offset + 8 ? unpack('J', $bytes, $offset) : false;

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : -1;
    }

    /** @return array{seconds: float, hevc_only: bool}|null */
    private function movieHeader(string $moov): ?array
    {
        $position = 0;
        $length = strlen($moov);
        while ($position + 8 <= $length) {
            $boxSize = $this->uint32($moov, $position);
            if (substr($moov, $position + 4, 4) === 'mvhd' && $boxSize >= 32 && $position + $boxSize <= $length) {
                $version = ord($moov[$position + 8]);
                if ($version === 1) {
                    $timescale = $this->uint32($moov, $position + 28);
                    $duration = $this->uint64($moov, $position + 32);
                } else {
                    $timescale = $this->uint32($moov, $position + 20);
                    $duration = $this->uint32($moov, $position + 24);
                }
                if ($timescale <= 0 || $duration < 0) {
                    return null;
                }
                $hevc = str_contains($moov, 'hvc1') || str_contains($moov, 'hev1');

                return ['seconds' => $duration / $timescale, 'hevc_only' => $hevc && ! str_contains($moov, 'avc1')];
            }
            if ($boxSize < 8) {
                return null;
            }
            $position += $boxSize;
        }

        return null;
    }
}
