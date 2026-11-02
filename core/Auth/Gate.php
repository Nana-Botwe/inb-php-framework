<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Auth;
use Core\HttpException;
use Core\Log;
use Core\Model;
use ReflectionMethod;

/**
 * Authorization: abilities and model policies (like Laravel's Gate).
 *
 *   Gate::define('view-reports', fn (User $user) => $user->hasRole('admin', 'manager'));
 *   Gate::allows('view-reports');
 *
 *   // Policies: App\Policies\PostPolicy is used automatically for App\Models\Post
 *   Gate::allows('update', $post);           // PostPolicy::update(User $user, Post $post)
 *   Gate::authorize('delete', $post);        // 403 (and a security-log entry) when denied
 *   $this->authorize('update', $post);       // in controllers
 *   @can('update', $post) ... @endcan        // in Blade
 *
 * Secure defaults: guests are denied unless the ability accepts a nullable user; an ability or policy
 * method that doesn't exist DENIES access; Gate::before() can grant super-admins everything.
 */
final class Gate
{
    /** @var array<string, callable> */
    private static array $abilities = [];

    /** @var array<class-string, class-string> */
    private static array $policies = [];

    /** @var callable[] */
    private static array $before = [];

    public static function define(string $ability, callable $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    /** @param class-string $model @param class-string $policy */
    public static function policy(string $model, string $policy): void
    {
        self::$policies[$model] = $policy;
    }

    /** Runs before every check; return true/false to decide, null to continue. */
    public static function before(callable $callback): void
    {
        self::$before[] = $callback;
    }

    public static function allows(string $ability, mixed ...$arguments): bool
    {
        return self::check($ability, Auth::user(), ...$arguments);
    }

    public static function denies(string $ability, mixed ...$arguments): bool
    {
        return !self::allows($ability, ...$arguments);
    }

    public static function forUser(?Model $user, string $ability, mixed ...$arguments): bool
    {
        return self::check($ability, $user, ...$arguments);
    }

    /** @throws HttpException 403 */
    public static function authorize(string $ability, mixed ...$arguments): void
    {
        if (!self::allows($ability, ...$arguments)) {
            $target = $arguments[0] ?? null;
            Log::security('authorization_denied', [
                'ability' => $ability,
                'target' => $target instanceof Model ? $target::class . '#' . $target->getKey() : (is_string($target) ? $target : null),
            ]);
            throw new HttpException(403, 'You are not allowed to do this.');
        }
    }

    public static function check(string $ability, ?Model $user, mixed ...$arguments): bool
    {
        foreach (self::$before as $callback) {
            $result = $callback($user, $ability, ...$arguments);
            if (is_bool($result)) {
                return $result;
            }
        }

        if (isset(self::$abilities[$ability])) {
            return self::call(self::$abilities[$ability], $user, $arguments);
        }

        $policy = self::policyFor($arguments[0] ?? null);
        if ($policy === null) {
            return false; // unknown ability: deny
        }

        $instance = new $policy();

        if (method_exists($instance, 'before')) {
            $result = $instance->before($user, $ability, ...$arguments);
            if (is_bool($result)) {
                return $result;
            }
        }

        if (!method_exists($instance, $ability)) {
            return false;
        }

        // A class-string argument (e.g. Gate::allows('create', Post::class)) is not passed to the method.
        $methodArgs = is_string($arguments[0] ?? null) ? array_slice($arguments, 1) : $arguments;

        return self::call([$instance, $ability], $user, $methodArgs);
    }

    /** @return class-string|null */
    public static function policyFor(mixed $target): ?string
    {
        $class = $target instanceof Model ? $target::class : (is_string($target) && class_exists($target) ? $target : null);
        if ($class === null) {
            return null;
        }

        if (isset(self::$policies[$class])) {
            return self::$policies[$class];
        }

        $guess = 'App\\Policies\\' . (new \ReflectionClass($class))->getShortName() . 'Policy';

        return class_exists($guess) ? $guess : null;
    }

    /** @param array<int, mixed> $arguments */
    private static function call(callable $callback, ?Model $user, array $arguments): bool
    {
        // Guests only pass if the callback explicitly accepts a nullable user.
        if ($user === null) {
            $reflection = is_array($callback) ? new ReflectionMethod($callback[0], $callback[1]) : new \ReflectionFunction(\Closure::fromCallable($callback));
            $first = $reflection->getParameters()[0] ?? null;
            if ($first === null || !$first->allowsNull()) {
                return false;
            }
        }

        return (bool) $callback($user, ...$arguments);
    }

    /** @internal tests */
    public static function flush(): void
    {
        self::$abilities = [];
        self::$policies = [];
        self::$before = [];
    }
}
