# SMS gateways

Four providers plus a development driver, all behind one API.

| Gateway | Coverage | Key |
|---|---|---|
| **Twilio** | global | `twilio` |
| **Africa's Talking** | Ghana, Kenya, Nigeria, Uganda and more (free sandbox) | `africastalking` |
| **Hubtel** | Ghana | `hubtel` |
| **Arkesel** | Ghana | `arkesel` |
| log | writes to `storage/logs/sms.log` (default, for development) | `log` |

## Install a gateway

```bash
php console sms:install arkesel
php console sms:list
php console sms:test 0241234567
```

| Gateway | .env keys |
|---|---|
| Twilio | `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` |
| Africa's Talking | `AFRICASTALKING_USERNAME` (`sandbox` for testing), `AFRICASTALKING_API_KEY`, `AFRICASTALKING_FROM` |
| Hubtel | `HUBTEL_SMS_CLIENT_ID`, `HUBTEL_SMS_CLIENT_SECRET`, `HUBTEL_SMS_FROM` |
| Arkesel | `ARKESEL_API_KEY`, `ARKESEL_FROM` |

> **Note:** Alphanumeric sender IDs (e.g. `INBFIRM`, max 11 characters) usually have to be approved by the provider first.

## Sending

```php
use Core\Sms\Sms;

Sms::send('0241234567', 'Your verification code is 493027');
Sms::send(['0241234567', '+233201234567'], 'Classes resume on Monday.');
Sms::send('0241234567', 'Hello', from: 'INBFIRM');
Sms::send('+14155550100', 'Hi', gateway: 'twilio');

$result = Sms::send(...);
$result->successful;   $result->messageIds;   $result->failed;   // numbers that were rejected
```

## Phone numbers

Local numbers are converted to international format using `SMS_COUNTRY_CODE` (233 by default):

| Input | Sent as |
|---|---|
| `0241234567`, `024 123 4567` | `+233241234567` |
| `233241234567` | `+233241234567` |
| `+447700900123`, `00447700900123` | `+447700900123` |

Invalid numbers throw `Core\Sms\SmsException`.

## Abuse protection

- Each number can receive at most `SMS_HOURLY_LIMIT` messages per hour (default 10). This stops "SMS pumping" fraud, where attackers make your site send thousands of paid messages.
- Messages are limited to 918 characters (6 parts).
- Put OTP and "send code" routes behind `throttle` middleware too.

## One-time codes

```php
$code = Core\Token::otp(6);
Core\Cache::put('otp:' . $user->id, Core\Token::hash($code), 300);   // store a hash, 5 minutes
Sms::send($user->phone, "Your code is $code. It expires in 5 minutes.");

// later
$valid = Core\Token::equals((string) Core\Cache::get('otp:' . $user->id), Core\Token::hash($input));
```

## Your own provider

```php
final class MySms extends Core\Sms\Gateway
{
    public function name(): string { return 'mysms'; }
    public function send(array $to, string $message, ?string $from = null): SmsResult { ... }
}

// config/sms.php
'mysms' => ['driver' => App\Sms\MySms::class, 'api_key' => env('MYSMS_KEY'), 'sender' => 'INBFIRM'],
```
