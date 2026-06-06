<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Cart\CartService;
use App\Support\Checkout\CheckoutPricingService;
use App\Support\Shipping\ShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService,
        protected ShippingQuoteService $shippingQuoteService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->resolve($request);

        return response()->json($this->cartService->payload($cart));
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $cart = $this->cartService->resolve($request);
        $product = Product::query()->findOrFail($data['product_id']);
        $this->cartService->add($cart, $product, (int) $data['quantity']);

        return response()->json($this->cartService->payload($cart), 201);
    }

    public function updateItem(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $cart = $this->cartService->resolve($request);
        abort_if(! $cart->items()->where('product_id', $product->id)->exists(), 404, 'Cart item not found.');
        $this->cartService->setQuantity($cart, $product, (int) $data['quantity']);

        return response()->json($this->cartService->payload($cart));
    }

    public function destroyItem(Request $request, Product $product): JsonResponse
    {
        $cart = $this->cartService->resolve($request, false);
        if ($cart) {
            $cart->items()->where('product_id', $product->id)->delete();
            $cart->forceFill(['last_activity_at' => now()])->save();
        }

        return response()->json($cart
            ? $this->cartService->payload($cart)
            : ['token' => null, 'items' => [], 'summary' => ['subtotal' => 0, 'shipping' => 0, 'tax' => 0, 'total' => 0, 'can_checkout' => false]]);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartService->resolve($request, false);
        if ($cart) {
            $cart->items()->delete();
            $cart->forceFill(['last_activity_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Cart cleared.',
            'token' => $cart?->token,
            'items' => [],
            'summary' => ['subtotal' => 0, 'shipping' => 0, 'tax' => 0, 'total' => 0, 'can_checkout' => false],
        ]);
    }

    public function merge(Request $request): JsonResponse
    {
        $request->validate(['guest_token' => ['required', 'uuid']]);
        $userCart = $this->cartService->resolve($request);
        $guestCart = \App\Models\Cart::query()
            ->whereNull('user_id')
            ->where('token', $request->string('guest_token'))
            ->first();

        if ($guestCart && $guestCart->id !== $userCart->id) {
            $this->cartService->merge($guestCart, $userCart);
        }

        return response()->json($this->cartService->payload($userCart->fresh()));
    }

    public function shippingOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);
        $cart = $this->cartService->resolve($request, false);
        $payload = $cart ? $this->cartService->payload($cart) : null;
        $subtotal = isset($data['subtotal'])
            ? (float) $data['subtotal']
            : (float) data_get($payload, 'summary.subtotal', 0);
        $user = $request->user('sanctum');
        $address = null;

        if (! empty($data['address_id'])) {
            abort_if(! $user, 401, 'Unauthenticated.');
            $address = $user->addresses()
                ->with('city.state')
                ->whereKey($data['address_id'])
                ->first();
            abort_if(! $address, 422, 'Selected address not found.');
        }

        return response()->json([
            'options' => $this->shippingQuoteService->options($subtotal, $address),
        ]);
    }

    /**
     * Validate requested products and quantities against current stock/status.
     */
    #[OA\Post(
        path: '/api/cart/check',
        operationId: 'cartCheck',
        summary: 'Validate requested products and quantities',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
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
        tags: ['Cart'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Validation result',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/CartCheckItem')
                        ),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $items = collect($data['items'] ?? []);
        if ($items->isEmpty()) {
            $cart = $this->cartService->resolve($request, false);
            abort_if(! $cart || $cart->items()->doesntExist(), 422, 'Cart is empty.');
            $items = $this->cartService->checkoutItems($cart);
        }

        $productIds = $items->pluck('product_id')->all();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->whereIn('status', ['active', 'special'])
            ->get()
            ->keyBy('id');

        $results = $items->map(function (array $item) use ($products) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                return [
                    'product_id' => $item['product_id'],
                    'requested_quantity' => $item['quantity'],
                    'available' => false,
                    'reason' => 'Product not available or inactive.',
                ];
            }

            $hasStock = $product->stock === null || $product->stock >= $item['quantity'];

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'currency' => $product->currency,
                'requested_quantity' => $item['quantity'],
                'available_quantity' => $product->stock,
                'available' => $hasStock,
                'reason' => $hasStock ? null : 'Insufficient stock.',
                'total' => $product->price * $item['quantity'],
            ];
        });

        $currency = $products->pluck('currency')->filter()->unique()->count() === 1
            ? $products->first()?->currency
            : null;
        $subtotal = round((float) $results->sum('total'), 2);
        $pricing = CheckoutPricingService::calculate($subtotal);

        return response()->json([
            'items' => $results,
            'summary' => [
                'subtotal' => $subtotal,
                'discount' => 0,
                'shipping' => $pricing['shipping'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
                'currency' => $currency,
                'can_checkout' => $results->every(
                    fn (array $result): bool => (bool) ($result['available'] ?? false)
                ),
            ],
        ]);
    }
}
