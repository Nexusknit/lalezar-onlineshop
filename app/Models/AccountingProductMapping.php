<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingProductMapping extends Model
{
    protected $fillable = [
        'product_id',
        'provider',
        'external_id',
        'checksum',
        'remote_updated_at',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'remote_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
