<?php

return [
    'default_provider' => env('PAYMENT_PROVIDER', 'mock_gateway'),
    'callback_base_url' => env('PAYMENT_CALLBACK_BASE_URL', ''),
    'frontend_callback_url' => env('PAYMENT_FRONTEND_CALLBACK_URL'),

    'providers' => [
        'mock_gateway' => [
            'enabled' => (bool) env('PAYMENT_MOCK_ENABLED', true),
        ],

        'zarinpal' => [
            'enabled' => (bool) env('PAYMENT_ZARINPAL_ENABLED', false),
            'merchant_id' => env('PAYMENT_ZARINPAL_MERCHANT_ID'),
            'sandbox' => (bool) env('PAYMENT_ZARINPAL_SANDBOX', true),
            'timeout' => (int) env('PAYMENT_ZARINPAL_TIMEOUT', 10),
            'description' => env('PAYMENT_ZARINPAL_DESCRIPTION', 'Online order payment'),
        ],
    ],
];
