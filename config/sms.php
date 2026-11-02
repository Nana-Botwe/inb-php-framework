<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS gateways
|--------------------------------------------------------------------------
| Pick one with:  php console sms:install arkesel   (twilio | africastalking | hubtel | arkesel)
| "log" writes messages to storage/logs/sms.log — perfect for development.
*/

return [
    'default' => env('SMS_GATEWAY', 'log'),

    // Local numbers like 0241234567 become +233241234567.
    'country_code' => env('SMS_COUNTRY_CODE', '233'),

    // Anti-abuse: max messages per phone number per hour, and max message length.
    'per_number_hourly_limit' => (int) env('SMS_HOURLY_LIMIT', 10),
    'max_length' => 918,

    'gateways' => [
        'log' => [],

        'twilio' => [
            'sid' => env('TWILIO_SID', ''),
            'token' => env('TWILIO_TOKEN', ''),
            'sender' => env('TWILIO_FROM', ''),
        ],

        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME', 'sandbox'),
            'api_key' => env('AFRICASTALKING_API_KEY', ''),
            'sender' => env('AFRICASTALKING_FROM', ''),
        ],

        'hubtel' => [
            'client_id' => env('HUBTEL_SMS_CLIENT_ID', ''),
            'client_secret' => env('HUBTEL_SMS_CLIENT_SECRET', ''),
            'sender' => env('HUBTEL_SMS_FROM', ''),
        ],

        'arkesel' => [
            'api_key' => env('ARKESEL_API_KEY', ''),
            'sender' => env('ARKESEL_FROM', ''),
        ],

        // Your own provider: 'mysms' => ['driver' => App\Sms\MyGateway::class, ...]
    ],
];
