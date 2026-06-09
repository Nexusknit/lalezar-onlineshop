<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    protected $fillable = [
        'name',
        'code',
        'status',
        'base_cost',
        'cost_per_kg',
        'max_weight_grams',
        'free_threshold',
        'state_ids',
        'city_ids',
        'estimated_days_min',
        'estimated_days_max',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'base_cost' => 'decimal:2',
        'cost_per_kg' => 'decimal:2',
        'max_weight_grams' => 'integer',
        'free_threshold' => 'decimal:2',
        'state_ids' => 'array',
        'city_ids' => 'array',
        'estimated_days_min' => 'integer',
        'estimated_days_max' => 'integer',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
