<?php

return [
    'provider' => env('OTP_PROVIDER', 'kavenegar'),
    'ttl_minutes' => max(1, (int) env('OTP_TTL_MINUTES', 5)),

    'providers' => [
        'kavenegar' => [
            'enabled' => (bool) env('OTP_KAVENEGAR_ENABLED', false),
            'sender' => env('OTP_KAVENEGAR_SENDER'),
            'template' => env('OTP_KAVENEGAR_TEMPLATE'),
            'message' => env('OTP_KAVENEGAR_MESSAGE', 'کد تایید ورود شما: :token'),
            'timeout' => max(3, (int) env('OTP_KAVENEGAR_TIMEOUT', 10)),
        ],
    ],
];

