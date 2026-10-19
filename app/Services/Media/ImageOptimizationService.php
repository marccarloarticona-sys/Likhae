<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageOptimizationService
{
    private const MAX_DIMENSION = 1920;
    private const MAX_PASSTHROUGH_WEBP_BYTES = 786432;

    /**
     * Store a browser-uploaded image as an optimized WebP file when possible.
     *
     * Images that are already smaller than their optimized version, or cannot
     * be decoded by GD, are retained unchanged so uploads never fail merely
     * because optimization is unavailable.
     */
    public function store(UploadedFile $image, string $directory, string $disk = 'public'): string
    {
        if (strtolower($image->getClientOriginalExtension()) === 'webp'
            && (int) $image->getSize() <= self::MAX_PASSTHROUGH_WEBP_BYTES) {
            $path = $image->getRealPath();
            $metadata = $path ? @getimagesize($path) : false;

            if (($metadata['mime'] ?? null) === 'image/webp'
                && max((int) ($metadata[0] ?? 0), (int) ($metadata[1] ?? 0)) <= self::MAX_DIMENSION) {
                return $this->storeOriginal($image, $directory, $disk);
            }
        }

        $source = $this->decode($image);
        if (! $source) {
            return $this->storeOriginal($image, $directory, $disk);
        }

        $optimized = null;

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::MAX_DIMENSION / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            foreach ([82, 72, 62] as $quality) {
                ob_start();
                $written = imagewebp($canvas, null, $quality);
                $encoded = ob_get_clean();

                if ($written && is_string($encoded) && $encoded !== '' && strlen($encoded) < (int) $image->getSize()) {
                    $optimized = $encoded;
                    break;
                }
            }

            imagedestroy($canvas);
        } finally {
            imagedestroy($source);
        }

        if (! $optimized) {
            return $this->storeOriginal($image, $directory, $disk);
        }

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';
        $stored = Storage::disk($disk)->put($path, $optimized, [
            'visibility' => $disk === 'public' ? 'public' : 'private',
        ]);

        return $stored ? $path : $this->storeOriginal($image, $directory, $disk);
    }

    private function decode(UploadedFile $image): \GdImage|false
    {
        // GD is optional: keep the upload working and store the original when
        // image decoding/resizing/WebP encoding is unavailable in this PHP runtime.
        if (! function_exists('imagecreatetruecolor')
            || ! function_exists('imagecopyresampled')
            || ! function_exists('imagewebp')) {
            return false;
        }

        $path = $image->getRealPath();
        $metadata = $path ? @getimagesize($path) : false;
        $type = $metadata[2] ?? false;

        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function storeOriginal(UploadedFile $image, string $directory, string $disk): string
    {
        $path = $disk === 'public'
            ? $image->storePublicly($directory, $disk)
            : $image->store($directory, $disk);

        if (! is_string($path)) {
            throw new RuntimeException('The image could not be stored.');
        }

        return $path;
    }
}
