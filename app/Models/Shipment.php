<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    public const STATUSES = [
        'preparing',
        'ready',
        'handed_over',
        'in_transit',
        'delivered',
        'returned',
        'cancelled',
    ];

    protected $fillable = [
        'invoice_id',
        'shipping_method_id',
        'status',
        'carrier',
        'tracking_code',
        'shipped_at',
        'delivered_at',
        'meta',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'meta' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
