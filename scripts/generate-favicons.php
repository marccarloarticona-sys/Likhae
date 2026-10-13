<?php

declare(strict_types=1);

$sourcePath = dirname(__DIR__).'/public/images/buyer/likhae-ai-logo.png';
$source = imagecreatefrompng($sourcePath);

if ($source === false) {
    fwrite(STDERR, "Unable to load the LIKHAE AI logo.\n");
    exit(1);
}

foreach ([16, 32, 180] as $size) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);

    // A tight crop makes the symbol remain legible in a small browser tab.
    $padding = max(1, (int) round($size * 0.11));
    imagecopyresampled(
        $canvas,
        $source,
        $padding,
        $padding,
        0,
        0,
        $size - ($padding * 2),
        $size - ($padding * 2),
        imagesx($source),
        imagesy($source),
    );

    // Use a brighter LIKHAE maroon so the mark stays distinct at favicon size.
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $pixel = imagecolorsforindex($canvas, imagecolorat($canvas, $x, $y));
            if ($pixel['alpha'] < 127) {
                imagesetpixel($canvas, $x, $y, imagecolorallocatealpha($canvas, 178, 52, 45, $pixel['alpha']));
            }
        }
    }

    $whiteCanvas = imagecreatetruecolor($size, $size);
    imagealphablending($whiteCanvas, false);
    imagesavealpha($whiteCanvas, true);
    imagefill($whiteCanvas, 0, 0, imagecolorallocatealpha($whiteCanvas, 0, 0, 0, 127));
    imageantialias($whiteCanvas, true);
    imagefilledellipse(
        $whiteCanvas,
        (int) floor($size / 2),
        (int) floor($size / 2),
        $size - 1,
        $size - 1,
        imagecolorallocate($whiteCanvas, 255, 255, 255),
    );
    imagealphablending($whiteCanvas, true);
    imagecopy($whiteCanvas, $canvas, 0, 0, 0, 0, $size, $size);
    imagedestroy($canvas);
    $canvas = $whiteCanvas;

    imagepng($canvas, dirname(__DIR__)."/public/favicon-{$size}x{$size}.png", 9);
    imagedestroy($canvas);
}

imagedestroy($source);
