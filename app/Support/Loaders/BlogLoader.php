<?php

namespace App\Support\Loaders;

use App\Models\Blog;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class BlogLoader
{
    use ResolvesMediaUrls;

    public static function make(Blog $blog): array
    {
        $comments = $blog->relationLoaded('comments')
            ? $blog->comments
                ->map(static function ($comment) {
                    return [
                        'id' => $comment->id,
                        'name' => $comment->user?->name ?? 'User',
                        'comment' => $comment->comment,
                        'answer' => $comment->answer,
                        'rating' => $comment->rating,
                        'date' => optional($comment->created_at)->toDateString(),
                    ];
                })
                ->values()
                ->all()
            : [];

        return [
            'id' => $blog->id,
            'img' => $blog->cover_image
                ? self::mediaUrl($blog->cover_image)
                : self::galleryUrl($blog->galleries->first()),
            'title' => $blog->title,
            'author' => $blog->creator?->name,
            'tags' => $blog->tags->pluck('name')->values()->all(),
            'desc' => $blog->body,
            'excerpt' => $blog->excerpt,
            'date' => optional($blog->published_at)->toDateString(),
            'page' => data_get($blog->meta, 'page', 'default'),
            'slug' => $blog->slug,
            'status' => $blog->status,
            'comments' => $comments,
            'comments_count' => count($comments),
        ];
    }
}
