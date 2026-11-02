<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * File storage on "disks" defined in config/filesystems.php:
 *   local  -> storage/app/private  (NOT web-accessible; serve through a controller with auth checks)
 *   public -> public/uploads       (web-accessible; PHP execution is disabled there by .htaccess)
 *
 *   Storage::disk('public')->put('reports/a.txt', 'hello');
 *   Storage::disk('public')->url('reports/a.txt');
 *   Storage::get('invoices/1.pdf');   // default disk
 */
final class Storage
{
    /** @var array<string, self> */
    private static array $disks = [];

    private function __construct(private readonly string $root, private readonly ?string $url)
    {
    }

    public static function disk(?string $name = null): self
    {
        $name ??= (string) Config::get('filesystems.default', 'local');

        if (!isset(self::$disks[$name])) {
            $config = Config::get("filesystems.disks.$name") ?? throw new RuntimeException("Disk [$name] is not configured.");
            self::$disks[$name] = new self(rtrim((string) $config['root'], '/\\'), $config['url'] ?? null);
        }

        return self::$disks[$name];
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::disk()->{$method}(...$arguments);
    }

    public function path(string $path = ''): string
    {
        return $this->root . ($path === '' ? '' : '/' . $this->safe($path));
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function get(string $path): ?string
    {
        $file = $this->path($path);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** Contents with upload compression (.gz/.br/.zst) undone. */
    public function read(string $path): ?string
    {
        $data = $this->get($path);

        return $data === null ? null : Compression\Compressor::decompress($data, $path);
    }

    public function put(string $path, string $contents): bool
    {
        $file = $this->path($path);
        $this->ensureDirectory(dirname($file));

        return file_put_contents($file, $contents, LOCK_EX) !== false;
    }

    /** @internal Used by UploadedFile::store(). */
    public function putFileFrom(string $source, string $path, bool $isUpload = true): void
    {
        $target = $this->path($path);
        $this->ensureDirectory(dirname($target));

        $ok = $isUpload ? move_uploaded_file($source, $target) : copy($source, $target);
        if (!$ok) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        @chmod($target, 0644);
    }

    public function delete(string ...$paths): bool
    {
        $ok = true;
        foreach ($paths as $path) {
            $file = $this->path($path);
            if (is_file($file)) {
                $ok = @unlink($file) && $ok;
            }
        }

        return $ok;
    }

    public function size(string $path): int
    {
        return (int) @filesize($this->path($path));
    }

    /** @return string[] Files in a directory (relative paths). */
    public function files(string $directory = ''): array
    {
        $dir = $this->path($directory);
        $files = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $files[] = ltrim(substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $this->root))), '/');
            }
        }

        return $files;
    }

    /** Public URL (only for disks with a "url"). */
    public function url(string $path): string
    {
        if ($this->url === null) {
            throw new RuntimeException('This disk is private and has no public URL.');
        }

        return url(rtrim($this->url, '/') . '/' . $this->safe($path));
    }

    /** Send a stored file as a download (e.g. from a controller after checking permissions). */
    public function download(string $path, ?string $name = null): Response
    {
        $file = $this->path($path);
        if (!is_file($file)) {
            throw new HttpException(404);
        }

        // Files compressed on upload are decompressed transparently.
        $contents = Compression\Compressor::decompress((string) file_get_contents($file), $file);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name ?? Compression\Compressor::originalName(basename($file)));

        return (new Response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /** Reject absolute paths and "../" so a path can never leave the disk. */
    private function safe(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || str_contains($part, "\0") || str_contains($part, ':')) {
                throw new RuntimeException("Unsafe storage path [$path].");
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory [$dir].");
        }
    }
}
