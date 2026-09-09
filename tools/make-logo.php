<?php
/**
 * Generates the placeholder module logo (onepaycx/logo.png, 32x32) with GD.
 * Replace logo.png with real artwork whenever one is available; PrestaShop
 * only needs a 32x32 PNG at the module root.
 *
 * Usage: php tools/make-logo.php
 *
 * @license MIT
 */

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "PHP GD is required to generate the placeholder logo.\n");
    exit(1);
}

$out = dirname(__DIR__) . '/onepaycx/logo.png';
$size = 32;

$im = imagecreatetruecolor($size, $size);
imagesavealpha($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);

$bg = imagecolorallocate($im, 17, 24, 39);    // near-black
$fg = imagecolorallocate($im, 74, 222, 128);  // green accent

// Rounded-ish square: a filled rectangle with the corners knocked out.
imagefilledrectangle($im, 2, 2, $size - 3, $size - 3, $bg);
foreach (array(array(2, 2), array($size - 3, 2), array(2, $size - 3), array($size - 3, $size - 3)) as $c) {
    imagesetpixel($im, $c[0], $c[1], $transparent);
}

// A bold "1" drawn from rectangles (no font dependency).
imagefilledrectangle($im, 14, 7, 18, 24, $fg);   // stem
imagefilledrectangle($im, 10, 9, 14, 12, $fg);   // flag
imagefilledrectangle($im, 10, 22, 22, 24, $fg);  // base

if (!imagepng($im, $out)) {
    fwrite(STDERR, "Could not write $out\n");
    exit(1);
}
imagedestroy($im);
echo "wrote $out\n";
