<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountingSyncLog;
use App\Models\Invoice;
use App\Support\Accounting\AccountingConfiguration;
use App\Support\Accounting\AccountingOutboxService;
use App\Support\Accounting\Contracts\AccountingProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class AccountingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:accounting.view')->only('index');
        $this->middleware('permission:accounting.test')->only('testConnection');
        $this->middleware('permission:accounting.sync')->only(['syncProducts', 'syncInvoice']);
        $this->middleware('permission:accounting.retry')->only('retry');
    }

    public function index(Request $request, AccountingConfiguration $configuration): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'processing', 'succeeded', 'failed', 'skipped'])],
            'operation' => ['nullable', Rule::in(['health_check', 'product_import', 'invoice_push'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = AccountingSyncLog::query()
            ->with(['triggeredBy:id,name,phone'])
            ->latest('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['operation'])) {
            $query->where('operation', $data['operation']);
        }

        return response()->json([
            'configuration' => $configuration->publicSummary(),
            'summary' => [
                'queued' => AccountingSyncLog::query()->where('status', AccountingSyncLog::STATUS_QUEUED)->count(),
                'processing' => AccountingSyncLog::query()->where('status', AccountingSyncLog::STATUS_PROCESSING)->count(),
                'succeeded' => AccountingSyncLog::query()->where('status', AccountingSyncLog::STATUS_SUCCEEDED)->count(),
                'failed' => AccountingSyncLog::query()->where('status', AccountingSyncLog::STATUS_FAILED)->count(),
                'last_success_at' => AccountingSyncLog::query()
                    ->where('status', AccountingSyncLog::STATUS_SUCCEEDED)
                    ->latest('finished_at')
                    ->value('finished_at'),
            ],
            'logs' => $query->paginate((int) ($data['per_page'] ?? 20)),
        ]);
    }

    public function testConnection(
        Request $request,
        AccountingConfiguration $configuration,
        AccountingProviderInterface $provider
    ): JsonResponse {
        abort_unless($configuration->configured(), 422, 'Accounting base URL is not configured.');

        $log = AccountingSyncLog::query()->create([
            'triggered_by' => $request->user()?->id,
            'provider' => $configuration->provider(),
            'operation' => 'health_check',
            'status' => AccountingSyncLog::STATUS_PROCESSING,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $result = $provider->healthCheck();
            $log->update([
                'status' => AccountingSyncLog::STATUS_SUCCEEDED,
                'response' => $result,
                'finished_at' => now(),
            ]);

            return response()->json([
                'message' => 'Accounting connection is healthy.',
                'result' => $result,
                'log' => $log->fresh(),
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => AccountingSyncLog::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            return response()->json([
                'message' => 'Accounting connection failed.',
                'error' => $exception->getMessage(),
                'log' => $log->fresh(),
            ], 422);
        }
    }

    public function syncProducts(
        Request $request,
        AccountingConfiguration $configuration,
        AccountingOutboxService $outbox
    ): JsonResponse {
        abort_unless($configuration->productSyncEnabled(), 422, 'Accounting product sync is disabled.');
        abort_unless($configuration->configured(), 422, 'Accounting base URL is not configured.');

        $log = $outbox->dispatchProductImport($request->user()?->id);

        return response()->json([
            'message' => 'Product synchronization was queued.',
            'log' => $log,
        ], 202);
    }

    public function syncInvoice(
        Request $request,
        Invoice $invoice,
        AccountingConfiguration $configuration,
        AccountingOutboxService $outbox
    ): JsonResponse {
        abort_unless($configuration->invoiceSyncEnabled(), 422, 'Accounting invoice sync is disabled.');
        abort_unless($configuration->configured(), 422, 'Accounting base URL is not configured.');
        abort_unless((string) $invoice->status === 'paid', 422, 'Only paid invoices can be synchronized.');

        $log = $outbox->dispatchPaidInvoice($invoice, $request->user()?->id);

        return response()->json([
            'message' => 'Invoice synchronization was queued or already completed.',
            'log' => $log,
        ], 202);
    }

    public function retry(
        Request $request,
        AccountingSyncLog $syncLog,
        AccountingOutboxService $outbox
    ): JsonResponse {
        abort_unless($syncLog->status === AccountingSyncLog::STATUS_FAILED, 422, 'Only failed syncs can be retried.');

        $log = $outbox->retry($syncLog, $request->user()?->id);
        abort_unless($log, 422, 'This sync operation cannot be retried.');

        return response()->json([
            'message' => 'Accounting synchronization retry was queued.',
            'log' => $log,
        ], 202);
    }
}
