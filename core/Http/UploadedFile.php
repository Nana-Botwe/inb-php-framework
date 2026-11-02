<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Compression\Compressor;
use Core\Compression\ImageOptimizer;
use Core\Config;
use Core\Storage;
use Core\Support\Str;
use RuntimeException;

/**
 * A file from a form upload: $request->file('avatar').
 *
 * The real type is detected from the file CONTENT (not the name or the browser's claim),
 * and stored files get a random name with an extension derived from that real type.
 * So "shell.php.jpg" or a PHP script renamed to "photo.png" can never be stored as runnable code.
 */
final class UploadedFile
{
    /** Real MIME type => safe extension. Only these types can be stored. */
    public const SAFE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'audio/mpeg' => 'mp3',
        'video/mp4' => 'mp4',
    ];

    private ?string $mime = null;

    /** @var array{original_size: int, size: int}|null */
    private ?array $stored = null;

    /** @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file */
    public function __construct(private readonly array $file, private readonly bool $test = false)
    {
    }

    public function isValid(): bool
    {
        $tmp = (string) ($this->file['tmp_name'] ?? '');

        return ($this->file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && $tmp !== ''
            && ($this->test ? is_file($tmp) : is_uploaded_file($tmp));
    }

    public function error(): int
    {
        return (int) ($this->file['error'] ?? UPLOAD_ERR_NO_FILE);
    }

    /** Original name sent by the browser. Never trust it, never use it as a path. */
    public function getClientOriginalName(): string
    {
        return basename(str_replace('\\', '/', (string) ($this->file['name'] ?? '')));
    }

    public function getClientOriginalExtension(): string
    {
        return strtolower(pathinfo($this->getClientOriginalName(), PATHINFO_EXTENSION));
    }

    public function size(): int
    {
        return (int) ($this->file['size'] ?? 0);
    }

    public function path(): string
    {
        return (string) ($this->file['tmp_name'] ?? '');
    }

    /** MIME type detected from the file's contents. */
    public function mimeType(): string
    {
        if ($this->mime !== null) {
            return $this->mime;
        }

        // If the file can't be read (e.g. an antivirus quarantined it) it is treated as an unknown,
        // disallowed type — the upload is rejected instead of crashing.
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? @finfo_file($finfo, $this->path()) : false;
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return $this->mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /** Safe extension for the real type, or '' if the type is not allowed. */
    public function extension(): string
    {
        return self::SAFE_TYPES[$this->mimeType()] ?? '';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType(), 'image/')
            && $this->extension() !== ''
            && @getimagesize($this->path()) !== false;
    }

    /** Random file name with the safe extension, e.g. "k3J9...x2.png". */
    public function hashName(): string
    {
        return Str::random(40) . '.' . $this->extension();
    }

    /**
     * Store the file and return its path relative to the disk:
     *   $path = $request->file('avatar')->store('avatars', 'public');   // "avatars/k3J9...x2.png"
     */
    public function store(string $directory = '', string $disk = 'local'): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException('The file upload failed (error code ' . $this->error() . ').');
        }

        if ($this->extension() === '') {
            throw new RuntimeException('This file type is not allowed: ' . $this->mimeType());
        }

        $maxKb = (int) Config::get('uploads.max_kb', 10240);
        if ($this->size() > $maxKb * 1024) {
            throw new RuntimeException("The file is larger than the {$maxKb} KB limit.");
        }

        $directory = trim($directory, '/');
        $prefix = $directory === '' ? '' : $directory . '/';
        $storage = Storage::disk($disk);

        // Images: re-encode (resize, strip metadata, WebP) — smaller AND safer.
        if ($this->isImage() && Config::get('uploads.images.optimize', true) && ImageOptimizer::supported()) {
            $optimized = ImageOptimizer::optimize($this->path(), $this->extension());
            $path = $prefix . Str::random(40) . '.' . $optimized['extension'];
            $storage->put($path, $optimized['data']);
            $this->stored = ['original_size' => $this->size(), 'size' => strlen($optimized['data'])];

            return $path;
        }

        $path = $prefix . $this->hashName();
        $storage->putFileFrom($this->path(), $path, !$this->test);

        // Documents: lossless compression (zstd / brotli / gzip), skipped for already-compressed formats.
        // Only on private disks: browsers can't open .gz files directly from public URLs.
        if ($disk !== 'public' && Compressor::shouldCompress($this->extension())) {
            $compressed = Compressor::compressFile($storage->path($path));
            $path = $prefix . basename($compressed);
        }

        $this->stored = ['original_size' => $this->size(), 'size' => $storage->size($path)];

        return $path;
    }

    /** Size before/after the last store() call: ['original_size' => ..., 'size' => ...] */
    public function compressionStats(): ?array
    {
        return $this->stored;
    }
}
