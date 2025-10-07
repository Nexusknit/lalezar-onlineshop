<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CartController extends Controller
{
    /**
     * Validate requested products and quantities against current stock/status.
     */
    #[OA\Post(
        path: '/api/cart/check',
        operationId: 'cartCheck',
        summary: 'Validate requested products and quantities',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $productIds = collect($data['items'])->pluck('product_id')->all();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->whereIn('status', ['active', 'special'])
            ->get()
            ->keyBy('id');

        $results = collect($data['items'])->map(function (array $item) use ($products) {
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

        return response()->json([
            'items' => $results,
        ]);
    }
}
