<?php

declare(strict_types=1);

/**
 * Generate lightweight WebP versions of the static landing-page photography.
 *
 * Run from the project root:
 * php scripts/optimize-landing-images.php
 */

if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
    fwrite(STDERR, "PHP GD with WebP support is required.\n");
    exit(1);
}

$images = [
    'Warm Mediterranean Still Life with Terracotta Accents.png',
    'Minimalist Workspace with City Views.png',
    'Sunlit Sage Green Lifestyle Vignette.png',
    'hero-bg.png',
    'hero-marketplace.png',
];

$imageDirectory = dirname(__DIR__).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images';

foreach ($images as $filename) {
    $sourcePath = $imageDirectory.DIRECTORY_SEPARATOR.$filename;
    $destinationPath = preg_replace('/\.png$/i', '.webp', $sourcePath);
    $image = imagecreatefrompng($sourcePath);

    if ($image === false || $destinationPath === null || ! imagewebp($image, $destinationPath, 78)) {
        fwrite(STDERR, "Unable to optimize {$filename}.\n");
        exit(1);
    }

    imagedestroy($image);
    printf("%s: %.1f KB -> %.1f KB\n", $filename, filesize($sourcePath) / 1024, filesize($destinationPath) / 1024);
}
