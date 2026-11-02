<?php

declare(strict_types=1);

namespace Core\Compression;

use Core\Config;
use RuntimeException;

/**
 * Upload compression (config/uploads.php).
 *
 * Images (JPEG, PNG, WebP, GIF, BMP) are decoded and RE-ENCODED with GD:
 *   - resized to fit max_width x max_height (aspect ratio kept)
 *   - saved as WebP (or the original format) at a visually lossless quality
 *   - all metadata (EXIF, GPS location, comments) is stripped
 *   - re-encoding also destroys "polyglot" files that hide PHP/JS inside an image
 *
 * Other files are compressed LOSSLESSLY with the fastest strong algorithm available:
 *   zstd (if the zstd extension is installed) -> Brotli (brotli extension) -> gzip/deflate (zlib, built in).
 * Formats that are already compressed (zip, docx, xlsx, pptx, mp3, mp4, pdf...) are left as they are —
 * compressing them again only wastes CPU and can make them bigger.
 */
final class Compressor
{
    /** Formats that are already compressed internally. */
    private const ALREADY_COMPRESSED = ['zip', 'docx', 'xlsx', 'pptx', 'mp3', 'mp4', 'gz', 'zst', 'br', 'jpg', 'png', 'gif', 'webp', 'pdf'];

    /** File signature + extension for each algorithm. */
    public const ALGORITHMS = ['zstd' => '.zst', 'brotli' => '.br', 'gzip' => '.gz'];

    public static function algorithm(): string
    {
        $preferred = (string) Config::get('uploads.algorithm', 'auto');

        if ($preferred !== 'auto') {
            if (!self::available($preferred)) {
                throw new RuntimeException("Compression algorithm [$preferred] is not available on this server.");
            }

            return $preferred;
        }

        foreach (['zstd', 'brotli', 'gzip'] as $algorithm) {
            if (self::available($algorithm)) {
                return $algorithm;
            }
        }

        throw new RuntimeException('No compression algorithm available (enable the zlib extension).');
    }

    public static function available(string $algorithm): bool
    {
        return match ($algorithm) {
            'zstd' => function_exists('zstd_compress'),
            'brotli' => function_exists('brotli_compress'),
            'gzip' => function_exists('gzencode'),
            default => false,
        };
    }

    public static function shouldCompress(string $extension): bool
    {
        return Config::get('uploads.compress', true) && !in_array(strtolower($extension), self::ALREADY_COMPRESSED, true);
    }

    /**
     * Compress a file in place. Returns the new path (with .zst/.br/.gz added),
     * or the original path when compression would not make it smaller.
     */
    public static function compressFile(string $path): string
    {
        $data = (string) file_get_contents($path);
        $algorithm = self::algorithm();
        $level = (int) Config::get('uploads.level', 0);

        $compressed = match ($algorithm) {
            'zstd' => zstd_compress($data, $level > 0 ? $level : 3),          // level 3: very fast, strong ratio
            'brotli' => brotli_compress($data, $level > 0 ? $level : 5),       // level 5: fast with good ratio
            'gzip' => gzencode($data, $level > 0 ? $level : 6, FORCE_GZIP),    // level 6: zlib's best speed/ratio balance
        };

        if (!is_string($compressed) || strlen($compressed) >= strlen($data)) {
            return $path;
        }

        $target = $path . self::ALGORITHMS[$algorithm];
        if (file_put_contents($target, $compressed, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the compressed file.');
        }
        @unlink($path);

        return $target;
    }

    /** Decompress data based on the file name (.zst / .br / .gz); other names are returned unchanged. */
    public static function decompress(string $data, string $name): string
    {
        $result = match (true) {
            str_ends_with($name, '.zst') => function_exists('zstd_uncompress') ? zstd_uncompress($data) : false,
            str_ends_with($name, '.br') => function_exists('brotli_uncompress') ? brotli_uncompress($data) : false,
            str_ends_with($name, '.gz') => gzdecode($data),
            default => $data,
        };

        if (!is_string($result)) {
            throw new RuntimeException('Could not decompress the file.');
        }

        return $result;
    }

    public static function isCompressedName(string $name): bool
    {
        foreach (self::ALGORITHMS as $extension) {
            if (str_ends_with($name, $extension)) {
                return true;
            }
        }

        return false;
    }

    /** Original name without the compression extension: "report.csv.gz" -> "report.csv" */
    public static function originalName(string $name): string
    {
        foreach (self::ALGORITHMS as $extension) {
            if (str_ends_with($name, $extension)) {
                return substr($name, 0, -strlen($extension));
            }
        }

        return $name;
    }
}
