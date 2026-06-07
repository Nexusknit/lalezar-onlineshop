<?php

namespace App\Support\Accounting;

use App\Models\Invoice;

class InvoicePayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'user',
            'address.city.state',
            'items.product.accountingMapping',
            'items.variant',
            'payments',
        ]);

        $payment = $invoice->payments
            ->where('status', 'paid')
            ->sortByDesc('paid_at')
            ->first();
        $address = $invoice->address;

        return [
            'invoice_number' => $invoice->number,
            'invoice_id' => $invoice->id,
            'issued_at' => $invoice->issued_at?->toAtomString(),
            'paid_at' => $payment?->paid_at?->toAtomString(),
            'currency' => $invoice->currency,
            'amounts' => [
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount,
                'tax' => (float) $invoice->tax,
                'shipping' => (float) data_get($invoice->meta, 'shipping', 0),
                'total' => (float) $invoice->total,
            ],
            'payment' => [
                'method' => $payment?->method,
                'reference' => $payment?->reference,
            ],
            'customer' => [
                'id' => $invoice->user?->id,
                'name' => $invoice->user?->name,
                'phone' => $invoice->user?->phone,
                'email' => $invoice->user?->email,
            ],
            'address' => [
                'recipient_name' => $address?->recipient_name,
                'phone' => $address?->phone,
                'state' => $address?->city?->state?->name,
                'city' => $address?->city?->name,
                'street_line1' => $address?->street_line1,
                'street_line2' => $address?->street_line2,
                'postal_code' => $address?->postal_code,
                'building' => $address?->building,
                'unit' => $address?->unit,
            ],
            'items' => $invoice->items->map(static fn ($item): array => [
                'local_product_id' => $item->product_id,
                'external_product_id' => $item->product?->accountingMapping?->external_id,
                'sku' => data_get($item->meta, 'sku', $item->product?->sku),
                'local_variant_id' => $item->product_variant_id,
                'variant' => data_get($item->meta, 'variant'),
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values()->all(),
        ];
    }

    public function idempotencyKey(Invoice $invoice): string
    {
        return 'invoice-'.hash('sha256', $invoice->id.'|'.$invoice->number);
    }
}
