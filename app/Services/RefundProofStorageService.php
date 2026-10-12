<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RefundProofStorageService
{
    private const MAX_DIMENSION = 2560;

    /**
     * Re-encode an uploaded proof image and store the encrypted bytes privately.
     * Re-encoding removes EXIF/GPS metadata and prevents browser-controlled paths.
     */
    public function storeImage(UploadedFile $file, string $directory): array
    {
        $sourcePath = $file->getRealPath();
        $metadata = $sourcePath ? @getimagesize($sourcePath) : false;
        $detectedMime = $sourcePath ? (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) : false;
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];

        if (! is_array($metadata) || ! in_array($detectedMime, $allowed, true)) {
            throw new RuntimeException('The uploaded proof is not a supported image.');
        }

        $image = match ($metadata[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default => false,
        };

        if (! $image) {
            throw new RuntimeException('The proof image could not be decoded safely.');
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::MAX_DIMENSION / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            imagewebp($canvas, null, 88);
            $bytes = ob_get_clean();
            imagedestroy($canvas);
        } finally {
            imagedestroy($image);
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The proof image could not be re-encoded safely.');
        }

        return $this->storeEncrypted($bytes, $directory, 'image/webp', $file->getClientOriginalName());
    }

    public function storeFile(UploadedFile $file, string $directory, array $allowedMimes): array
    {
        $path = $file->getRealPath();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('The uploaded evidence content is invalid.');
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The uploaded evidence could not be read.');
        }

        return $this->storeEncrypted($bytes, $directory, $mime, $file->getClientOriginalName());
    }

    public function read(string $disk, string $path): string
    {
        $payload = Storage::disk($disk)->get($path);
        if (! is_string($payload)) {
            throw new RuntimeException('The protected evidence is unavailable.');
        }

        return Crypt::decryptString($payload);
    }

    private function storeEncrypted(string $bytes, string $directory, string $mime, ?string $originalName): array
    {
        $path = trim($directory, '/').'/'.Str::uuid().'.enc';
        $stored = Storage::disk('local')->put($path, Crypt::encryptString($bytes), ['visibility' => 'private']);
        if (! $stored) {
            throw new RuntimeException('The protected evidence could not be stored.');
        }

        return [
            'disk' => 'local',
            'path' => $path,
            'mime' => $mime,
            'original_name' => $originalName,
            'encrypted' => true,
        ];
    }
}
