<?php

return [
    'default_provider' => env('PAYMENT_PROVIDER', 'mock_gateway'),
    'callback_base_url' => env('PAYMENT_CALLBACK_BASE_URL', ''),
    'frontend_callback_url' => env('PAYMENT_FRONTEND_CALLBACK_URL'),

    'providers' => [
        'mock_gateway' => [
            'enabled' => (bool) env('PAYMENT_MOCK_ENABLED', true),
        ],

        'shetabit' => [
            'enabled' => (bool) env('PAYMENT_SHETABIT_ENABLED', false),
            'driver' => env('PAYMENT_SHETABIT_DRIVER', 'zarinpal'),
            'merchant_id' => env('PAYMENT_SHETABIT_MERCHANT_ID'),
            'sandbox' => (bool) env('PAYMENT_SHETABIT_SANDBOX', true),
            'timeout' => (int) env('PAYMENT_SHETABIT_TIMEOUT', 10),
            'description' => env('PAYMENT_DESCRIPTION', 'پرداخت سفارش فروشگاه لوازم الکتریکی'),
        ],

        'zarinpal' => [
            'enabled' => (bool) env('PAYMENT_ZARINPAL_ENABLED', false),
            'merchant_id' => env('PAYMENT_ZARINPAL_MERCHANT_ID'),
            'sandbox' => (bool) env('PAYMENT_ZARINPAL_SANDBOX', true),
            'timeout' => (int) env('PAYMENT_ZARINPAL_TIMEOUT', 10),
            'description' => env('PAYMENT_DESCRIPTION', 'پرداخت سفارش فروشگاه لوازم الکتریکی'),
        ],
    ],
];
