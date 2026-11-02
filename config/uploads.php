<?php

declare(strict_types=1);

return [
    // Hard upload limit in KB (also set upload_max_filesize / post_max_size in php.ini).
    'max_kb' => (int) env('UPLOAD_MAX_KB', 10240),

    // Lossless compression of documents stored on private disks.
    'compress' => (bool) env('UPLOAD_COMPRESS', true),

    // auto = best available: zstd -> brotli -> gzip. Or force one of them.
    'algorithm' => env('UPLOAD_ALGORITHM', 'auto'),

    // 0 = recommended level for the algorithm (zstd 3, brotli 5, gzip 6: fast with strong ratio).
    'level' => 0,

    'images' => [
        // Re-encode images: resize, strip EXIF/GPS metadata, remove hidden payloads.
        'optimize' => true,
        // webp (smallest) | jpg | png | original
        'format' => env('UPLOAD_IMAGE_FORMAT', 'webp'),
        // 82 is visually lossless for photos.
        'quality' => 82,
        'max_width' => 1920,
        'max_height' => 1920,
        // Refuse "decompression bomb" images bigger than this many pixels.
        'max_pixels' => 40_000_000,
    ],
];
