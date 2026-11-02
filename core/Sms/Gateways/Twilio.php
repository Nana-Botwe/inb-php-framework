<?php

declare(strict_types=1);

namespace Core\Sms\Gateways;

use Core\Sms\Gateway;
use Core\Sms\SmsException;
use Core\Sms\SmsResult;

/**
 * Twilio (global). Docs: https://www.twilio.com/docs/sms/api
 * .env: TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM (a Twilio number or approved sender ID)
 */
final class Twilio extends Gateway
{
    public function name(): string
    {
        return 'twilio';
    }

    public function send(array $to, string $message, ?string $from = null): SmsResult
    {
        $sid = $this->setting('sid');
        $from = $this->sender($from);
        $ids = [];
        $failed = [];
        $raw = [];

        // Twilio sends one message per request.
        foreach ($to as $number) {
            $response = $this->http()->withBasicAuth($sid, $this->setting('token'))->asForm()
                ->post('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json', [
                    'To' => $number,
                    'From' => $from,
                    'Body' => $message,
                ]);

            $raw[$number] = $response->json();
            if ($response->ok() && $response->json('sid')) {
                $ids[] = (string) $response->json('sid');
            } else {
                $failed[] = $number;
            }
        }

        if ($ids === [] && $failed !== []) {
            $first = reset($raw);
            throw new SmsException('Twilio rejected the message: ' . (is_array($first) ? (string) ($first['message'] ?? 'unknown error') : 'unknown error'));
        }

        return new SmsResult($this->name(), $failed === [], $ids, $failed, '', $raw);
    }
}
