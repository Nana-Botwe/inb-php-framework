<?php

declare(strict_types=1);

namespace Core\Sms\Gateways;

use Core\Sms\Gateway;
use Core\Sms\SmsException;
use Core\Sms\SmsResult;

/**
 * Africa's Talking (Ghana, Kenya, Nigeria, Uganda, ...). Docs: https://developers.africastalking.com/docs/sms
 * .env: AFRICASTALKING_USERNAME (use "sandbox" for testing), AFRICASTALKING_API_KEY, AFRICASTALKING_FROM
 */
final class AfricasTalking extends Gateway
{
    public function name(): string
    {
        return 'africastalking';
    }

    public function send(array $to, string $message, ?string $from = null): SmsResult
    {
        $username = $this->setting('username');
        $host = $username === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';

        $data = ['username' => $username, 'to' => implode(',', $to), 'message' => $message];
        $sender = $from ?? (string) ($this->config['sender'] ?? '');
        if ($sender !== '') {
            $data['from'] = $this->sender($sender);
        }

        $response = $this->http()->withHeaders(['apiKey' => $this->setting('api_key')])->asForm()
            ->post($host . '/version1/messaging', $data);

        if (!$response->ok() && $response->status() !== 201) {
            throw new SmsException("Africa's Talking rejected the message: " . $response->body());
        }

        $ids = [];
        $failed = [];
        foreach ((array) $response->json('SMSMessageData.Recipients', []) as $recipient) {
            if (($recipient['status'] ?? '') === 'Success') {
                $ids[] = (string) ($recipient['messageId'] ?? '');
            } else {
                $failed[] = (string) ($recipient['number'] ?? '');
            }
        }

        return new SmsResult($this->name(), $ids !== [] && $failed === [], $ids, $failed, (string) $response->json('SMSMessageData.Message', ''), (array) $response->json());
    }
}
