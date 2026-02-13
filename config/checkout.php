<?php

return [
    'shipping' => [
        // Flat shipping fee applied to orders below free_threshold.
        'flat_fee' => (float) env('CHECKOUT_SHIPPING_FLAT_FEE', 0),

        // Set to null to disable free shipping threshold.
        'free_threshold' => env('CHECKOUT_SHIPPING_FREE_THRESHOLD') !== null
            ? (float) env('CHECKOUT_SHIPPING_FREE_THRESHOLD')
            : null,
    ],

    'tax' => [
        // Enable tax calculation on (subtotal_after_discount + shipping).
        'enabled' => filter_var(env('CHECKOUT_TAX_ENABLED', false), FILTER_VALIDATE_BOOL),
        'rate_percent' => (float) env('CHECKOUT_TAX_RATE_PERCENT', 0),
    ],
];
