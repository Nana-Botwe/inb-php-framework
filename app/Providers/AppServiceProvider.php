<?php

declare(strict_types=1);

namespace App\Providers;

use Core\Event;
use Core\ServiceProvider;
use Core\View;

/**
 * Wire up your application here.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind your own services into the container, e.g.:
        // $this->app->singleton(InvoiceService::class, fn () => new InvoiceService(config('invoices')));
    }

    public function boot(): void
    {
        View::share('appName', (string) config('app.name'));

        // React to framework events, e.g.:
        Event::listen('payment.succeeded', static function ($payment): void {
            logger('payment_succeeded', ['payment' => $payment->id]);
        });
    }
}
