<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;
use Spatie\Searchable\Searchable;
use Spatie\Searchable\SearchResult;

class Category extends Model implements Searchable
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'creator_id',
        'parent_id',
        'name',
        'slug',
        'summary',
        'content',
        'description',
        'icon',
        'image_path',
        'order_column',
        'status',
        'is_special',
    ];

    protected $casts = [
        'order_column' => 'integer',
        'is_special' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function blogs(): MorphToMany
    {
        return $this->morphedByMany(Blog::class, 'categorizable');
    }

    public function news(): MorphToMany
    {
        return $this->morphedByMany(News::class, 'categorizable');
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'categorizable');
    }

    public function invoices(): MorphToMany
    {
        return $this->morphedByMany(Invoice::class, 'categorizable');
    }

    public function tickets(): MorphToMany
    {
        return $this->morphedByMany(Ticket::class, 'categorizable');
    }

    /**
     * Keep legacy description access while storing data in the new summary/content columns.
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->content ?? $this->summary,
            set: function ($value): void {
                $this->attributes['content'] = $value;
                $this->attributes['summary'] = $this->attributes['summary']
                    ?? ($value ? Str::limit((string) $value, 160) : null);
            },
        );
    }

    public function getSearchResult(): SearchResult
    {
        return new SearchResult(
            $this,
            $this->name,
            route('home.categories.show', $this)
        );
    }
}
