<?php

declare(strict_types=1);

namespace Core\Sms\Gateways;

use Core\Sms\Gateway;
use Core\Sms\SmsException;
use Core\Sms\SmsResult;

/**
 * Arkesel (Ghana). Docs: https://developers.arkesel.com
 * .env: ARKESEL_API_KEY, ARKESEL_FROM
 */
final class Arkesel extends Gateway
{
    private const URL = 'https://sms.arkesel.com/api/v2/sms/send';

    public function name(): string
    {
        return 'arkesel';
    }

    public function send(array $to, string $message, ?string $from = null): SmsResult
    {
        $response = $this->http()->withHeaders(['api-key' => $this->setting('api_key')])->asJson()->post(self::URL, [
            'sender' => $this->sender($from),
            'message' => $message,
            'recipients' => array_map(static fn ($n) => ltrim($n, '+'), $to),
        ]);

        if (!$response->ok() || $response->json('status') !== 'success') {
            throw new SmsException('Arkesel rejected the message: ' . (string) ($response->json('message') ?? $response->body()));
        }

        $ids = array_map(static fn ($r) => (string) ($r['id'] ?? ''), (array) $response->json('data', []));

        return new SmsResult($this->name(), true, $ids, [], (string) $response->json('message', ''), (array) $response->json());
    }
}
