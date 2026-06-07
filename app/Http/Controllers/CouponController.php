<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Checkout\CheckoutPricingService;
use App\Support\Coupons\CouponService;
use App\Support\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(protected InventoryService $inventoryService) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'coupon_code' => ['required', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $items = collect($data['items']);
        $productIds = $items->pluck('product_id')->unique()->values()->all();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->whereIn('status', ['active', 'special'])
            ->with('variants')
            ->get()
            ->keyBy('id');

        abort_if($products->count() !== count($productIds), 422, 'One or more products are unavailable.');

        $currency = $products->pluck('currency')->unique()->count() === 1
            ? $products->first()->currency
            : null;

        abort_if($currency === null, 422, 'Products must share a common currency.');

        $variants = ProductVariant::query()
            ->whereIn('id', $items->pluck('product_variant_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $subtotal = $items->sum(function (array $item) use ($products, $variants): float {
            $product = $products->get($item['product_id']);

            abort_if(! $product, 422, 'Product '.$item['product_id'].' not available.');
            $variant = ! empty($item['product_variant_id'])
                ? $variants->get((int) $item['product_variant_id'])
                : null;
            abort_if(
                $product->variants->where('status', 'active')->isNotEmpty() && ! $variant,
                422,
                "Select a variant for {$product->name}."
            );
            $this->inventoryService->assertPurchasable($product, $variant, (int) $item['quantity']);

            return (float) ($variant?->price ?? $product->price) * (int) $item['quantity'];
        });

        $coupon = CouponService::resolveValidCoupon(
            $data['coupon_code'],
            $subtotal,
            $currency,
            $request->user()
        );

        $discount = $coupon->calculateDiscount($subtotal);
        $subtotalAfterDiscount = round(max(0, $subtotal - $discount), 2);
        $pricing = CheckoutPricingService::calculate($subtotalAfterDiscount);

        return response()->json([
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'title' => $coupon->title,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
                'max_discount' => $coupon->max_discount,
            ],
            'summary' => [
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'subtotal_after_discount' => $subtotalAfterDiscount,
                'shipping' => $pricing['shipping'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
                'currency' => $currency,
                'can_checkout' => true,
            ],
        ]);
    }
}
