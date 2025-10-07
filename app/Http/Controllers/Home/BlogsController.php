<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BlogsController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 12);
        $perPage = $perPage > 0 ? min($perPage, 100) : 12;

        $blogs = Blog::query()
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
                    $query->where('title', 'like', "%{$term}%")
                        ->orWhere('excerpt', 'like', "%{$term}%")
                        ->orWhere('body', 'like', "%{$term}%");
                });
            })
            ->latest('published_at')
            ->paginate($perPage);

        return response()->json($blogs);
    }

    public function single(Blog $blog): JsonResponse
    {
        $allowed = ['active', 'special'];

        abort_if(! in_array($blog->status, $allowed, true), 404, 'Blog post is not available.');

        if ($blog->published_at && $blog->published_at->isFuture()) {
            abort(404, 'Blog post has not been published yet.');
        }

        $blog->load([
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

        $blog->setRelation('likes', $blog->likes->take(20));
        $blog->setRelation('comments', $blog->comments->take(20));

        return response()->json($blog);
    }
}
