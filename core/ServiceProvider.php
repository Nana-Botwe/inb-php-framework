<?php

declare(strict_types=1);

namespace Core;

/**
 * Providers wire up your app. register() binds things into the container;
 * boot() runs after all providers are registered (listen to events, share view data, ...).
 * List providers in config/app.php under "providers".
 */
abstract class ServiceProvider
{
    public function __construct(protected App $app)
    {
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
