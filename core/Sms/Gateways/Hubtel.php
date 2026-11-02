<?php

declare(strict_types=1);

namespace Core\Sms\Gateways;

use Core\Sms\Gateway;
use Core\Sms\SmsException;
use Core\Sms\SmsResult;

/**
 * Hubtel SMS (Ghana). Docs: https://developers.hubtel.com
 * .env: HUBTEL_SMS_CLIENT_ID, HUBTEL_SMS_CLIENT_SECRET, HUBTEL_SMS_FROM
 */
final class Hubtel extends Gateway
{
    private const URL = 'https://smsc.hubtel.com/v1/messages/send';

    public function name(): string
    {
        return 'hubtel';
    }

    public function send(array $to, string $message, ?string $from = null): SmsResult
    {
        $from = $this->sender($from);
        $ids = [];
        $failed = [];
        $raw = [];

        foreach ($to as $number) {
            $response = $this->http()->withBasicAuth($this->setting('client_id'), $this->setting('client_secret'))->asJson()
                ->post(self::URL, ['From' => $from, 'To' => ltrim($number, '+'), 'Content' => $message]);

            $raw[$number] = $response->json();
            $id = $response->json('messageId') ?? $response->json('MessageId');
            if ($response->ok() && $id) {
                $ids[] = (string) $id;
            } else {
                $failed[] = $number;
            }
        }

        if ($ids === [] && $failed !== []) {
            throw new SmsException('Hubtel rejected the message: ' . json_encode(reset($raw)));
        }

        return new SmsResult($this->name(), $failed === [], $ids, $failed, '', $raw);
    }
}
