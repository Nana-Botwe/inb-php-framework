<?php

declare(strict_types=1);

namespace Core\Sms;

use Core\Config;
use Core\Log;
use Core\RateLimiter;

/**
 * SMS facade. Uses SMS_GATEWAY from .env (log | twilio | africastalking | hubtel | arkesel).
 *
 *   Sms::send('0241234567', 'Your code is 493027');            // local numbers become +233241234567
 *   Sms::send(['+233241234567', '+233201234567'], 'Hello!');
 *   Sms::gateway('twilio')->send(['+14155550100'], 'Hi');
 *
 * Built-in protection: numbers are validated/normalised, messages are length-limited, and each
 * number can receive at most N messages per hour (config/sms.php) to stop SMS-pumping fraud.
 */
final class Sms
{
    public const GATEWAYS = [
        'log' => Gateways\LogGateway::class,
        'twilio' => Gateways\Twilio::class,
        'africastalking' => Gateways\AfricasTalking::class,
        'hubtel' => Gateways\Hubtel::class,
        'arkesel' => Gateways\Arkesel::class,
    ];

    /** @var array<string, SmsGateway> */
    private static array $resolved = [];

    public static function gateway(?string $name = null): SmsGateway
    {
        $name = strtolower($name ?? (string) Config::get('sms.default', 'log'));

        if (isset(self::$resolved[$name])) {
            return self::$resolved[$name];
        }

        $config = Config::get("sms.gateways.$name");
        if (!is_array($config)) {
            throw new SmsException("SMS gateway [$name] is not configured.", 'Run "php console sms:install ' . $name . '" or add it in config/sms.php.');
        }

        $class = (string) ($config['driver'] ?? self::GATEWAYS[$name] ?? '');
        if (!is_a($class, SmsGateway::class, true)) {
            throw new SmsException("SMS gateway [$name] has no valid driver class.");
        }

        return self::$resolved[$name] = new $class($config);
    }

    /** @param string|string[] $to */
    public static function send(string|array $to, string $message, ?string $from = null, ?string $gateway = null): SmsResult
    {
        $message = trim(str_replace("\0", '', $message));
        $max = (int) Config::get('sms.max_length', 918); // 6 concatenated SMS parts
        if ($message === '' || mb_strlen($message) > $max) {
            throw new SmsException("The SMS message must be between 1 and $max characters.");
        }

        $numbers = [];
        foreach ((array) $to as $number) {
            $normalised = self::normalize($number);
            $limit = (int) Config::get('sms.per_number_hourly_limit', 10);
            if (RateLimiter::hit('sms:' . $normalised, 3600) > $limit) {
                Log::security('sms_rate_limited', ['number' => substr($normalised, 0, 6) . '****']);
                continue;
            }
            $numbers[] = $normalised;
        }

        if ($numbers === []) {
            throw new SmsException('No valid recipients (numbers invalid or hourly limit reached).');
        }

        $result = self::gateway($gateway)->send(array_values(array_unique($numbers)), $message, $from);
        Log::info('sms_sent', ['gateway' => $result->gateway, 'recipients' => count($numbers), 'ok' => $result->successful]);

        return $result;
    }

    /** "024 123 4567" -> "+233241234567" using SMS_COUNTRY_CODE (default 233, Ghana). */
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/[^\d+]/', '', $number) ?? '';
        $country = (string) Config::get('sms.country_code', '233');

        $international = match (true) {
            str_starts_with($digits, '+') => $digits,
            str_starts_with($digits, '00') => '+' . substr($digits, 2),
            str_starts_with($digits, '0') => '+' . $country . substr($digits, 1),
            str_starts_with($digits, $country) && strlen($digits) > 10 => '+' . $digits,
            default => '+' . $country . $digits,
        };

        if (!preg_match('/^\+[1-9]\d{7,14}$/', $international)) {
            throw new SmsException("Invalid phone number [$number].");
        }

        return $international;
    }

    public static function flush(): void
    {
        self::$resolved = [];
    }
}
