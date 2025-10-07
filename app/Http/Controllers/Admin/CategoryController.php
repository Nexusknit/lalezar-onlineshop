<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:category.all')->only('all');
        $this->middleware('permission:category.store')->only('store');
        $this->middleware('permission:category.update')->only('update');
        $this->middleware('permission:category.activate')->only('activate');
        $this->middleware('permission:category.specialize')->only('specialize');
    }

    #[OA\Get(
        path: '/api/admin/categories',
        operationId: 'adminCategoriesIndex',
        summary: 'List categories',
        security: [['sanctum' => []]],
        tags: ['Admin - Categories'],
        responses: [
            new OA\Response(response: 200, description: 'Categories retrieved', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->with(['children', 'parent'])
            ->orderBy('order_column')
            ->get();

        return response()->json($categories);
    }

    #[OA\Post(
        path: '/api/admin/categories',
        operationId: 'adminCategoriesStore',
        summary: 'Create category',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'order_column', type: 'integer', nullable: true),
                    new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'is_special', type: 'boolean', nullable: true),
                    new OA\Property(property: 'creator_id', type: 'integer', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Categories'],
        responses: [
            new OA\Response(response: 201, description: 'Category created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
            'order_column' => ['nullable', 'integer', 'min:0'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'status' => ['nullable', 'string', 'max:50'],
            'is_special' => ['nullable', 'boolean'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['creator_id'] = $data['creator_id'] ?? $request->user()?->id;

        $category = Category::query()->create($data);

        return response()->json($category->fresh()->load(['parent', 'children']), 201);
    }

    #[OA\Patch(
        path: '/api/admin/categories/{category}',
        operationId: 'adminCategoriesUpdate',
        summary: 'Update category',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'order_column', type: 'integer', nullable: true),
                    new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'is_special', type: 'boolean', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($category->id),
            ],
            'description' => ['nullable', 'string'],
            'order_column' => ['nullable', 'integer', 'min:0'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'status' => ['nullable', 'string', 'max:50'],
            'is_special' => ['nullable', 'boolean'],
        ]);

        $category->fill($data)->save();

        return response()->json($category->fresh()->load(['parent', 'children']));
    }

    #[OA\Post(
        path: '/api/admin/categories/{category}/activate',
        operationId: 'adminCategoriesActivate',
        summary: 'Activate category',
        security: [['sanctum' => []]],
        tags: ['Admin - Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category activated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function activate(Category $category): JsonResponse
    {
        $category->update(['status' => 'active']);

        return response()->json($category->fresh());
    }

    #[OA\Post(
        path: '/api/admin/categories/{category}/specialize',
        operationId: 'adminCategoriesSpecialize',
        summary: 'Mark category as special',
        security: [['sanctum' => []]],
        tags: ['Admin - Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category specialized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function specialize(Category $category): JsonResponse
    {
        $category->update(['is_special' => true]);

        return response()->json($category->fresh());
    }
}
