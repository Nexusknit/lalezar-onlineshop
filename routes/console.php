<?php

use App\Support\Accounting\AccountingConfiguration;
use App\Support\Accounting\AccountingOutboxService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('accounting:sync-products', function (
    AccountingConfiguration $configuration,
    AccountingOutboxService $outbox
) {
    if (! $configuration->productSyncEnabled()) {
        $this->warn('Accounting product synchronization is disabled.');

        return self::FAILURE;
    }

    $log = $outbox->dispatchProductImport();
    $this->info('Accounting product synchronization queued as log #'.$log?->id.'.');

    return self::SUCCESS;
})->purpose('Queue product synchronization from the configured accounting service');

Schedule::command('accounting:sync-products')
    ->cron((string) config('accounting.product_sync_cron', '0 * * * *'))
    ->withoutOverlapping()
    ->when(fn (): bool => app(AccountingConfiguration::class)->automaticProductSyncEnabled());
