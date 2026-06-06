<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:shipment.update');
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Shipment::STATUSES)],
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_code' => ['nullable', 'string', 'max:120'],
        ]);

        $shipment = DB::transaction(function () use ($shipment, $data): Shipment {
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (in_array($data['status'], ['handed_over', 'in_transit'], true) && ! $locked->shipped_at) {
                $data['shipped_at'] = now();
            }
            if ($data['status'] === 'delivered' && ! $locked->delivered_at) {
                $data['delivered_at'] = now();
            }

            $locked->update($data);
            $invoice = $locked->invoice()->lockForUpdate()->first();

            if ($invoice) {
                $targets = match ($data['status']) {
                    'ready' => [InvoiceStatusService::PROCESSING],
                    'handed_over', 'in_transit' => [
                        InvoiceStatusService::PROCESSING,
                        InvoiceStatusService::SHIPPED,
                    ],
                    'delivered' => [
                        InvoiceStatusService::PROCESSING,
                        InvoiceStatusService::SHIPPED,
                        InvoiceStatusService::DELIVERED,
                    ],
                    default => [],
                };

                foreach ($targets as $target) {
                    if (InvoiceStatusService::canTransition((string) $invoice->status, $target)) {
                        $invoice->update(['status' => $target]);
                    }
                }
            }

            return $locked;
        });

        return response()->json($shipment->fresh()->load('shippingMethod'));
    }
}
