<?php

declare(strict_types=1);

namespace Core;

use Closure;
use Core\Middleware\Middleware;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Explicit routes (routes/web.php, routes/api.php) — only listed URLs exist.
 *
 *   $router->get('/', [HomeController::class, 'index'])->name('home');
 *   $router->get('/posts/{post}', [PostController::class, 'show']);      // show(Post $post) is auto-loaded (404 if missing)
 *   $router->resource('photos', PhotoController::class);                  // 7 CRUD routes
 *   $router->group(['prefix' => '/admin', 'middleware' => ['auth', 'role:admin'], 'as' => 'admin.'], fn ($r) => ...);
 *   $router->view('/terms', 'pages.terms');   $router->redirect('/old', '/new', 301);
 *   $router->fallback(fn () => abort(404));
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var array<int, array{prefix: string, middleware: string[], as: string}> */
    private array $groupStack = [];

    private ?Route $fallback = null;

    private ?Route $current = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function get(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['GET', 'HEAD'], $uri, $action);
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function post(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['POST'], $uri, $action);
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function put(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['PUT'], $uri, $action);
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function patch(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['PATCH'], $uri, $action);
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function delete(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['DELETE'], $uri, $action);
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function options(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['OPTIONS'], $uri, $action);
    }

    /** @param Closure|array{0: class-string, 1: string}|string $action */
    public function any(string $uri, Closure|array|string $action): Route
    {
        return $this->add(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, $action);
    }

    /**
     * @param string[] $methods
     * @param Closure|array{0: class-string, 1: string}|string $action
     */
    public function match(array $methods, string $uri, Closure|array|string $action): Route
    {
        $methods = array_map('strtoupper', $methods);
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }

        return $this->add(array_values(array_unique($methods)), $uri, $action);
    }

    /** @param array<string, mixed> $data */
    public function view(string $uri, string $view, array $data = []): Route
    {
        return $this->get($uri, static fn () => view($view, $data));
    }

    public function redirect(string $from, string $to, int $status = 302): Route
    {
        return $this->any($from, static fn () => Response::redirect($to, $status));
    }

    /** Runs when no other route matches (instead of the default 404 page). */
    public function fallback(Closure|array|string $action): Route
    {
        return $this->fallback = (new Route(['GET', 'HEAD'], '/{fallbackPlaceholder}', $action, $this))
            ->where('fallbackPlaceholder', '.*');
    }

    /**
     * index, create, store, show, edit, update, destroy for a controller.
     *
     * @param array{only?: string[], except?: string[]} $options
     */
    public function resource(string $name, string $controller, array $options = [], bool $api = false): void
    {
        $base = '/' . trim($name, '/');
        $plural = basename($base);
        $singular = match (true) {
            str_ends_with($plural, 'ies') => substr($plural, 0, -3) . 'y',
            (bool) preg_match('/(ss|x|ch|sh)es$/', $plural) => substr($plural, 0, -2),
            str_ends_with($plural, 's') => substr($plural, 0, -1),
            default => $plural,
        };
        $param = '{' . str_replace('-', '_', $singular) . '}';
        $routeName = str_replace('/', '.', trim($name, '/'));

        $actions = [
            'index' => ['GET', $base],
            'create' => ['GET', "$base/create"],
            'store' => ['POST', $base],
            'show' => ['GET', "$base/$param"],
            'edit' => ['GET', "$base/$param/edit"],
            'update' => ['PUT', "$base/$param"],
            'destroy' => ['DELETE', "$base/$param"],
        ];
        if ($api) {
            unset($actions['create'], $actions['edit']);
        }
        if (isset($options['only'])) {
            $actions = array_intersect_key($actions, array_flip($options['only']));
        }
        if (isset($options['except'])) {
            $actions = array_diff_key($actions, array_flip($options['except']));
        }

        foreach ($actions as $action => [$method, $uri]) {
            $methods = $method === 'GET' ? ['GET', 'HEAD'] : ($method === 'PUT' ? ['PUT', 'PATCH'] : [$method]);
            $this->add($methods, $uri, [$controller, $action])->name("$routeName.$action");
        }
    }

    /** @param array{only?: string[], except?: string[]} $options */
    public function apiResource(string $name, string $controller, array $options = []): void
    {
        $this->resource($name, $controller, $options, true);
    }

    /** @param array{prefix?: string, middleware?: string[]|string, as?: string} $attributes */
    public function group(array $attributes, Closure $routes): void
    {
        $this->groupStack[] = [
            'prefix' => '/' . trim($attributes['prefix'] ?? '', '/'),
            'middleware' => (array) ($attributes['middleware'] ?? []),
            'as' => (string) ($attributes['as'] ?? ''),
        ];

        $routes($this);

        array_pop($this->groupStack);
    }

    public function prefix(string $prefix): RouteGroupBuilder
    {
        return (new RouteGroupBuilder($this))->prefix($prefix);
    }

    public function middleware(string ...$middleware): RouteGroupBuilder
    {
        return (new RouteGroupBuilder($this))->middleware(...$middleware);
    }

    /**
     * @param string[] $methods
     * @param Closure|array{0: class-string, 1: string}|string $action
     */
    private function add(array $methods, string $uri, Closure|array|string $action): Route
    {
        $prefix = implode('', array_map(static fn ($g) => rtrim($g['prefix'], '/'), $this->groupStack));
        $uri = '/' . trim($prefix . '/' . trim($uri, '/'), '/');

        $route = new Route($methods, $uri, $action, $this, implode('', array_column($this->groupStack, 'as')));

        foreach ($this->groupStack as $group) {
            $route->middleware(...$group['middleware']);
        }

        return $this->routes[] = $route;
    }

    /** @internal Called by Route::name(). */
    public function registerName(string $name, Route $route): void
    {
        $this->named[$name] = $route;
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes;
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    public function current(): ?Route
    {
        return $this->current;
    }

    public function currentRouteName(): ?string
    {
        return $this->current?->getName();
    }

    /** Path for a named route: $router->url('users.show', ['user' => 5]) -> "/users/5" */
    public function url(string $name, array $params = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Route [$name] is not defined.");

        return $route->path(array_map(static fn ($v) => $v instanceof Model ? $v->getKey() : $v, $params));
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }

            if (!in_array($method, $route->getMethods(), true)) {
                array_push($allowed, ...$route->getMethods());
                continue;
            }

            return $this->runRoute($route, $request, $params);
        }

        if ($method === 'OPTIONS' && $allowed !== []) {
            return $this->runMiddlewareOnly($request); // CORS pre-flight
        }

        if ($allowed !== []) {
            throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
        }

        if ($this->fallback !== null && in_array($method, ['GET', 'HEAD'], true)) {
            return $this->runRoute($this->fallback, $request, []);
        }

        throw new HttpException(404);
    }

    /** @param array<string, string> $params */
    private function runRoute(Route $route, Request $request, array $params): Response
    {
        $this->current = $route;
        $request->setParams($params);

        $middleware = $this->expandMiddleware([...(array) Config::get('middleware.global', []), ...$route->getMiddleware()]);
        $destination = fn (Request $request): Response => Response::from($this->callAction($route->getAction(), $request, $params));

        return $this->pipeline($middleware, $request, $destination);
    }

    private function runMiddlewareOnly(Request $request): Response
    {
        $middleware = $this->expandMiddleware([...(array) Config::get('middleware.global', []), 'api']);

        return $this->pipeline($middleware, $request, static fn () => Response::noContent());
    }

    /** @param string[] $middleware */
    private function pipeline(array $middleware, Request $request, Closure $destination): Response
    {
        $pipeline = $destination;

        foreach (array_reverse($middleware) as $definition) {
            [$instance, $args] = $this->resolveMiddleware($definition);
            $next = $pipeline;
            $pipeline = static fn (Request $request): Response => Response::from($instance->handle($request, $next, ...$args));
        }

        return $pipeline($request);
    }

    /**
     * Replace group names ("web", "api") with their middleware lists and remove duplicates.
     *
     * @param string[] $middleware
     * @return string[]
     */
    private function expandMiddleware(array $middleware): array
    {
        $groups = (array) Config::get('middleware.groups', []);
        $expanded = [];

        foreach ($middleware as $item) {
            foreach (isset($groups[$item]) ? (array) $groups[$item] : [$item] as $entry) {
                if (!in_array($entry, $expanded, true)) {
                    $expanded[] = $entry;
                }
            }
        }

        return $expanded;
    }

    /**
     * "auth" or "throttle:5,1" or a full class name.
     *
     * @return array{0: Middleware, 1: string[]}
     */
    private function resolveMiddleware(string $definition): array
    {
        [$name, $args] = array_pad(explode(':', $definition, 2), 2, null);
        // Read the alias table directly: names may contain dots ("password.confirm").
        $aliases = (array) Config::get('middleware.aliases', []);
        $class = $aliases[$name] ?? $name;

        if (!is_string($class) || !class_exists($class)) {
            throw new RuntimeException("Middleware [$name] not found. Register it in config/middleware.php.");
        }

        $instance = $this->container->make($class);
        if (!$instance instanceof Middleware) {
            throw new RuntimeException("[$class] must implement " . Middleware::class . '.');
        }

        return [$instance, $args === null ? [] : explode(',', $args)];
    }

    /**
     * @param Closure|array{0: class-string, 1: string}|string $action
     * @param array<string, string> $params
     */
    private function callAction(Closure|array|string $action, Request $request, array $params): mixed
    {
        if (is_string($action)) {
            $action = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        }

        if (is_array($action)) {
            [$class, $method] = $action;
            if (!class_exists($class) || !method_exists($class, $method)) {
                throw new RuntimeException("Route action [$class@$method] does not exist.");
            }
            $controller = $this->container->make($class);
            $reflection = new ReflectionMethod($controller, $method);
            if (!$reflection->isPublic() || $reflection->isStatic()) {
                throw new HttpException(404);
            }

            return $reflection->invokeArgs($controller, $this->resolveArguments($reflection, $request, $params));
        }

        $reflection = new ReflectionFunction($action);

        return $reflection->invokeArgs($this->resolveArguments($reflection, $request, $params));
    }

    /**
     * Inject the Request, route parameters (by name), models (route model binding)
     * and any other class from the container.
     *
     * @param array<string, string> $params
     * @return array<int, mixed>
     */
    private function resolveArguments(ReflectionFunctionAbstract $reflection, Request $request, array $params): array
    {
        $args = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $name = $parameter->getName();

            if ($typeName !== null && is_a($typeName, Request::class, true)) {
                $args[] = $request;
                continue;
            }

            if (array_key_exists($name, $params)) {
                $args[] = $this->castParameter($params[$name], $typeName, $parameter->allowsNull());
                continue;
            }

            if ($typeName !== null && $type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->container->make($typeName);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new RuntimeException("Cannot resolve parameter \${$name} for route action.");
        }

        return $args;
    }

    private function castParameter(string $value, ?string $type, bool $nullable): mixed
    {
        if ($type !== null && is_a($type, Model::class, true)) {
            $model = $type::find($value);
            if ($model === null && !$nullable) {
                throw new HttpException(404);
            }

            return $model;
        }

        return match ($type) {
            'int' => preg_match('/^-?\d+$/', $value) ? (int) $value : throw new HttpException(404),
            'float' => is_numeric($value) ? (float) $value : throw new HttpException(404),
            'bool' => in_array($value, ['1', 'true'], true),
            default => $value,
        };
    }
}
