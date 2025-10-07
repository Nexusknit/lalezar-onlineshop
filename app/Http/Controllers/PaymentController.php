<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Product;
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        /** @var Address|null $address */
        $address = $user->addresses()->where('id', $data['address_id'])->first();

        abort_if(! $address, 422, 'Selected address not found.');

        $productIds = collect($data['items'])->pluck('product_id')->all();

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

        $itemsPayload = collect($data['items'])->map(function (array $item) use ($products) {
            $product = $products->get($item['product_id']);

            abort_if(! $product, 422, 'Product '.$item['product_id'].' not available.');

            abort_if($product->stock !== null && $product->stock < $item['quantity'], 422, "Insufficient stock for {$product->name}.");

            return [
                'product' => $product,
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
                'total' => $product->price * $item['quantity'],
            ];
        });

        $subtotal = $itemsPayload->sum('total');

        /** @var Invoice $invoice */
        $invoice = DB::transaction(function () use ($user, $address, $currency, $itemsPayload, $subtotal) {
            $invoice = Invoice::query()->create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'number' => $this->generateInvoiceNumber(),
                'status' => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'tax' => 0,
                'discount' => 0,
                'total' => $subtotal,
                'issued_at' => now(),
                'meta' => null,
            ]);

            $itemsPayload->each(function (array $payload) use ($invoice): void {
                /** @var Product $product */
                $product = $payload['product'];

                Item::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'quantity' => $payload['quantity'],
                    'unit_price' => $payload['unit_price'],
                    'total' => $payload['total'],
                    'meta' => [
                        'sku' => $product->sku,
                        'product_status' => $product->status,
                    ],
                ]);
            });

            return $invoice->fresh()->load([
                'items',
                'address.city.state',
            ]);
        });

        return response()->json($invoice);
    }

    protected function generateInvoiceNumber(): string
    {
        return 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }
}
