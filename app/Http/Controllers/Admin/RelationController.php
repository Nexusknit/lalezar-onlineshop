<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Like;
use App\Models\News;
use App\Models\Product;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RelationController extends Controller
{
    /**
     * @var array<string, class-string<Model>>
     */
    protected array $modelMap = [
        'blog' => Blog::class,
        'news' => News::class,
        'product' => Product::class,
        'invoice' => Invoice::class,
        'ticket' => Ticket::class,
    ];

    public function __construct()
    {
        $this->middleware('permission:relation.attachCategory')->only('attachCategory');
        $this->middleware('permission:relation.attachTag')->only('attachTag');
        $this->middleware('permission:relation.attachAttribute')->only('attachAttribute');
        $this->middleware('permission:relation.attachLike')->only('attachLike');
        $this->middleware('permission:relation.attachGallery')->only('attachGallery');
    }

    public function attachCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
        ]);

        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);

        if (! method_exists($model, 'categories')) {
            abort(422, 'The selected model cannot be categorized.');
        }

        $model->categories()->syncWithoutDetaching([$data['category_id']]);

        return response()->json($model->fresh()->load('categories'));
    }

    public function attachTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tag_id' => ['required', 'integer', Rule::exists('tags', 'id')],
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
        ]);

        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);

        if (! method_exists($model, 'tags')) {
            abort(422, 'The selected model cannot be tagged.');
        }

        $model->tags()->syncWithoutDetaching([$data['tag_id']]);

        return response()->json($model->fresh()->load('tags'));
    }

    public function attachAttribute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);

        if (! method_exists($model, 'attributes')) {
            abort(422, 'The selected model cannot receive attributes.');
        }

        $payload = [
            'creator_id' => $data['creator_id'] ?? $request->user()?->id,
            'key' => $data['key'],
            'value' => $data['value'] ?? null,
            'amount' => $data['amount'] ?? null,
            'meta' => $data['meta'] ?? null,
        ];

        /** @var Attribute $attribute */
        $attribute = $model->attributes()->create($payload);

        return response()->json($attribute->fresh(), 201);
    }

    public function attachLike(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);

        if (! method_exists($model, 'likes')) {
            abort(422, 'The selected model cannot be liked.');
        }

        $creatorId = $data['creator_id'] ?? $request->user()?->id;

        $like = $model->likes()->firstOrCreate([
            'creator_id' => $creatorId,
        ]);

        return response()->json($like->fresh(), 201);
    }

    public function attachGallery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
            'path' => ['required', 'string', 'max:255'],
            'disk' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);

        if (! method_exists($model, 'galleries')) {
            abort(422, 'The selected model cannot have gallery items.');
        }

        $payload = [
            'creator_id' => $data['creator_id'] ?? $request->user()?->id,
            'disk' => $data['disk'] ?? 'public',
            'path' => $data['path'],
            'title' => $data['title'] ?? null,
            'alt' => $data['alt'] ?? null,
            'meta' => $data['meta'] ?? null,
        ];

        /** @var Gallery $gallery */
        $gallery = $model->galleries()->create($payload);

        return response()->json($gallery->fresh(), 201);
    }

    protected function resolveModel(string $type, int $id): Model
    {
        $normalized = strtolower($type);
        $class = $this->modelMap[$normalized] ?? null;

        abort_if($class === null, 422, 'Unsupported model type supplied.');

        /** @var Model $model */
        $model = $class::query()->findOrFail($id);

        return $model;
    }
}
