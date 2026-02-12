<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Comment;
use App\Models\News;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CommentController extends Controller
{
    /**
     * @var array<string, class-string<Model>>
     */
    protected array $modelMap = [
        'product' => Product::class,
        'blog' => Blog::class,
        'news' => News::class,
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_type' => ['required', 'string', Rule::in(array_keys($this->modelMap))],
            'model_id' => ['required', 'integer'],
            'comment' => ['required', 'string', 'max:5000'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
        ]);

        $model = $this->resolveModel($data['model_type'], (int) $data['model_id']);
        $this->assertModelCanReceiveComment($model);

        $comment = Comment::query()->create([
            'user_id' => $request->user()->id,
            'admin_id' => null,
            'model_id' => $model->getKey(),
            'model_type' => $model::class,
            'comment' => $data['comment'],
            'rating' => $data['rating'] ?? null,
            'answer' => null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Your comment has been submitted and is pending review.',
            'comment' => $comment->fresh()->load('user:id,name'),
        ], 201);
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

    protected function assertModelCanReceiveComment(Model $model): void
    {
        if ($model instanceof Product) {
            abort_if(! in_array($model->status, ['active', 'special'], true), 422, 'Product is not available for comments.');

            return;
        }

        if ($model instanceof Blog) {
            abort_if(! in_array($model->status, ['active', 'special'], true), 422, 'Blog post is not available for comments.');
            abort_if($model->published_at && Carbon::parse($model->published_at)->isFuture(), 422, 'Blog post is not published yet.');

            return;
        }

        if ($model instanceof News) {
            abort_if(! in_array($model->status, ['active', 'special'], true), 422, 'News item is not available for comments.');
            abort_if($model->published_at && Carbon::parse($model->published_at)->isFuture(), 422, 'News item is not published yet.');
        }
    }
}
