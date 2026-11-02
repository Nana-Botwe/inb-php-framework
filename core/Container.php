<?php

declare(strict_types=1);

namespace Core;

use Closure;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Dependency-injection container with autowiring.
 *
 *   app()->singleton(PaymentGateway::class, fn () => new StripeGateway(config('services.stripe')));
 *   app()->bind(ReportExporter::class, CsvExporter::class);
 *   $service = app(InvoiceService::class);      // constructor dependencies are injected automatically
 */
class Container
{
    /** @var array<string, array{concrete: Closure|string, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        unset($this->instances[$abstract]);
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => $shared];
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /** Register an existing object: app()->bindInstance(Mailer::class, $mailer) */
    public function bindInstance(string $abstract, mixed $instance): mixed
    {
        return $this->instances[$abstract] = $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]);
    }

    /**
     * @template T
     * @param class-string<T>|string $abstract
     * @param array<string, mixed> $parameters Values for constructor parameters, by name.
     * @return T|mixed
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? $abstract;

        $object = $concrete instanceof Closure
            ? $concrete($this, $parameters)
            : ($concrete === $abstract ? $this->build($concrete, $parameters) : $this->make($concrete, $parameters));

        if ($binding['shared'] ?? false) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /** @param array<string, mixed> $parameters */
    public function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve [$class]: class does not exist.");
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Cannot resolve [$class]: it is not instantiable. Bind an implementation first.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        return $reflection->newInstanceArgs($this->resolveParameters($constructor, $parameters));
    }

    /**
     * Call a closure or [object, method] and inject its parameters.
     *
     * @param array<string, mixed> $parameters
     */
    public function call(callable|array $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            $target = is_object($callback[0]) ? $callback[0] : null;

            return $reflection->invokeArgs($target, $this->resolveParameters($reflection, $parameters));
        }

        $reflection = new \ReflectionFunction(Closure::fromCallable($callback));

        return $reflection->invokeArgs($this->resolveParameters($reflection, $parameters));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<int, mixed>
     */
    public function resolveParameters(ReflectionFunctionAbstract $function, array $parameters = []): array
    {
        $args = [];

        foreach ($function->getParameters() as $parameter) {
            $args[] = $this->resolveParameter($parameter, $parameters);
        }

        return $args;
    }

    /** @param array<string, mixed> $parameters */
    private function resolveParameter(ReflectionParameter $parameter, array $parameters): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if ($typeName !== null) {
            foreach ($parameters as $value) {
                if ($value instanceof $typeName) {
                    return $value;
                }
            }
        }

        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        if ($typeName !== null && ($this->has($typeName) || class_exists($typeName))) {
            return $this->make($typeName);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        $owner = $parameter->getDeclaringClass()?->getName() ?? 'closure';
        throw new RuntimeException("Cannot resolve parameter \${$name} of [$owner].");
    }
}
