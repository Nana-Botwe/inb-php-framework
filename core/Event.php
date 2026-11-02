<?php

declare(strict_types=1);

namespace Core;

/**
 * Simple event dispatcher.
 *
 *   Event::listen(UserRegistered::class, SendWelcomeEmail::class);     // class with handle($event)
 *   Event::listen('order.paid', fn ($order) => ...);
 *   Event::dispatch(new UserRegistered($user));     // or event(new UserRegistered($user))
 */
final class Event
{
    /** @var array<string, array<int, callable|string>> */
    private static array $listeners = [];

    public static function listen(string $event, callable|string $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /**
     * @param object|string $event An event object (listeners get the object) or a name.
     * @param array<int, mixed> $payload Arguments for string events.
     * @return array<int, mixed> Listener return values.
     */
    public static function dispatch(object|string $event, array $payload = []): array
    {
        $name = is_object($event) ? $event::class : $event;
        $arguments = is_object($event) ? [$event] : $payload;
        $results = [];

        foreach (self::$listeners[$name] ?? [] as $listener) {
            if (is_string($listener) && class_exists($listener)) {
                $listener = [App::instance()->make($listener), 'handle'];
            }
            $result = $listener(...$arguments);
            $results[] = $result;
            if ($result === false) {
                break; // a listener can stop propagation
            }
        }

        return $results;
    }

    public static function forget(string $event): void
    {
        unset(self::$listeners[$event]);
    }
}
