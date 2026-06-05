<?php

$defaultAdminSeedEnabled = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    'admin_seed' => [
        'enabled' => (bool) env('ADMIN_SEED_ENABLED', $defaultAdminSeedEnabled),
        'name' => env('ADMIN_SEED_NAME', 'مدیر فروشگاه'),
        'email' => env('ADMIN_SEED_EMAIL', 'admin@lalezar.local'),
        'phone' => env('ADMIN_SEED_PHONE', '09120000000'),
        'password' => env('ADMIN_SEED_PASSWORD'),
        'unsafe_passwords' => [
            'password',
            'admin',
            'administrator',
            'secret',
            'secret123',
            '12345678',
            'change-me',
            'change-this-admin-password',
        ],
    ],
];
