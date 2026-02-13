<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Product;
use App\Support\Checkout\CheckoutPricingService;
use App\Support\Coupons\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class PaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Post(
        path: '/api/user/checkout',
        operationId: 'checkout',
        summary: 'Create an invoice based on cart items',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['address_id', 'items'],
                properties: [
                    new OA\Property(property: 'address_id', type: 'integer', example: 3),
                    new OA\Property(property: 'coupon_code', type: 'string', nullable: true, example: 'WELCOME10'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            required: ['product_id', 'quantity'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'integer', example: 1),
                                new OA\Property(property: 'quantity', type: 'integer', example: 2),
                            ]
                        )
                    ),
                ]
            )
        ),
        tags: ['Checkout'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice created',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceResponse')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'shipping' => ['prohibited'],
            'tax' => ['prohibited'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        /** @var Address|null $address */
        $address = $user->addresses()->where('id', $data['address_id'])->first();

        abort_if(! $address, 422, 'Selected address not found.');

        $productIds = collect($data['items'])->pluck('product_id')->all();

        $couponCode = isset($data['coupon_code']) ? trim((string) $data['coupon_code']) : null;

        /** @var Invoice $invoice */
        $invoice = DB::transaction(function () use ($user, $address, $productIds, $data, $couponCode) {
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->whereIn('status', ['active', 'special'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_if($products->count() !== count($productIds), 422, 'One or more products are unavailable.');

            $itemsPayload = collect($data['items'])->map(function (array $item) use ($products) {
                /** @var Product|null $product */
                $product = $products->get($item['product_id']);

                abort_if(! $product, 422, 'Product '.$item['product_id'].' not available.');

                $quantity = (int) $item['quantity'];
                $stock = $product->stock !== null ? (int) $product->stock : null;

                abort_if($stock !== null && $stock < $quantity, 422, "Insufficient stock for {$product->name}.");

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'total' => $product->price * $quantity,
                ];
            });

            $currency = $products->pluck('currency')->unique()->count() === 1
                ? $products->first()->currency
                : null;

            abort_if($currency === null, 422, 'Products must share a common currency.');

            $subtotal = (float) $itemsPayload->sum('total');
            $coupon = null;
            $discount = 0.0;

            if ($couponCode) {
                $coupon = CouponService::resolveValidCoupon(
                    $couponCode,
                    (float) $subtotal,
                    $currency,
                    $user,
                    true
                );
                $discount = $coupon->calculateDiscount((float) $subtotal);
            }

            $total = round(max(0, (float) $subtotal - $discount), 2);
            $pricing = CheckoutPricingService::calculate($total);
            $shipping = $pricing['shipping'];
            $tax = $pricing['tax'];
            $grandTotal = $pricing['total'];

            $meta = $coupon ? [
                'coupon' => [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'discount_type' => $coupon->discount_type,
                    'discount_value' => $coupon->discount_value,
                    'calculated_discount' => $discount,
                ],
            ] : [];

            $meta['shipping'] = $shipping;
            $meta['tax'] = $tax;

            $invoice = Invoice::query()->create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'coupon_id' => $coupon?->id,
                'number' => $this->generateInvoiceNumber(),
                'status' => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'total' => $grandTotal,
                'issued_at' => now(),
                'meta' => $meta,
            ]);

            $itemsPayload->each(function (array $payload) use ($invoice): void {
                /** @var Product $product */
                $product = $payload['product'];
                $quantity = (int) $payload['quantity'];

                Item::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'quantity' => $quantity,
                    'unit_price' => $payload['unit_price'],
                    'total' => $payload['total'],
                    'meta' => [
                        'sku' => $product->sku,
                        'product_status' => $product->status,
                    ],
                ]);

                if ($product->stock !== null) {
                    $product->stock = max(0, (int) $product->stock - $quantity);
                }

                $product->sold_count = (int) ($product->sold_count ?? 0) + $quantity;
                $product->save();
            });

            if ($coupon) {
                $coupon->increment('used_count');

                CouponUsage::query()->create([
                    'coupon_id' => $coupon->id,
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'discount_amount' => $discount,
                    'used_at' => now(),
                ]);
            }

            return $invoice->fresh()->load([
                'items',
                'address.city.state',
                'coupon',
            ]);
        });

        return response()->json($invoice);
    }

    protected function generateInvoiceNumber(): string
    {
        return 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }
}
