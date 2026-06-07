<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:product.update');
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request);
        $variant = $product->variants()->create($data);

        PriceHistory::query()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'actor_id' => $request->user()?->id,
            'new_price' => $variant->price,
            'currency' => $product->currency,
            'reason' => 'variant_created',
        ]);
        $this->recordStockChange($product, $variant, 0, (int) $variant->stock, $request->user()?->id, 'variant_created');

        return response()->json($variant->fresh(), 201);
    }

    public function update(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        abort_if((int) $variant->product_id !== (int) $product->id, 404);
        $data = $this->validated($request, $variant);
        $oldPrice = (float) $variant->price;
        $oldStock = (int) $variant->stock;
        $nextStock = array_key_exists('stock', $data) ? (int) $data['stock'] : $oldStock;
        abort_if($nextStock < (int) $variant->stock_reserved, 422, 'Stock cannot be lower than reserved stock.');
        $variant->fill($data)->save();

        if (array_key_exists('price', $data) && (float) $variant->price !== $oldPrice) {
            PriceHistory::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'actor_id' => $request->user()?->id,
                'old_price' => $oldPrice,
                'new_price' => $variant->price,
                'currency' => $product->currency,
                'reason' => 'admin_update',
            ]);
        }

        if (array_key_exists('stock', $data) && (int) $variant->stock !== $oldStock) {
            $this->recordStockChange(
                $product,
                $variant,
                $oldStock,
                (int) $variant->stock,
                $request->user()?->id,
                'admin_update'
            );
        }

        return response()->json($variant->fresh());
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        abort_if((int) $variant->product_id !== (int) $product->id, 404);
        abort_if((int) $variant->stock_reserved > 0, 422, 'A variant with reserved stock cannot be deleted.');
        abort_if($variant->cartItems()->exists(), 422, 'Remove this variant from active carts before deleting it.');
        $variant->delete();

        return response()->json(['message' => 'Product variant deleted successfully.']);
    }

    private function validated(Request $request, ?ProductVariant $variant = null): array
    {
        return $request->validate([
            'name' => [$variant ? 'sometimes' : 'required', 'string', 'max:255'],
            'sku' => [
                $variant ? 'sometimes' : 'required',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')->ignore($variant?->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_variants', 'barcode')->ignore($variant?->id),
            ],
            'price' => [$variant ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'stock' => [$variant ? 'sometimes' : 'required', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'draft'])],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'string', 'max:255'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'length_mm' => ['nullable', 'integer', 'min:0'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'min_order_quantity' => ['sometimes', 'integer', 'min:1'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1', 'gte:min_order_quantity'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'meta' => ['nullable', 'array'],
        ]);
    }

    private function recordStockChange(
        Product $product,
        ProductVariant $variant,
        int $oldStock,
        int $newStock,
        ?int $actorId,
        string $reason
    ): void {
        if ($oldStock === $newStock) {
            return;
        }

        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'actor_id' => $actorId,
            'type' => 'adjustment',
            'stock_delta' => $newStock - $oldStock,
            'reserved_delta' => 0,
            'stock_after' => $newStock,
            'reserved_after' => (int) $variant->stock_reserved,
            'reason' => $reason,
        ]);
    }
}
