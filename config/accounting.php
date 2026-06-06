<?php

return [
    'enabled' => env('ACCOUNTING_ENABLED', false),
    'provider' => env('ACCOUNTING_PROVIDER', 'generic_rest'),
    'base_url' => env('ACCOUNTING_BASE_URL'),
    'timeout' => (int) env('ACCOUNTING_TIMEOUT', 15),
    'verify_ssl' => env('ACCOUNTING_VERIFY_SSL', true),
    'token' => env('ACCOUNTING_TOKEN'),
    'api_key' => env('ACCOUNTING_API_KEY'),
    'api_key_header' => env('ACCOUNTING_API_KEY_HEADER', 'X-API-Key'),
    'health_path' => env('ACCOUNTING_HEALTH_PATH', '/health'),
    'products_path' => env('ACCOUNTING_PRODUCTS_PATH', '/products'),
    'invoices_path' => env('ACCOUNTING_INVOICES_PATH', '/invoices'),
    'product_sync_enabled' => env('ACCOUNTING_PRODUCT_SYNC_ENABLED', true),
    'invoice_sync_enabled' => env('ACCOUNTING_INVOICE_SYNC_ENABLED', true),
    'automatic_product_sync' => env('ACCOUNTING_AUTOMATIC_PRODUCT_SYNC', false),
    'product_sync_cron' => env('ACCOUNTING_PRODUCT_SYNC_CRON', '0 * * * *'),
    'product_creator_id' => env('ACCOUNTING_PRODUCT_CREATOR_ID'),
    'queue' => env('ACCOUNTING_QUEUE', 'accounting'),
    'per_page' => (int) env('ACCOUNTING_PRODUCTS_PER_PAGE', 100),
    'max_pages' => (int) env('ACCOUNTING_PRODUCTS_MAX_PAGES', 100),
];
