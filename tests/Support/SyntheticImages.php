<?php

declare(strict_types=1);

namespace Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Images built in the test process, for the signature capture suite.
 *
 * Generated rather than committed. A committed PNG would be a binary blob nobody reviews, and
 * these are all one-line descriptions of a shape: "2000 by 801 pixels", "not an image at all",
 * "SVG dressed as a data URL". Generating them keeps the intent in the source, and keeps the
 * repository free of binaries whose contents cannot be diffed (AGENTS.md: synthetic fixtures
 * only).
 */
final class SyntheticImages
{
    /** A transparent PNG of the given size, with one visible stroke so it is not empty. */
    public static function pngDataUrl(int $width = 300, int $height = 100): string
    {
        return 'data:image/png;base64,'.base64_encode(self::pngBytes($width, $height));
    }

    public static function pngBytes(int $width = 300, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD could not create a synthetic image.');
        }

        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imageline(
            $image,
            0,
            (int) ($height / 2),
            $width - 1,
            (int) ($height / 2),
            (int) imagecolorallocate($image, 17, 17, 17),
        );

        ob_start();
        imagepng($image);

        // No imagedestroy(): it has had no effect since PHP 8.0 and is deprecated in 8.5.
        // GdImage is garbage collected like any other object.
        return (string) ob_get_clean();
    }

    public static function jpegDataUrl(int $width = 200, int $height = 80): string
    {
        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD could not create a synthetic image.');
        }

        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));

        ob_start();
        imagejpeg($image, null, 80);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    /** A valid SVG document, correctly labelled. Must be refused by name, not sanitized. */
    public static function svgDataUrl(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>fetch("https://attacker.example.test")</script></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Bytes that are not an image, wearing an image content type. */
    public static function notAnImageDataUrl(): string
    {
        return 'data:image/png;base64,'.base64_encode('<html><body>not a picture</body></html>');
    }
}
