<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Coupons\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'coupon_code' => ['required', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $items = collect($data['items']);
        $productIds = $items->pluck('product_id')->all();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->whereIn('status', ['active', 'special'])
            ->get()
            ->keyBy('id');

        abort_if($products->count() !== count($productIds), 422, 'One or more products are unavailable.');

        $currency = $products->pluck('currency')->unique()->count() === 1
            ? $products->first()->currency
            : null;

        abort_if($currency === null, 422, 'Products must share a common currency.');

        $subtotal = $items->sum(function (array $item) use ($products): float {
            $product = $products->get($item['product_id']);

            abort_if(! $product, 422, 'Product '.$item['product_id'].' not available.');
            abort_if(
                $product->stock !== null && $product->stock < $item['quantity'],
                422,
                "Insufficient stock for {$product->name}."
            );

            return (float) $product->price * (int) $item['quantity'];
        });

        $coupon = CouponService::resolveValidCoupon(
            $data['coupon_code'],
            $subtotal,
            $currency,
            $request->user()
        );

        $discount = $coupon->calculateDiscount($subtotal);
        $total = round(max(0, $subtotal - $discount), 2);

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
                'total' => $total,
                'currency' => $currency,
            ],
        ]);
    }
}
