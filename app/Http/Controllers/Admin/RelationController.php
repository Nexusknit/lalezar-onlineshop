<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Blog;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\News;
use App\Models\Product;
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
        $this->middleware('permission:product.update')->only([
            'attachBrand',
            'detachCategory',
            'detachTag',
            'detachBrand',
            'updateAttribute',
            'deleteAttribute',
            'updateGallery',
            'deleteGallery',
        ]);
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

    public function attachBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
        ]);
        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);
        abort_if(! method_exists($model, 'brands'), 422, 'The selected model cannot have brands.');
        $model->brands()->syncWithoutDetaching([$data['brand_id']]);

        return response()->json($model->fresh()->load('brands'));
    }

    public function detachCategory(Request $request): JsonResponse
    {
        return $this->detachMorph($request, 'category_id', 'categories', 'categories');
    }

    public function detachTag(Request $request): JsonResponse
    {
        return $this->detachMorph($request, 'tag_id', 'tags', 'tags');
    }

    public function detachBrand(Request $request): JsonResponse
    {
        return $this->detachMorph($request, 'brand_id', 'brands', 'brands');
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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_primary' => ['nullable', 'boolean'],
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
            'sort_order' => $data['sort_order'] ?? 0,
            'is_primary' => $data['is_primary'] ?? false,
        ];

        if ($payload['is_primary']) {
            $model->galleries()->update(['is_primary' => false]);
        }

        /** @var Gallery $gallery */
        $gallery = $model->galleries()->create($payload);

        return response()->json($gallery->fresh(), 201);
    }

    public function updateAttribute(Request $request, Attribute $attribute): JsonResponse
    {
        $data = $request->validate([
            'key' => ['sometimes', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);
        $attribute->update($data);

        return response()->json($attribute->fresh());
    }

    public function deleteAttribute(Attribute $attribute): JsonResponse
    {
        $attribute->delete();

        return response()->json(['message' => 'Attribute deleted successfully.']);
    }

    public function updateGallery(Request $request, Gallery $gallery): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        if (($data['is_primary'] ?? false) && $gallery->model) {
            $gallery->model->galleries()->where('id', '!=', $gallery->id)->update(['is_primary' => false]);
        }
        $gallery->update($data);

        return response()->json($gallery->fresh());
    }

    public function deleteGallery(Gallery $gallery): JsonResponse
    {
        $gallery->delete();

        return response()->json(['message' => 'Gallery item deleted successfully.']);
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

    protected function detachMorph(Request $request, string $key, string $relation, string $table): JsonResponse
    {
        $data = $request->validate([
            $key => ['required', 'integer', Rule::exists($table, 'id')],
            'model_type' => ['required', 'string'],
            'model_id' => ['required', 'integer'],
        ]);
        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);
        abort_if(! method_exists($model, $relation), 422, 'The selected relation is unsupported.');
        $model->{$relation}()->detach((int) $data[$key]);

        return response()->json($model->fresh()->load($relation));
    }
}
