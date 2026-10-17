<?php

namespace App\Services\Moderation;

use App\Models\Seller\ProductImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductModerationImagePreprocessor
{
    /** @return array<string, mixed> */
    public function prepare(ProductImage $image): array
    {
        $path = trim((string) $image->file_path);
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
        }

        try {
            $bytes = Storage::disk('public')->get($path);
        } catch (Throwable) {
            throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
        }
        $maxBytes = (int) config('product_moderation.images.max_bytes', 5_242_880);
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > $maxBytes) {
            throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ProductModerationProviderException('AI_UNSUPPORTED_IMAGE', false);
        }

        $dimensions = @getimagesizefromstring($bytes);
        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
            throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
        }
        [$width, $height] = [(int) $dimensions[0], (int) $dimensions[1]];
        if ($width < 1 || $height < 1 || $width * $height > (int) config('product_moderation.images.max_pixels', 40_000_000)) {
            throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            throw new ProductModerationProviderException('AI_IMAGE_PROCESSOR_UNAVAILABLE', false);
        }

        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
        }

        try {
            $maxDimension = (int) config('product_moderation.images.max_dimension', 1280);
            $scale = min(1, $maxDimension / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($resized === false) {
                throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
            }
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            imagecopyresampled($resized, $decoded, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            $written = imagejpeg($resized, null, 82);
            $normalized = ob_get_clean();
            imagedestroy($resized);
            if (! $written || ! is_string($normalized) || strlen($normalized) > 1_500_000) {
                throw new ProductModerationProviderException('AI_INVALID_IMAGE', false);
            }
        } finally {
            imagedestroy($decoded);
        }

        return [
            'image_id' => (int) $image->id,
            'bytes' => $normalized,
            'mime_type' => 'image/jpeg',
            'sha256' => hash('sha256', $bytes),
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }
}
