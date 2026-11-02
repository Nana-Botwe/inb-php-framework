<?php

/*
|--------------------------------------------------------------------------
| Payment gateways
|--------------------------------------------------------------------------
| Pick one with:  php console payment:install paystack   (flutterwave | stripe | paypal | hubtel)
| That adds the credential keys to .env and makes it the default. Fill in your keys there.
| Use TEST keys while developing. Never commit real keys.
*/

return [
    'default' => env('PAYMENT_GATEWAY', ''),

    'currency' => env('PAYMENT_CURRENCY', 'GHS'),

    'gateways' => [
        'paystack' => [
            'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
            'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
        ],

        'flutterwave' => [
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY', ''),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY', ''),
            'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH', ''),   // the "secret hash" set in your dashboard
        ],

        'stripe' => [
            'public_key' => env('STRIPE_PUBLIC_KEY', ''),
            'secret_key' => env('STRIPE_SECRET_KEY', ''),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),    // whsec_...
            'tolerance' => 300,                                      // seconds a webhook signature is valid
        ],

        'paypal' => [
            'mode' => env('PAYPAL_MODE', 'sandbox'),                 // sandbox | live
            'client_id' => env('PAYPAL_CLIENT_ID', ''),
            'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID', ''),
        ],

        'hubtel' => [
            'client_id' => env('HUBTEL_CLIENT_ID', ''),
            'client_secret' => env('HUBTEL_CLIENT_SECRET', ''),
            'merchant_account' => env('HUBTEL_MERCHANT_ACCOUNT', ''),
            'callback_url' => env('HUBTEL_CALLBACK_URL', ''),        // defaults to your webhook route
        ],

        // Your own gateway: 'mygateway' => ['driver' => App\Payments\MyGateway::class, ...]
    ],
];
