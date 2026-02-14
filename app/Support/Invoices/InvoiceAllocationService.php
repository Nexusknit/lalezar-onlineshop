<?php

namespace App\Support\Invoices;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Product;
use App\Support\Coupons\CouponService;
use Illuminate\Validation\ValidationException;

class InvoiceAllocationService
{
    public function releaseForFailedPayment(Invoice $invoice, ?string $reason = null): void
    {
        /** @var Invoice|null $lockedInvoice */
        $lockedInvoice = Invoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedInvoice) {
            return;
        }

        $meta = (array) ($lockedInvoice->meta ?? []);
        $allocation = (array) data_get($meta, 'allocation', []);

        if (isset($allocation['released_at']) && is_string($allocation['released_at'])) {
            return;
        }

        $items = $lockedInvoice->items()
            ->select(['id', 'product_id', 'quantity'])
            ->lockForUpdate()
            ->get();

        if ($items->isNotEmpty()) {
            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                /** @var Product|null $product */
                $product = $products->get((int) $item->product_id);
                if (! $product) {
                    continue;
                }

                $quantity = max(0, (int) $item->quantity);

                if ($product->stock !== null) {
                    $product->stock = max(0, (int) $product->stock + $quantity);
                }

                $product->sold_count = max(0, (int) ($product->sold_count ?? 0) - $quantity);
                $product->save();
            }
        }

        $this->releaseCouponUsage($lockedInvoice);

        $allocation['released_at'] = now()->toAtomString();
        $allocation['last_action'] = 'released';
        if (is_string($reason) && trim($reason) !== '') {
            $allocation['release_reason'] = trim($reason);
        }

        $meta['allocation'] = $allocation;
        $lockedInvoice->update(['meta' => $meta]);
    }

    public function reserveForRetry(Invoice $invoice): void
    {
        /** @var Invoice|null $lockedInvoice */
        $lockedInvoice = Invoice::query()
            ->whereKey($invoice->id)
            ->with(['user'])
            ->lockForUpdate()
            ->first();

        if (! $lockedInvoice) {
            return;
        }

        $meta = (array) ($lockedInvoice->meta ?? []);
        $allocation = (array) data_get($meta, 'allocation', []);
        $releasedAt = data_get($allocation, 'released_at');

        if (! is_string($releasedAt) || trim($releasedAt) === '') {
            return;
        }

        $items = $lockedInvoice->items()
            ->select(['id', 'product_id', 'quantity', 'name'])
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice does not have any reservable items.'],
            ]);
        }

        $products = Product::query()
            ->whereIn('id', $items->pluck('product_id')->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            /** @var Product|null $product */
            $product = $products->get((int) $item->product_id);
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["Product {$item->product_id} no longer exists."],
                ]);
            }

            $quantity = max(0, (int) $item->quantity);
            $stock = $product->stock !== null ? (int) $product->stock : null;

            if ($stock !== null && $stock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for {$product->name}."],
                ]);
            }
        }

        foreach ($items as $item) {
            /** @var Product $product */
            $product = $products->get((int) $item->product_id);
            $quantity = max(0, (int) $item->quantity);

            if ($product->stock !== null) {
                $product->stock = max(0, (int) $product->stock - $quantity);
            }

            $product->sold_count = max(0, (int) ($product->sold_count ?? 0) + $quantity);
            $product->save();
        }

        $this->reserveCouponUsage($lockedInvoice);

        $allocation['re_reserved_at'] = now()->toAtomString();
        $allocation['last_action'] = 'reserved';
        unset($allocation['released_at'], $allocation['release_reason']);
        if (! isset($allocation['reserved_at'])) {
            $allocation['reserved_at'] = now()->toAtomString();
        }

        $meta['allocation'] = $allocation;
        $lockedInvoice->update(['meta' => $meta]);
    }

    protected function releaseCouponUsage(Invoice $invoice): void
    {
        if (! $invoice->coupon_id) {
            return;
        }

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()->whereKey($invoice->coupon_id)->lockForUpdate()->first();
        if (! $coupon) {
            CouponUsage::query()
                ->where('invoice_id', $invoice->id)
                ->delete();
            return;
        }

        $usageQuery = CouponUsage::query()
            ->where('invoice_id', $invoice->id)
            ->where('coupon_id', $coupon->id)
            ->lockForUpdate();

        $usageCount = (int) $usageQuery->count();
        if ($usageCount < 1) {
            return;
        }

        $usageQuery->delete();
        $coupon->used_count = max(0, (int) ($coupon->used_count ?? 0) - $usageCount);
        $coupon->save();
    }

    protected function reserveCouponUsage(Invoice $invoice): void
    {
        if (! $invoice->coupon_id) {
            return;
        }

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()->whereKey($invoice->coupon_id)->lockForUpdate()->first();
        if (! $coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Coupon no longer exists.'],
            ]);
        }

        CouponService::validateCoupon(
            $coupon,
            (float) $invoice->subtotal,
            $invoice->currency,
            $invoice->user
        );

        $existingUsage = CouponUsage::query()
            ->where('invoice_id', $invoice->id)
            ->where('coupon_id', $coupon->id)
            ->lockForUpdate()
            ->exists();

        if ($existingUsage) {
            return;
        }

        CouponUsage::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'discount_amount' => (float) $invoice->discount,
            'used_at' => now(),
        ]);

        $coupon->increment('used_count');
    }
}
