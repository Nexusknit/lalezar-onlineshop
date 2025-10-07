<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'model_id',
        'model_type',
        'key',
        'value',
        'amount',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
