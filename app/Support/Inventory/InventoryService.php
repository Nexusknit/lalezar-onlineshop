<?php

namespace App\Support\Inventory;

use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function assertPurchasable(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        $target = $variant ?? $product;
        $name = $variant ? "{$product->name} - {$variant->name}" : $product->name;
        $minimum = max(1, (int) ($target->min_order_quantity ?? 1));
        $maximum = $target->max_order_quantity !== null ? (int) $target->max_order_quantity : null;

        if ($variant && ((int) $variant->product_id !== (int) $product->id || $variant->status !== 'active')) {
            throw ValidationException::withMessages(['variant' => ['Selected product variant is unavailable.']]);
        }

        if ($quantity < $minimum) {
            throw ValidationException::withMessages([
                'quantity' => ["Minimum order quantity for {$name} is {$minimum}."],
            ]);
        }

        if ($maximum !== null && $quantity > $maximum) {
            throw ValidationException::withMessages([
                'quantity' => ["Maximum order quantity for {$name} is {$maximum}."],
            ]);
        }

        $available = max(0, (int) $target->stock - (int) $target->stock_reserved);
        if ($available < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Insufficient stock for {$name}."],
            ]);
        }
    }

    public function reserve(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ?Invoice $invoice = null,
        ?int $actorId = null,
        ?string $reason = null
    ): void {
        $target = $variant ?? $product;
        $this->assertPurchasable($product, $variant, $quantity);
        $target->stock_reserved = (int) $target->stock_reserved + $quantity;
        $target->save();
        $this->record($product, $variant, $invoice, $actorId, 'reserve', 0, $quantity, $reason);
    }

    public function release(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ?Invoice $invoice = null,
        ?int $actorId = null,
        ?string $reason = null
    ): void {
        $target = $variant ?? $product;
        $released = min(max(0, $quantity), (int) $target->stock_reserved);
        if ($released === 0) {
            return;
        }
        $target->stock_reserved = (int) $target->stock_reserved - $released;
        $target->save();
        $this->record($product, $variant, $invoice, $actorId, 'release', 0, -$released, $reason);
    }

    public function commitSale(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ?Invoice $invoice = null
    ): void {
        $target = $variant ?? $product;
        $quantity = max(0, $quantity);
        if ((int) $target->stock_reserved < $quantity || (int) $target->stock < $quantity) {
            throw ValidationException::withMessages(['items' => ['Reserved inventory is no longer valid.']]);
        }

        $target->stock = (int) $target->stock - $quantity;
        $target->stock_reserved = (int) $target->stock_reserved - $quantity;
        $target->save();
        $product->sold_count = max(0, (int) $product->sold_count + $quantity);
        $product->save();
        $this->record($product, $variant, $invoice, null, 'sale', -$quantity, -$quantity);
    }

    public function restoreSale(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ?Invoice $invoice = null,
        ?int $actorId = null,
        ?string $reason = null
    ): void {
        $target = $variant ?? $product;
        $quantity = max(0, $quantity);
        $target->stock = (int) $target->stock + $quantity;
        $target->save();
        $product->sold_count = max(0, (int) $product->sold_count - $quantity);
        $product->save();
        $this->record($product, $variant, $invoice, $actorId, 'restore', $quantity, 0, $reason);
    }

    private function record(
        Product $product,
        ?ProductVariant $variant,
        ?Invoice $invoice,
        ?int $actorId,
        string $type,
        int $stockDelta,
        int $reservedDelta,
        ?string $reason = null
    ): void {
        /** @var Product|ProductVariant $target */
        $target = $variant ?? $product;

        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'invoice_id' => $invoice?->id,
            'actor_id' => $actorId,
            'type' => $type,
            'stock_delta' => $stockDelta,
            'reserved_delta' => $reservedDelta,
            'stock_after' => (int) $target->stock,
            'reserved_after' => (int) $target->stock_reserved,
            'reason' => $reason,
        ]);
    }
}
