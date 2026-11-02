<?php

declare(strict_types=1);

namespace Core;

use Closure;

/**
 * A single registered route. Returned by $router->get(...) so you can chain
 * ->name(), ->middleware(), ->where().
 */
final class Route
{
    private ?string $name = null;

    /** @var string[] */
    private array $middleware = [];

    /** @var array<string, string> */
    private array $wheres = [];

    private ?string $regex = null;

    /** @var string[] */
    private array $paramNames = [];

    /**
     * @param string[] $methods
     * @param Closure|array{0: class-string, 1: string}|string $action
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $uri,
        private readonly Closure|array|string $action,
        private readonly Router $router,
        private readonly string $namePrefix = '',
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $this->namePrefix . $name;
        $this->router->registerName($this->name, $this);

        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /** Remove middleware inherited from a group (e.g. ->withoutMiddleware('csrf')). */
    public function withoutMiddleware(string ...$middleware): self
    {
        $this->middleware = array_values(array_diff($this->middleware, $middleware));

        return $this;
    }

    /**
     * Constrain parameters: ->where('id', '[0-9]+') or ->where(['id' => '\d+', 'slug' => '[a-z-]+'])
     *
     * @param string|array<string, string> $name
     */
    public function where(string|array $name, ?string $pattern = null): self
    {
        foreach (is_array($name) ? $name : [$name => (string) $pattern] as $param => $regex) {
            $this->wheres[$param] = $regex;
        }
        $this->regex = null;

        return $this;
    }

    public function whereNumber(string ...$names): self
    {
        return $this->where(array_fill_keys($names, '[0-9]+'));
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return string[] */
    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /** @return Closure|array{0: class-string, 1: string}|string */
    public function getAction(): Closure|array|string
    {
        return $this->action;
    }

    /** @return string[] */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /** @return array<string, string>|null Route parameters if the path matches, otherwise null. */
    public function match(string $path): ?array
    {
        if ($this->regex === null) {
            $this->compile();
        }

        if (!preg_match((string) $this->regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $i => $name) {
            $value = $matches[$i + 1] ?? '';
            if ($value !== '') {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * Build this route's path from parameters: /users/{id} + ['id' => 5] -> /users/5
     *
     * @param array<string, mixed> $params
     */
    public function path(array $params = []): string
    {
        $path = preg_replace_callback('#/?\{(\w+)(\?)?(?::[^}]+)?\}#', static function (array $m) use (&$params): string {
            $optional = ($m[2] ?? '') === '?';
            if (!array_key_exists($m[1], $params)) {
                if ($optional) {
                    return '';
                }
                throw new \InvalidArgumentException("Missing route parameter [{$m[1]}].");
            }
            $value = rawurlencode((string) $params[$m[1]]);
            unset($params[$m[1]]);

            return (str_starts_with($m[0], '/') ? '/' : '') . $value;
        }, $this->uri) ?? $this->uri;

        $path = $path === '' ? '/' : $path;

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    /** "/users/{id:\d+}/{slug?}" -> regex. Inline constraints may not contain "{" or "}". */
    private function compile(): void
    {
        $parts = preg_split('#(/?\{\w+\??(?::[^}]+)?\})#', $this->uri, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        $this->paramNames = [];

        foreach ($parts as $part) {
            if (preg_match('#^(/?)\{(\w+)(\?)?(?::([^}]+))?\}$#', $part, $m)) {
                $this->paramNames[] = $m[2];
                $pattern = $this->wheres[$m[2]] ?? (($m[4] ?? '') !== '' ? $m[4] : '[^/]+');
                $segment = ($m[1] === '/' ? '/' : '') . '(' . $pattern . ')';
                $regex .= ($m[3] ?? '') === '?' ? '(?:' . $segment . ')?' : $segment;
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        $this->regex = '#^' . ($regex === '' ? '/' : $regex) . '$#u';
    }
}
