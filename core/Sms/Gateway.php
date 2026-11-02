<?php

declare(strict_types=1);

namespace Core\Sms;

use Core\Http\Client;

abstract class Gateway implements SmsGateway
{
    /** @param array<string, mixed> $config Entry from config/sms.php */
    public function __construct(protected readonly array $config)
    {
    }

    protected function setting(string $key, bool $required = true): string
    {
        $value = (string) ($this->config[$key] ?? '');
        if ($required && $value === '') {
            throw new SmsException(
                "The {$this->name()} SMS setting [$key] is empty.",
                'Add your ' . $this->name() . ' credentials to .env (run "php console sms:install ' . $this->name() . '").',
            );
        }

        return $value;
    }

    protected function sender(?string $from): string
    {
        $sender = $from ?? $this->setting('sender');
        // Alphanumeric sender IDs are max 11 characters on most networks.
        if (!preg_match('/^(\+?\d{3,15}|[A-Za-z0-9 ]{1,11})$/', $sender)) {
            throw new SmsException("Invalid sender ID [$sender]. Use up to 11 letters/numbers or a phone number.");
        }

        return $sender;
    }

    protected function http(): Client
    {
        return Client::make()->timeout((int) ($this->config['timeout'] ?? 20));
    }
}
