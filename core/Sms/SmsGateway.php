<?php

declare(strict_types=1);

namespace Core\Sms;

/**
 * Every SMS provider implements this; switch providers with SMS_GATEWAY in .env.
 */
interface SmsGateway
{
    public function name(): string;

    /** @param string[] $to Phone numbers in international format (+233...). */
    public function send(array $to, string $message, ?string $from = null): SmsResult;
}
