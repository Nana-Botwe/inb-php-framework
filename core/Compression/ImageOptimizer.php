<?php

declare(strict_types=1);

namespace Core\Compression;

use Core\Config;
use GdImage;
use RuntimeException;

/**
 * Re-encodes an uploaded image: resize, strip metadata, convert to WebP (or keep the format).
 * Typical result: photos from phones shrink by 70–95% with no visible difference.
 */
final class ImageOptimizer
{
    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /**
     * @return array{data: string, extension: string, width: int, height: int}
     */
    public static function optimize(string $sourcePath, string $originalExtension): array
    {
        if (!self::supported()) {
            throw new RuntimeException('The GD extension is required for image optimisation.');
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new RuntimeException('The file is not a valid image.');
        }

        [$width, $height] = $info;
        $maxPixels = (int) Config::get('uploads.images.max_pixels', 40_000_000);
        if ($width * $height > $maxPixels) {
            // Protects against "decompression bomb" images that would exhaust memory.
            throw new RuntimeException('The image dimensions are too large.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($sourcePath));
        if (!$image instanceof GdImage) {
            throw new RuntimeException('The image could not be decoded.');
        }

        $image = self::applyExifOrientation($image, $sourcePath, $originalExtension);
        $image = self::resize($image);

        $format = (string) Config::get('uploads.images.format', 'webp');
        if ($format === 'original') {
            $format = in_array($originalExtension, ['jpg', 'png', 'webp', 'gif'], true) ? $originalExtension : 'jpg';
        }
        if ($format === 'webp' && !function_exists('imagewebp')) {
            $format = 'jpg';
        }

        $quality = (int) Config::get('uploads.images.quality', 82);

        ob_start();
        $ok = match ($format) {
            'webp' => imagewebp($image, null, $quality),
            'png' => self::png($image),
            'gif' => imagegif($image),
            default => self::jpeg($image, $quality),
        };
        $data = (string) ob_get_clean();

        $result = ['data' => $data, 'extension' => $format, 'width' => imagesx($image), 'height' => imagesy($image)];
        imagedestroy($image);

        if (!$ok || $data === '') {
            throw new RuntimeException('The image could not be encoded.');
        }

        return $result;
    }

    private static function resize(GdImage $image): GdImage
    {
        $maxWidth = (int) Config::get('uploads.images.max_width', 1920);
        $maxHeight = (int) Config::get('uploads.images.max_height', 1920);
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));
        if ($scale >= 1) {
            return $image;
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /** Phone photos are often stored sideways with an EXIF "rotate" flag; apply it before stripping EXIF. */
    private static function applyExifOrientation(GdImage $image, string $path, string $extension): GdImage
    {
        if ($extension !== 'jpg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private static function jpeg(GdImage $image, int $quality): bool
    {
        imageinterlace($image, true); // progressive JPEG: loads faster on slow networks

        return imagejpeg($image, null, $quality);
    }

    private static function png(GdImage $image): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, null, 9, PNG_ALL_FILTERS);
    }
}
