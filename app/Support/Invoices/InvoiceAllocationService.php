<?php

namespace App\Support\Invoices;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Coupons\CouponService;
use App\Support\Inventory\InventoryService;
use Illuminate\Validation\ValidationException;

class InvoiceAllocationService
{
    public function __construct(protected InventoryService $inventoryService) {}

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

        if (($allocation['state'] ?? 'reserved') !== 'reserved') {
            return;
        }

        $items = $lockedInvoice->items()
            ->select(['id', 'product_id', 'product_variant_id', 'quantity'])
            ->lockForUpdate()
            ->get();

        if ($items->isNotEmpty()) {
            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $variants = ProductVariant::query()
                ->whereIn('id', $items->pluck('product_variant_id')->filter()->all())
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
                $variant = $item->product_variant_id
                    ? $variants->get((int) $item->product_variant_id)
                    : null;
                $this->inventoryService->release(
                    $product,
                    $variant,
                    $quantity,
                    $lockedInvoice,
                    null,
                    $reason
                );
            }
        }

        $this->releaseCouponUsage($lockedInvoice);

        $allocation['released_at'] = now()->toAtomString();
        $allocation['last_action'] = 'released';
        $allocation['state'] = 'released';
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

        if (($allocation['state'] ?? null) !== 'released' || ! is_string($releasedAt) || trim($releasedAt) === '') {
            return;
        }

        $items = $lockedInvoice->items()
            ->select(['id', 'product_id', 'product_variant_id', 'quantity', 'name'])
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
        $variants = ProductVariant::query()
            ->whereIn('id', $items->pluck('product_variant_id')->filter()->all())
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
            $variant = $item->product_variant_id
                ? $variants->get((int) $item->product_variant_id)
                : null;
            $this->inventoryService->assertPurchasable($product, $variant, $quantity);
        }

        foreach ($items as $item) {
            /** @var Product $product */
            $product = $products->get((int) $item->product_id);
            $quantity = max(0, (int) $item->quantity);
            $variant = $item->product_variant_id
                ? $variants->get((int) $item->product_variant_id)
                : null;
            $this->inventoryService->reserve($product, $variant, $quantity, $lockedInvoice);
        }

        $this->reserveCouponUsage($lockedInvoice);

        $allocation['re_reserved_at'] = now()->toAtomString();
        $allocation['last_action'] = 'reserved';
        $allocation['state'] = 'reserved';
        unset($allocation['released_at'], $allocation['release_reason']);
        if (! isset($allocation['reserved_at'])) {
            $allocation['reserved_at'] = now()->toAtomString();
        }

        $meta['allocation'] = $allocation;
        $lockedInvoice->update(['meta' => $meta]);
    }

    public function commitForPaidPayment(Invoice $invoice): void
    {
        /** @var Invoice|null $lockedInvoice */
        $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
        if (! $lockedInvoice) {
            return;
        }
        $meta = (array) ($lockedInvoice->meta ?? []);
        $allocation = (array) data_get($meta, 'allocation', []);
        if (($allocation['state'] ?? 'reserved') !== 'reserved') {
            return;
        }

        [$items, $products, $variants] = $this->lockedTargets($lockedInvoice);
        foreach ($items as $item) {
            $product = $products->get((int) $item->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['items' => ['A purchased product no longer exists.']]);
            }
            $variant = $item->product_variant_id
                ? $variants->get((int) $item->product_variant_id)
                : null;
            $this->inventoryService->commitSale(
                $product,
                $variant,
                max(0, (int) $item->quantity),
                $lockedInvoice
            );
        }

        $allocation['committed_at'] = now()->toAtomString();
        $allocation['last_action'] = 'committed';
        $allocation['state'] = 'committed';
        $meta['allocation'] = $allocation;
        $lockedInvoice->update(['meta' => $meta]);
    }

    public function restoreForCancelledOrRefunded(
        Invoice $invoice,
        ?int $actorId = null,
        ?string $reason = null
    ): void {
        /** @var Invoice|null $lockedInvoice */
        $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
        if (! $lockedInvoice) {
            return;
        }
        $meta = (array) ($lockedInvoice->meta ?? []);
        $allocation = (array) data_get($meta, 'allocation', []);
        $state = $allocation['state'] ?? null;

        if ($state === 'reserved') {
            $this->releaseForFailedPayment($lockedInvoice, $reason ?? 'invoice_cancelled');

            return;
        }
        if ($state !== 'committed') {
            return;
        }

        [$items, $products, $variants] = $this->lockedTargets($lockedInvoice);
        foreach ($items as $item) {
            $product = $products->get((int) $item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $item->product_variant_id
                ? $variants->get((int) $item->product_variant_id)
                : null;
            $this->inventoryService->restoreSale(
                $product,
                $variant,
                max(0, (int) $item->quantity),
                $lockedInvoice,
                $actorId,
                $reason
            );
        }

        $allocation['restored_at'] = now()->toAtomString();
        $allocation['last_action'] = 'restored';
        $allocation['state'] = 'restored';
        $meta['allocation'] = $allocation;
        $lockedInvoice->update(['meta' => $meta]);
    }

    private function lockedTargets(Invoice $invoice): array
    {
        $items = $invoice->items()
            ->select(['id', 'product_id', 'product_variant_id', 'quantity'])
            ->lockForUpdate()
            ->get();
        $products = Product::query()
            ->whereIn('id', $items->pluck('product_id')->filter()->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->whereIn('id', $items->pluck('product_variant_id')->filter()->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$items, $products, $variants];
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
