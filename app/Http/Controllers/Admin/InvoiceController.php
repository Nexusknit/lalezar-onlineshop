<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Invoices\InvoiceAllocationService;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceAllocationService $invoiceAllocationService
    )
    {
        $this->middleware('permission:invoice.all')->only('all');
        $this->middleware('permission:invoice.items')->only('items');
        $this->middleware('permission:invoice.detail')->only('detail');
        $this->middleware('permission:invoice.user')->only('user');
        $this->middleware('permission:invoice.updateStatus')->only('updateStatus');
    }

    #[OA\Get(
        path: '/api/admin/invoices',
        operationId: 'adminInvoicesIndex',
        summary: 'List invoices',
        security: [['sanctum' => []]],
        tags: ['Admin - Invoices'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Invoices retrieved', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $invoices = Invoice::query()
            ->with(['user'])
            ->when($request->filled('status'), static function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($invoices);
    }

    #[OA\Get(
        path: '/api/admin/invoices/{invoice}/items',
        operationId: 'adminInvoicesItems',
        summary: 'List invoice items',
        security: [['sanctum' => []]],
        tags: ['Admin - Invoices'],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Items retrieved', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function items(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load('items')->items);
    }

    #[OA\Get(
        path: '/api/admin/invoices/{invoice}',
        operationId: 'adminInvoicesShow',
        summary: 'Invoice detail',
        security: [['sanctum' => []]],
        tags: ['Admin - Invoices'],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Invoice detail', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function detail(Invoice $invoice): JsonResponse
    {
        return response()->json(
            $invoice->load(['user', 'items', 'payments', 'tags', 'categories'])
        );
    }

    #[OA\Get(
        path: '/api/admin/users/{user}/invoices',
        operationId: 'adminInvoicesForUser',
        summary: 'Invoices for a user',
        security: [['sanctum' => []]],
        tags: ['Admin - Invoices'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Invoices retrieved', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function user(User $user): JsonResponse
    {
        $invoices = $user->invoices()->with(['items', 'payments'])->get();

        return response()->json($invoices);
    }

    #[OA\Patch(
        path: '/api/admin/invoices/{invoice}/status',
        operationId: 'adminInvoicesUpdateStatus',
        summary: 'Update invoice status',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Invoices'],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Invoice status updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(InvoiceStatusService::values())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Invoice $updatedInvoice */
        $updatedInvoice = DB::transaction(function () use ($invoice, $data, $request): Invoice {
            /** @var Invoice|null $lockedInvoice */
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->first();
            abort_if(! $lockedInvoice, 404, 'Invoice not found.');

            $fromStatus = (string) $lockedInvoice->status;
            $toStatus = (string) $data['status'];

            abort_if(
                ! InvoiceStatusService::canTransition($fromStatus, $toStatus),
                422,
                'Invoice status transition is not allowed.'
            );

            if (
                $fromStatus === InvoiceStatusService::PAYMENT_FAILED &&
                $toStatus === InvoiceStatusService::PAYMENT_PENDING
            ) {
                $this->invoiceAllocationService->reserveForRetry($lockedInvoice);
            }

            $meta = (array) ($lockedInvoice->meta ?? []);
            $meta['admin_status_update'] = [
                'from' => $fromStatus,
                'to' => $toStatus,
                'updated_by' => (int) $request->user()->id,
                'updated_at' => now()->toAtomString(),
            ];

            if (isset($data['note']) && $data['note'] !== '') {
                $meta['admin_status_update']['note'] = $data['note'];
            }

            $lockedInvoice->update([
                'status' => $toStatus,
                'meta' => $meta,
            ]);

            if ($toStatus === InvoiceStatusService::PAYMENT_FAILED) {
                $this->invoiceAllocationService->releaseForFailedPayment(
                    $lockedInvoice,
                    isset($data['note']) ? (string) $data['note'] : 'admin_status_update'
                );
            }

            return $lockedInvoice->fresh()->load(['user', 'items', 'payments', 'tags', 'categories']);
        });

        return response()->json($updatedInvoice);
    }
}
