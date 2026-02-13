<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $invoices = $request->user()
            ->invoices()
            ->with(['items', 'address.city.state', 'payments', 'coupon'])
            ->when($request->filled('status'), static function ($query) use ($request): void {
                $query->where('status', $request->string('status'));
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        abort_if($invoice->user_id !== $request->user()->id, 404, 'Invoice not found.');

        return response()->json(
            $invoice->load(['items', 'address.city.state', 'payments', 'coupon'])
        );
    }
}
