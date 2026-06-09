<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class ExpiredReservationService
{
    public function __construct(
        private readonly InvoiceAllocationService $allocationService
    ) {}

    public function releaseExpired(int $limit = 500): int
    {
        $invoiceIds = Invoice::query()
            ->whereIn('status', [
                InvoiceStatusService::PENDING,
                InvoiceStatusService::PAYMENT_PENDING,
            ])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $released = 0;
        foreach ($invoiceIds as $invoiceId) {
            $didRelease = DB::transaction(function () use ($invoiceId): bool {
                $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
                if (
                    ! $invoice
                    || ! in_array((string) $invoice->status, [
                        InvoiceStatusService::PENDING,
                        InvoiceStatusService::PAYMENT_PENDING,
                    ], true)
                    || ! $invoice->due_at
                    || $invoice->due_at->isFuture()
                ) {
                    return false;
                }

                $this->allocationService->releaseForFailedPayment($invoice, 'reservation_expired');

                Payment::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Payment $payment): void {
                        $meta = (array) ($payment->meta ?? []);
                        $meta['reason'] = 'reservation_expired';
                        $meta['expired_at'] = now()->toAtomString();
                        $payment->update(['status' => 'failed', 'meta' => $meta]);
                    });

                $meta = (array) ($invoice->fresh()->meta ?? []);
                $meta['payment'] = array_merge((array) ($meta['payment'] ?? []), [
                    'status' => 'failed',
                    'reason' => 'reservation_expired',
                    'expired_at' => now()->toAtomString(),
                ]);
                $invoice->update([
                    'status' => InvoiceStatusService::PAYMENT_FAILED,
                    'due_at' => null,
                    'meta' => $meta,
                ]);

                return true;
            });

            $released += $didRelease ? 1 : 0;
        }

        return $released;
    }
}
