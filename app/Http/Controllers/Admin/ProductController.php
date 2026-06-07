<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\PriceHistory;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:product.all')->only('all');
        $this->middleware('permission:product.all')->only('show');
        $this->middleware('permission:product.store')->only('store');
        $this->middleware('permission:product.update')->only('update');
        $this->middleware('permission:product.activate')->only('activate');
        $this->middleware('permission:product.specialize')->only('specialize');
    }

    #[OA\Get(
        path: '/api/admin/products',
        operationId: 'adminProductsIndex',
        summary: 'List products',
        security: [['sanctum' => []]],
        tags: ['Admin - Products'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Products retrieved', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $products = Product::query()
            ->with(['creator', 'categories', 'tags', 'galleries', 'attributes', 'brands', 'variants'])
            ->when($request->filled('status'), static function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            })
            ->when($request->filled('search'), static function ($query) use ($request): void {
                $term = trim((string) $request->string('search'));
                $query->where(static function ($query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load([
            'creator',
            'categories',
            'tags',
            'galleries',
            'attributes',
            'brands',
            'variants',
            'inventoryMovements' => static fn ($query) => $query->latest()->limit(50),
            'priceHistories' => static fn ($query) => $query->latest()->limit(50),
        ]);

        return response()->json($product);
    }

    #[OA\Post(
        path: '/api/admin/products',
        operationId: 'adminProductsStore',
        summary: 'Create product',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'price'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'sku', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'stock', type: 'integer', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'meta', type: 'object', nullable: true),
                    new OA\Property(property: 'creator_id', type: 'integer', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Products'],
        responses: [
            new OA\Response(response: 201, description: 'Product created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'description' => ['nullable', 'string'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'length_mm' => ['nullable', 'integer', 'min:0'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1', 'gte:min_order_quantity'],
            'status' => ['nullable', 'string', 'max:50'],
            'meta' => ['nullable', 'array'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['creator_id'] = $data['creator_id'] ?? $request->user()?->id;

        $product = Product::query()->create($data);
        PriceHistory::query()->create([
            'product_id' => $product->id,
            'actor_id' => $request->user()?->id,
            'new_price' => $product->price,
            'currency' => $product->currency,
            'reason' => 'product_created',
        ]);
        if ((int) $product->stock > 0) {
            InventoryMovement::query()->create([
                'product_id' => $product->id,
                'actor_id' => $request->user()?->id,
                'type' => 'adjustment',
                'stock_delta' => (int) $product->stock,
                'reserved_delta' => 0,
                'stock_after' => (int) $product->stock,
                'reserved_after' => 0,
                'reason' => 'product_created',
            ]);
        }

        return response()->json($product->fresh()->load(['creator']), 201);
    }

    #[OA\Patch(
        path: '/api/admin/products/{product}',
        operationId: 'adminProductsUpdate',
        summary: 'Update product',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'sku', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'stock', type: 'integer', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'meta', type: 'object', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products', 'slug')->ignore($product->id),
            ],
            'sku' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($product->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->ignore($product->id),
            ],
            'description' => ['nullable', 'string'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'length_mm' => ['nullable', 'integer', 'min:0'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1', 'gte:min_order_quantity'],
            'status' => ['nullable', 'string', 'max:50'],
            'meta' => ['nullable', 'array'],
        ]);

        $oldPrice = (float) $product->price;
        $oldStock = (int) $product->stock;
        $product->fill($data);
        abort_if((int) $product->stock < (int) $product->stock_reserved, 422, 'Stock cannot be lower than reserved stock.');
        $product->save();

        if (array_key_exists('price', $data) && (float) $product->price !== $oldPrice) {
            PriceHistory::query()->create([
                'product_id' => $product->id,
                'actor_id' => $request->user()?->id,
                'old_price' => $oldPrice,
                'new_price' => $product->price,
                'currency' => $product->currency,
                'reason' => 'admin_update',
            ]);
        }
        if (array_key_exists('stock', $data) && (int) $product->stock !== $oldStock) {
            InventoryMovement::query()->create([
                'product_id' => $product->id,
                'actor_id' => $request->user()?->id,
                'type' => 'adjustment',
                'stock_delta' => (int) $product->stock - $oldStock,
                'reserved_delta' => 0,
                'stock_after' => (int) $product->stock,
                'reserved_after' => (int) $product->stock_reserved,
                'reason' => 'admin_update',
            ]);
        }

        return response()->json($product->fresh()->load(['creator', 'variants']));
    }

    #[OA\Post(
        path: '/api/admin/products/{product}/activate',
        operationId: 'adminProductsActivate',
        summary: 'Activate product',
        security: [['sanctum' => []]],
        tags: ['Admin - Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product activated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function activate(Product $product): JsonResponse
    {
        $product->update(['status' => 'active']);

        return response()->json($product->fresh());
    }

    #[OA\Post(
        path: '/api/admin/products/{product}/specialize',
        operationId: 'adminProductsSpecialize',
        summary: 'Mark product as special',
        security: [['sanctum' => []]],
        tags: ['Admin - Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product specialized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function specialize(Product $product): JsonResponse
    {
        $product->update(['status' => 'special']);

        return response()->json($product->fresh());
    }
}
