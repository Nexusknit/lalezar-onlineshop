<?php

$appUrl = rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/');
$frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/');
$paymentDescription = env('PAYMENT_DESCRIPTION', 'پرداخت سفارش '.env('APP_NAME', 'فروشگاه'));

return [
    'default_provider' => env('PAYMENT_PROVIDER', 'mock_gateway'),
    'callback_base_url' => env('PAYMENT_CALLBACK_BASE_URL', $appUrl),
    'frontend_callback_url' => env('PAYMENT_FRONTEND_CALLBACK_URL', "{$frontendUrl}/payment/callback"),

    'providers' => [
        'mock_gateway' => [
            'enabled' => (bool) env('PAYMENT_MOCK_ENABLED', true),
        ],

        'shetabit' => [
            'enabled' => (bool) env('PAYMENT_SHETABIT_ENABLED', false),
            'driver' => env('PAYMENT_SHETABIT_DRIVER', 'zarinpal'),
            'merchant_id' => env('PAYMENT_SHETABIT_MERCHANT_ID'),
            'sandbox' => (bool) env('PAYMENT_SHETABIT_SANDBOX', true),
            'currency' => env('PAYMENT_SHETABIT_CURRENCY', 'R'),
            'timeout' => (int) env('PAYMENT_SHETABIT_TIMEOUT', 10),
            'description' => $paymentDescription,
        ],

        'zarinpal' => [
            'enabled' => (bool) env('PAYMENT_ZARINPAL_ENABLED', false),
            'merchant_id' => env('PAYMENT_ZARINPAL_MERCHANT_ID'),
            'sandbox' => (bool) env('PAYMENT_ZARINPAL_SANDBOX', true),
            'timeout' => (int) env('PAYMENT_ZARINPAL_TIMEOUT', 10),
            'description' => $paymentDescription,
        ],
    ],
];
