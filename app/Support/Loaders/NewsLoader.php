<?php

namespace App\Support\Loaders;

use App\Models\News;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class NewsLoader
{
    use ResolvesMediaUrls;

    public static function make(News $news): array
    {
        $comments = $news->relationLoaded('comments')
            ? $news->comments
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

        $likesCount = (int) ($news->likes_count
            ?? ($news->relationLoaded('likes') ? $news->likes->count() : 0));

        $approvedCommentsCount = (int) ($news->approved_comments_count ?? count($comments));

        return [
            'id' => $news->id,
            'img' => self::mediaUrl((string) data_get($news->meta, 'cover_image'))
                ?: self::galleryUrl($news->galleries->first()),
            'title' => $news->headline,
            'headline' => $news->headline,
            'author' => $news->creator?->name,
            'tags' => $news->tags->pluck('name')->values()->all(),
            'categories' => $news->categories->pluck('name')->values()->all(),
            'desc' => $news->content ?? $news->summary,
            'excerpt' => $news->summary,
            'summary' => $news->summary,
            'content' => $news->content,
            'date' => optional($news->published_at)->toDateString(),
            'published_at' => optional($news->published_at)->toAtomString(),
            'page' => data_get($news->meta, 'page', 'default'),
            'slug' => $news->slug,
            'status' => $news->status,
            'likes_count' => $likesCount,
            'approved_comments_count' => $approvedCommentsCount,
            'comments' => $comments,
            'comments_count' => count($comments),
        ];
    }
}
