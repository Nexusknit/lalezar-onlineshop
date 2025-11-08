<?php

namespace App\Support\Loaders;

use App\Models\Blog;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class BlogLoader
{
    use ResolvesMediaUrls;

    public static function make(Blog $blog): array
    {
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
        ];
    }
}
