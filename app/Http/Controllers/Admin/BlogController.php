<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class BlogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:blog.all')->only('all');
        $this->middleware('permission:blog.store')->only('store');
        $this->middleware('permission:blog.update')->only('update');
        $this->middleware('permission:blog.delete')->only('delete');
        $this->middleware('permission:blog.activate')->only('activate');
        $this->middleware('permission:blog.specialize')->only('specialize');
    }

    #[OA\Get(
        path: '/api/admin/blogs',
        operationId: 'adminBlogsIndex',
        summary: 'List blogs',
        security: [['sanctum' => []]],
        tags: ['Admin - Blogs'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Blogs retrieved', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $blogs = Blog::query()
            ->with(['creator', 'categories', 'tags', 'images'])
            ->when($request->filled('status'), static function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($blogs);
    }

    #[OA\Post(
        path: '/api/admin/blogs',
        operationId: 'adminBlogsStore',
        summary: 'Create blog post',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'excerpt', type: 'string', nullable: true),
                    new OA\Property(property: 'body', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'meta', type: 'object', nullable: true),
                    new OA\Property(property: 'creator_id', type: 'integer', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Blogs'],
        responses: [
            new OA\Response(response: 201, description: 'Blog created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:blogs,slug'],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'published_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);
        $data['creator_id'] = $data['creator_id'] ?? $request->user()?->id;

        $blog = Blog::query()->create($data);

        return response()->json($blog->fresh()->load(['creator']), 201);
    }

    #[OA\Patch(
        path: '/api/admin/blogs/{blog}',
        operationId: 'adminBlogsUpdate',
        summary: 'Update blog post',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'excerpt', type: 'string', nullable: true),
                    new OA\Property(property: 'body', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'meta', type: 'object', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Blogs'],
        parameters: [
            new OA\Parameter(name: 'blog', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Blog updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Blog $blog): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('blogs', 'slug')->ignore($blog->id),
            ],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'published_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $blog->fill($data)->save();

        return response()->json($blog->fresh()->load(['creator']));
    }

    #[OA\Delete(
        path: '/api/admin/blogs/{blog}',
        operationId: 'adminBlogsDelete',
        summary: 'Delete blog post',
        security: [['sanctum' => []]],
        tags: ['Admin - Blogs'],
        parameters: [
            new OA\Parameter(name: 'blog', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Blog deleted', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function delete(Blog $blog): JsonResponse
    {
        $blog->delete();

        return response()->json([
            'message' => 'Blog deleted successfully.',
        ]);
    }

    #[OA\Post(
        path: '/api/admin/blogs/{blog}/activate',
        operationId: 'adminBlogsActivate',
        summary: 'Mark blog as active',
        security: [['sanctum' => []]],
        tags: ['Admin - Blogs'],
        parameters: [
            new OA\Parameter(name: 'blog', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Blog activated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function activate(Blog $blog): JsonResponse
    {
        $blog->update([
            'status' => 'active',
            'published_at' => $blog->published_at ?? now(),
        ]);

        return response()->json($blog->fresh());
    }

    #[OA\Post(
        path: '/api/admin/blogs/{blog}/specialize',
        operationId: 'adminBlogsSpecialize',
        summary: 'Mark blog as special',
        security: [['sanctum' => []]],
        tags: ['Admin - Blogs'],
        parameters: [
            new OA\Parameter(name: 'blog', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Blog specialized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function specialize(Blog $blog): JsonResponse
    {
        $blog->update(['status' => 'special']);

        return response()->json($blog->fresh());
    }
}
