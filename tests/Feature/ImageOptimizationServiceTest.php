<?php

namespace Tests\Feature;

use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizationServiceTest extends TestCase
{
    public function test_it_resizes_and_reencodes_uploaded_images_as_webp(): void
    {
        Storage::fake('public');

        $path = app(ImageOptimizationService::class)->store(
            UploadedFile::fake()->image('camera-photo.jpg', 3000, 2000)->size(5000),
            'optimized-images'
        );

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.webp', $path);

        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertLessThanOrEqual(1920, max($width, $height));
    }

    public function test_it_stores_the_original_when_there_is_not_enough_memory_to_optimize(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('identity.jpg', 1200, 1200)->size(500);
        $originalLimit = (string) ini_get('memory_limit');
        $constrainedLimit = (string) (memory_get_usage(false) + 8 * 1024 * 1024);

        try {
            ini_set('memory_limit', $constrainedLimit);
            $path = app(ImageOptimizationService::class)->store($image, 'optimized-images');
        } finally {
            ini_set('memory_limit', $originalLimit);
        }

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.jpg', $path);
    }
}
