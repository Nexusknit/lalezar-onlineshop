<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class Brand extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'creator_id',
        'name',
        'slug',
        'summary',
        'content',
        'description',
        'email',
        'website',
        'location',
        'status',
        'logo_path',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

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
}
