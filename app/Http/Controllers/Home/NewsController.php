<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Support\Loaders\NewsLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NewsController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 12);
        $perPage = $perPage > 0 ? min($perPage, 100) : 12;

        $news = News::query()
            ->with([
                'creator:id,name',
                'categories:id,name,slug',
                'tags:id,name,slug',
                'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
            ])
            ->withCount([
                'likes',
                'comments as approved_comments_count' => static function ($query): void {
                    $query->whereIn('status', ['published', 'answered']);
                },
            ])
            ->whereIn('status', ['active', 'special'])
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', Carbon::now());
            })
            ->when($request->filled('category'), static function ($query) use ($request): void {
                $slug = $request->string('category');
                $query->whereHas('categories', static function ($query) use ($slug): void {
                    $query->where('slug', $slug);
                });
            })
            ->when($request->filled('tag'), static function ($query) use ($request): void {
                $slug = $request->string('tag');
                $query->whereHas('tags', static function ($query) use ($slug): void {
                    $query->where('slug', $slug);
                });
            })
            ->when($request->filled('q'), static function ($query) use ($request): void {
                $term = $request->string('q');
                $query->where(static function ($query) use ($term): void {
                    $query->where('headline', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('content', 'like', "%{$term}%");
                });
            })
            ->latest('published_at')
            ->paginate($perPage)
            ->through(fn (News $item) => NewsLoader::make($item));

        return response()->json($news);
    }

    public function single(News $news): JsonResponse
    {
        $allowed = ['active', 'special'];

        abort_if(! in_array($news->status, $allowed, true), 404, 'News item is not available.');

        if ($news->published_at && $news->published_at->isFuture()) {
            abort(404, 'News item has not been published yet.');
        }

        $news->load([
            'creator:id,name',
            'categories:id,name,slug',
            'tags:id,name,slug',
            'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
            'likes:id,creator_id,model_id,model_type,created_at',
            'comments' => static function ($query): void {
                $query->whereIn('status', ['published', 'answered'])
                    ->with(['user:id,name'])
                    ->latest();
            },
        ])->loadCount([
            'likes',
            'comments as approved_comments_count' => static function ($query): void {
                $query->whereIn('status', ['published', 'answered']);
            },
        ]);

        $news->setRelation('likes', $news->likes->take(20));
        $news->setRelation('comments', $news->comments->take(20));

        return response()->json(NewsLoader::make($news));
    }
}
