<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class NewsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:news.all')->only('all');
        $this->middleware('permission:news.store')->only('store');
        $this->middleware('permission:news.update')->only('update');
        $this->middleware('permission:news.delete')->only('delete');
        $this->middleware('permission:news.activate')->only('activate');
        $this->middleware('permission:news.specialize')->only('specialize');
    }

    #[OA\Get(
        path: '/api/admin/news',
        operationId: 'adminNewsIndex',
        summary: 'List news items',
        security: [['sanctum' => []]],
        tags: ['Admin - News'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'News retrieved', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $news = News::query()
            ->with(['creator', 'categories', 'tags', 'galleries'])
            ->when($request->filled('status'), static function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($news);
    }

    #[OA\Post(
        path: '/api/admin/news',
        operationId: 'adminNewsStore',
        summary: 'Create news item',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['headline'],
                properties: [
                    new OA\Property(property: 'headline', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'summary', type: 'string', nullable: true),
                    new OA\Property(property: 'content', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'meta', type: 'object', nullable: true),
                    new OA\Property(property: 'creator_id', type: 'integer', nullable: true),
                ]
            )
        ),
        tags: ['Admin - News'],
        responses: [
            new OA\Response(response: 201, description: 'News created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:news,slug'],
            'summary' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'published_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['headline']);
        $data['creator_id'] = $data['creator_id'] ?? $request->user()?->id;

        $news = News::query()->create($data);

        return response()->json($news->fresh()->load(['creator']), 201);
    }

    #[OA\Patch(
        path: '/api/admin/news/{news}',
        operationId: 'adminNewsUpdate',
        summary: 'Update news item',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'headline', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'summary', type: 'string', nullable: true),
                    new OA\Property(property: 'content', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'meta', type: 'object', nullable: true),
                ]
            )
        ),
        tags: ['Admin - News'],
        parameters: [
            new OA\Parameter(name: 'news', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'News updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, News $news): JsonResponse
    {
        $data = $request->validate([
            'headline' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('news', 'slug')->ignore($news->id),
            ],
            'summary' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'published_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $news->fill($data)->save();

        return response()->json($news->fresh()->load(['creator']));
    }

    #[OA\Delete(
        path: '/api/admin/news/{news}',
        operationId: 'adminNewsDelete',
        summary: 'Delete news item',
        security: [['sanctum' => []]],
        tags: ['Admin - News'],
        parameters: [
            new OA\Parameter(name: 'news', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'News deleted', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function delete(News $news): JsonResponse
    {
        $news->delete();

        return response()->json([
            'message' => 'News deleted successfully.',
        ]);
    }

    #[OA\Post(
        path: '/api/admin/news/{news}/activate',
        operationId: 'adminNewsActivate',
        summary: 'Mark news as active',
        security: [['sanctum' => []]],
        tags: ['Admin - News'],
        parameters: [
            new OA\Parameter(name: 'news', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'News activated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function activate(News $news): JsonResponse
    {
        $news->update([
            'status' => 'active',
            'published_at' => $news->published_at ?? now(),
        ]);

        return response()->json($news->fresh());
    }

    #[OA\Post(
        path: '/api/admin/news/{news}/specialize',
        operationId: 'adminNewsSpecialize',
        summary: 'Mark news as special',
        security: [['sanctum' => []]],
        tags: ['Admin - News'],
        parameters: [
            new OA\Parameter(name: 'news', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'News specialized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function specialize(News $news): JsonResponse
    {
        $news->update(['status' => 'special']);

        return response()->json($news->fresh());
    }
}
