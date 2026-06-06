<?php

namespace App\Jobs;

use App\Models\AccountingSyncLog;
use App\Support\Accounting\AccountingConfiguration;
use App\Support\Accounting\Contracts\AccountingProviderInterface;
use App\Support\Accounting\ProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportAccountingProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $syncLogId
    ) {}

    public function handle(
        AccountingConfiguration $configuration,
        AccountingProviderInterface $provider,
        ProductImportService $importService
    ): void {
        $log = AccountingSyncLog::query()->find($this->syncLogId);
        if (! $log) {
            return;
        }

        if (! $configuration->productSyncEnabled()) {
            $log->update([
                'status' => AccountingSyncLog::STATUS_SKIPPED,
                'error' => 'Accounting product sync is disabled.',
                'finished_at' => now(),
            ]);

            return;
        }

        $log->update([
            'status' => AccountingSyncLog::STATUS_PROCESSING,
            'attempts' => $this->attempts(),
            'started_at' => $log->started_at ?? now(),
            'error' => null,
        ]);

        try {
            $result = $importService->import($provider);
            $log->update([
                'status' => AccountingSyncLog::STATUS_SUCCEEDED,
                'response' => $result,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => AccountingSyncLog::STATUS_FAILED,
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        AccountingSyncLog::query()->whereKey($this->syncLogId)->update([
            'status' => AccountingSyncLog::STATUS_FAILED,
            'error' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
