<?php

declare(strict_types=1);

namespace Core\Payments;

use Core\Config;
use Core\Request;

/**
 * Payment facade. Uses PAYMENT_GATEWAY from .env unless you pick one.
 *
 *   $checkout = Payment::initialize(PaymentRequest::make('150.00', 'GHS', $user->email)->callbackUrl(...));
 *   return redirect($checkout->url);
 *
 *   $result = Payment::gateway('stripe')->verify($order->gateway_reference);
 */
final class Payment
{
    /** Gateways that ship with the framework. */
    public const GATEWAYS = [
        'paystack' => Gateways\Paystack::class,
        'flutterwave' => Gateways\Flutterwave::class,
        'stripe' => Gateways\Stripe::class,
        'paypal' => Gateways\PayPal::class,
        'hubtel' => Gateways\Hubtel::class,
    ];

    /** @var array<string, PaymentGateway> */
    private static array $resolved = [];

    public static function gateway(?string $name = null): PaymentGateway
    {
        $name = strtolower($name ?? (string) Config::get('payments.default', ''));
        if ($name === '') {
            throw new PaymentException('No payment gateway selected.', 'Run "php console payment:install paystack" (or flutterwave, stripe, paypal, hubtel) to choose one.');
        }

        if (isset(self::$resolved[$name])) {
            return self::$resolved[$name];
        }

        $config = Config::get("payments.gateways.$name");
        if (!is_array($config)) {
            throw new PaymentException("Payment gateway [$name] is not configured.", 'Add it under "gateways" in config/payments.php or run "php console payment:install ' . $name . '".');
        }

        $class = (string) ($config['driver'] ?? self::GATEWAYS[$name] ?? '');
        if (!is_a($class, PaymentGateway::class, true)) {
            throw new PaymentException("Payment gateway [$name] has no valid driver class.");
        }

        return self::$resolved[$name] = new $class($config);
    }

    public static function initialize(PaymentRequest $request, ?string $gateway = null): Checkout
    {
        return self::gateway($gateway)->initialize($request);
    }

    public static function verify(string $gatewayReference, ?string $gateway = null): PaymentResult
    {
        return self::gateway($gateway)->verify($gatewayReference);
    }

    public static function parseWebhook(string $gateway, Request $request, string $rawBody): ?WebhookEvent
    {
        return self::gateway($gateway)->parseWebhook($request, $rawBody);
    }

    /** Forget resolved gateways (after changing config at runtime, e.g. in tests). */
    public static function flush(): void
    {
        self::$resolved = [];
    }
}
