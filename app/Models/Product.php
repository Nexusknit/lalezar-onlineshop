<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Support\Str;
use Spatie\Searchable\Searchable;
use Spatie\Searchable\SearchResult;

class Product extends Model implements Searchable
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'creator_id',
        'name',
        'slug',
        'sku',
        'summary',
        'content',
        'description',
        'stock',
        'sold_count',
        'price',
        'discount_percent',
        'currency',
        'status',
        'meta',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_percent' => 'integer',
        'sold_count' => 'integer',
        'meta' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function brands(): MorphToMany
    {
        return $this->morphToMany(Brand::class, 'brandable');
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

    protected function description(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->content ?? $this->summary,
            set: function ($value): void {
                $this->attributes['content'] = $value;
                $this->attributes['summary'] = $this->attributes['summary']
                    ?? ($value ? Str::limit((string) $value, 160) : null);
            },
        );
    }

    public function galleries(): MorphMany
    {
        return $this->morphMany(Gallery::class, 'model');
    }

    public function getSearchResult(): SearchResult
    {
        return new SearchResult(
            $this,
            $this->name,
            route('home.products.show', $this)
        );
    }
}
