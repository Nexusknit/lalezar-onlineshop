<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:invoice.all')->only('all');
        $this->middleware('permission:invoice.items')->only('items');
        $this->middleware('permission:invoice.detail')->only('detail');
        $this->middleware('permission:invoice.user')->only('user');
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
}
