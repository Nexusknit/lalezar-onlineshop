<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'invoice_id',
        'actor_id',
        'type',
        'stock_delta',
        'reserved_delta',
        'stock_after',
        'reserved_after',
        'reason',
        'meta',
    ];

    protected $casts = [
        'stock_delta' => 'integer',
        'reserved_delta' => 'integer',
        'stock_after' => 'integer',
        'reserved_after' => 'integer',
        'meta' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
