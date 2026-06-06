<?php

namespace App\Support\Accounting;

use App\Jobs\ImportAccountingProductsJob;
use App\Jobs\PushInvoiceToAccountingJob;
use App\Models\AccountingSyncLog;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Throwable;

class AccountingOutboxService
{
    public function __construct(
        private readonly AccountingConfiguration $configuration
    ) {}

    public function dispatchProductImport(?int $triggeredBy = null, ?int $retryOf = null): ?AccountingSyncLog
    {
        if (! $this->configuration->productSyncEnabled()) {
            return null;
        }

        $log = AccountingSyncLog::query()->create([
            'retry_of_id' => $retryOf,
            'triggered_by' => $triggeredBy,
            'provider' => $this->configuration->provider(),
            'operation' => 'product_import',
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);

        try {
            ImportAccountingProductsJob::dispatch($log->id)
                ->onQueue($this->configuration->queue());
        } catch (Throwable $exception) {
            $log->update([
                'status' => AccountingSyncLog::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return $log;
    }

    public function dispatchPaidInvoice(Invoice $invoice, ?int $triggeredBy = null, ?int $retryOf = null): ?AccountingSyncLog
    {
        if (! $this->configuration->invoiceSyncEnabled() || (string) $invoice->status !== 'paid') {
            return null;
        }

        $existing = AccountingSyncLog::query()
            ->where('operation', 'invoice_push')
            ->where('syncable_type', Invoice::class)
            ->where('syncable_id', $invoice->id)
            ->whereIn('status', [
                AccountingSyncLog::STATUS_QUEUED,
                AccountingSyncLog::STATUS_PROCESSING,
                AccountingSyncLog::STATUS_SUCCEEDED,
            ])
            ->latest('id')
            ->first();

        if ($existing && $retryOf === null) {
            return $existing;
        }

        $log = AccountingSyncLog::query()->create([
            'retry_of_id' => $retryOf,
            'triggered_by' => $triggeredBy,
            'provider' => $this->configuration->provider(),
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $invoice->id,
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);

        try {
            PushInvoiceToAccountingJob::dispatch($log->id, $invoice->id)
                ->onQueue($this->configuration->queue());
        } catch (Throwable $exception) {
            $log->update([
                'status' => AccountingSyncLog::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return $log;
    }

    public function dispatchPaidInvoiceSafely(Invoice $invoice): void
    {
        try {
            $this->dispatchPaidInvoice($invoice);
        } catch (Throwable $exception) {
            Log::error('Unable to queue accounting invoice sync.', [
                'invoice_id' => $invoice->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function retry(AccountingSyncLog $failedLog, ?int $triggeredBy = null): ?AccountingSyncLog
    {
        if ($failedLog->status !== AccountingSyncLog::STATUS_FAILED) {
            return null;
        }

        if ($failedLog->operation === 'product_import') {
            return $this->dispatchProductImport($triggeredBy, $failedLog->id);
        }

        if ($failedLog->operation === 'invoice_push' && $failedLog->syncable_type === Invoice::class) {
            $invoice = Invoice::query()->find($failedLog->syncable_id);

            return $invoice ? $this->dispatchPaidInvoice($invoice, $triggeredBy, $failedLog->id) : null;
        }

        return null;
    }
}
