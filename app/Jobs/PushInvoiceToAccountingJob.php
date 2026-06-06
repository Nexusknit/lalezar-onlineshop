<?php

namespace App\Jobs;

use App\Models\AccountingInvoiceMapping;
use App\Models\AccountingSyncLog;
use App\Models\Invoice;
use App\Support\Accounting\AccountingConfiguration;
use App\Support\Accounting\Contracts\AccountingProviderInterface;
use App\Support\Accounting\InvoicePayloadBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PushInvoiceToAccountingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $syncLogId,
        public readonly int $invoiceId
    ) {}

    public function handle(
        AccountingConfiguration $configuration,
        AccountingProviderInterface $provider,
        InvoicePayloadBuilder $payloadBuilder
    ): void {
        $log = AccountingSyncLog::query()->find($this->syncLogId);
        $invoice = Invoice::query()->find($this->invoiceId);
        if (! $log || ! $invoice) {
            return;
        }

        if (! $configuration->invoiceSyncEnabled() || (string) $invoice->status !== 'paid') {
            $log->update([
                'status' => AccountingSyncLog::STATUS_SKIPPED,
                'error' => 'Accounting invoice sync is disabled or invoice is not paid.',
                'finished_at' => now(),
            ]);

            return;
        }

        $payload = $payloadBuilder->build($invoice);
        $idempotencyKey = $payloadBuilder->idempotencyKey($invoice);
        $mapping = AccountingInvoiceMapping::query()->firstOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => $provider->name(),
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
            ]
        );

        if ($mapping->status === 'succeeded') {
            $log->update([
                'status' => AccountingSyncLog::STATUS_SUCCEEDED,
                'payload' => $payload,
                'response' => ['idempotent' => true, 'external_id' => $mapping->external_id],
                'finished_at' => now(),
            ]);

            return;
        }

        $log->update([
            'status' => AccountingSyncLog::STATUS_PROCESSING,
            'attempts' => $this->attempts(),
            'payload' => $payload,
            'started_at' => $log->started_at ?? now(),
            'error' => null,
        ]);

        try {
            $response = $provider->pushInvoice($payload, $idempotencyKey);

            DB::transaction(function () use ($mapping, $log, $response): void {
                $mapping->update([
                    'external_id' => $response['external_id'] ?? $mapping->external_id,
                    'status' => 'succeeded',
                    'last_synced_at' => now(),
                    'meta' => ['response' => $response],
                ]);
                $log->update([
                    'status' => AccountingSyncLog::STATUS_SUCCEEDED,
                    'response' => $response,
                    'finished_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $mapping->update([
                'status' => 'failed',
                'meta' => ['last_error' => $exception->getMessage()],
            ]);
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
