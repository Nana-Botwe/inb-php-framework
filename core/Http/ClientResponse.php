<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Support\Arr;

final class ClientResponse
{
    private mixed $decoded = null;

    private bool $parsed = false;

    public function __construct(private readonly int $status, private readonly string $body)
    {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    /** Decoded JSON, or one value by dot path: ->json('data.authorization_url') */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->parsed) {
            $this->decoded = json_decode($this->body, true);
            $this->parsed = true;
        }

        return $key === null ? ($this->decoded ?? $default) : Arr::get($this->decoded, $key, $default);
    }
}
