<?php

declare(strict_types=1);

namespace Core;

use Closure;

/**
 * Fluent groups:  $router->prefix('admin')->middleware('auth')->name('admin.')->group(function ($router) { ... });
 */
final class RouteGroupBuilder
{
    /** @var array{prefix?: string, middleware?: string[], as?: string} */
    private array $attributes = [];

    public function __construct(private readonly Router $router)
    {
    }

    public function prefix(string $prefix): self
    {
        $this->attributes['prefix'] = $prefix;

        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        $this->attributes['middleware'] = [...($this->attributes['middleware'] ?? []), ...$middleware];

        return $this;
    }

    public function name(string $prefix): self
    {
        $this->attributes['as'] = $prefix;

        return $this;
    }

    public function group(Closure $routes): void
    {
        $this->router->group($this->attributes, $routes);
    }
}
