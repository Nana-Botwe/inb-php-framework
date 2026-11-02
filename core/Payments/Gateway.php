<?php

declare(strict_types=1);

namespace Core\Payments;

use Core\Http\Client;
use Core\Http\ClientResponse;
use Core\Log;

/**
 * Shared helpers for gateway implementations.
 */
abstract class Gateway implements PaymentGateway
{
    /** @param array<string, mixed> $config Entry from config/payments.php */
    public function __construct(protected readonly array $config)
    {
    }

    protected function setting(string $key, bool $required = true): string
    {
        $value = (string) ($this->config[$key] ?? '');

        if ($required && $value === '') {
            throw new PaymentException(
                "The {$this->name()} setting [$key] is empty.",
                'Add your ' . $this->name() . ' credentials to .env (run "php console payment:install ' . $this->name() . '" to create the keys).',
            );
        }

        return $value;
    }

    protected function http(): Client
    {
        return Client::make()->timeout((int) ($this->config['timeout'] ?? 30));
    }

    protected function fail(ClientResponse $response, string $action): never
    {
        $message = (string) ($response->json('message') ?? $response->json('error.message') ?? $response->json('error_description') ?? 'HTTP ' . $response->status());
        Log::info('payment_gateway_error', ['gateway' => $this->name(), 'action' => $action, 'status' => $response->status()]);

        throw new PaymentException("{$this->name()} could not $action: $message");
    }
}
