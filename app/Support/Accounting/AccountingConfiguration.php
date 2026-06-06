<?php

namespace App\Support\Accounting;

use App\Support\Settings\StoreSettingService;

class AccountingConfiguration
{
    public function enabled(): bool
    {
        return StoreSettingService::boolean('accounting', 'enabled', (bool) config('accounting.enabled', false));
    }

    public function productSyncEnabled(): bool
    {
        return $this->enabled()
            && StoreSettingService::boolean(
                'accounting',
                'product_sync_enabled',
                (bool) config('accounting.product_sync_enabled', true)
            );
    }

    public function invoiceSyncEnabled(): bool
    {
        return $this->enabled()
            && StoreSettingService::boolean(
                'accounting',
                'invoice_sync_enabled',
                (bool) config('accounting.invoice_sync_enabled', true)
            );
    }

    public function automaticProductSyncEnabled(): bool
    {
        return $this->productSyncEnabled()
            && StoreSettingService::boolean(
                'accounting',
                'automatic_product_sync',
                (bool) config('accounting.automatic_product_sync', false)
            );
    }

    public function provider(): string
    {
        return trim((string) StoreSettingService::value(
            'accounting',
            'provider',
            config('accounting.provider', 'generic_rest')
        ));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) StoreSettingService::value(
            'accounting',
            'base_url',
            config('accounting.base_url', '')
        )), '/');
    }

    public function healthPath(): string
    {
        return $this->path('health_path', (string) config('accounting.health_path', '/health'));
    }

    public function productsPath(): string
    {
        return $this->path('products_path', (string) config('accounting.products_path', '/products'));
    }

    public function invoicesPath(): string
    {
        return $this->path('invoices_path', (string) config('accounting.invoices_path', '/invoices'));
    }

    public function configured(): bool
    {
        return $this->baseUrl() !== '';
    }

    public function queue(): string
    {
        return trim((string) config('accounting.queue', 'accounting')) ?: 'accounting';
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSummary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'provider' => $this->provider(),
            'base_url' => $this->baseUrl(),
            'health_path' => $this->healthPath(),
            'products_path' => $this->productsPath(),
            'invoices_path' => $this->invoicesPath(),
            'product_sync_enabled' => $this->productSyncEnabled(),
            'invoice_sync_enabled' => $this->invoiceSyncEnabled(),
            'automatic_product_sync' => $this->automaticProductSyncEnabled(),
            'configured' => $this->configured(),
            'credentials_configured' => $this->credentialsConfigured(),
            'secrets_managed_by_env' => true,
        ];
    }

    public function credentialsConfigured(): bool
    {
        return trim((string) config('accounting.token', '')) !== ''
            || trim((string) config('accounting.api_key', '')) !== '';
    }

    private function path(string $key, string $default): string
    {
        $value = trim((string) StoreSettingService::value('accounting', $key, $default));

        return '/'.ltrim($value, '/');
    }
}
