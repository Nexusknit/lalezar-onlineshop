<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Searchable\Searchable;
use Spatie\Searchable\SearchResult;

class News extends Model implements Searchable
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'creator_id',
        'headline',
        'slug',
        'summary',
        'content',
        'status',
        'published_at',
        'meta',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function attributes(): MorphMany
    {
        return $this->morphMany(Attribute::class, 'model');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'model');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'model');
    }

    public function galleries(): MorphMany
    {
        return $this->morphMany(Gallery::class, 'model');
    }

    public function getSearchResult(): SearchResult
    {
        return new SearchResult(
            $this,
            $this->headline,
            route('home.news.show', $this)
        );
    }
}
