<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProductImageProcessor
{
    /** @return array{extension: string, source: string, card: string, detail: string} */
    public function process(UploadedFile $upload): array
    {
        Validator::make(['image' => $upload], [
            'image' => ['bail', 'required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:max_width=6000,max_height=6000'],
        ])->validate();

        if (! extension_loaded('gd') || ! (imagetypes() & IMG_JPG)
            || ! (imagetypes() & IMG_PNG) || ! (imagetypes() & IMG_WEBP)) {
            throw new RuntimeException('Product images require GD with JPEG, PNG and WebP support.');
        }

        $contents = $upload->getContent();
        $dimensions = getimagesizefromstring($contents);

        if ($dimensions === false) {
            throw ValidationException::withMessages(['image' => 'The image is malformed or corrupted.']);
        }

        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        $estimatedMemory = $dimensions[0] * $dimensions[1] * 8 + 32 * 1024 * 1024;

        if ($memoryLimit > 0 && $estimatedMemory > $memoryLimit - memory_get_usage()) {
            throw ValidationException::withMessages(['image' => 'The image is too large to process safely. Please reduce its dimensions.']);
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw ValidationException::withMessages(['image' => 'The image is malformed or corrupted.']);
        });

        try {
            $source = imagecreatefromstring($contents);
        } finally {
            restore_error_handler();
        }

        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'The image is malformed or corrupted.']);
        }

        try {
            return [
                'extension' => match ($dimensions[2]) {
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_WEBP => 'webp',
                    default => throw ValidationException::withMessages(['image' => 'The image format is not supported.']),
                },
                'source' => $contents,
                'card' => $this->webp($source, 480),
                'detail' => $this->webp($source, 1200),
            ];
        } finally {
            unset($source);
        }
    }

    private function webp(GdImage $source, int $bound): string
    {
        $scale = min(1, $bound / imagesx($source), $bound / imagesy($source));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $variant = imagecreatetruecolor($width, $height);

        if ($variant === false) {
            throw new RuntimeException('Could not allocate the product image variant.');
        }

        ob_start();

        try {
            imagealphablending($variant, false);
            imagesavealpha($variant, true);

            if (! imagecopyresampled($variant, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))
                || ! imagewebp($variant, null, 84)) {
                throw new RuntimeException('Could not encode the product image variant.');
            }

            $contents = ob_get_contents();

            if ($contents === false || $contents === '' || getimagesizefromstring($contents) === false) {
                throw new RuntimeException('The encoded product image variant is invalid.');
            }

            return $contents;
        } finally {
            ob_end_clean();
            unset($variant);
        }
    }
}
