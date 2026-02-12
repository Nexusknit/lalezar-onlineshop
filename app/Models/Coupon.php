<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Coupon extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENT = 'percent';

    protected $fillable = [
        'code',
        'title',
        'description',
        'discount_type',
        'discount_value',
        'min_subtotal',
        'max_discount',
        'currency',
        'starts_at',
        'ends_at',
        'max_uses',
        'max_uses_per_user',
        'used_count',
        'status',
        'meta',
    ];

    protected $casts = [
        'discount_value' => 'float',
        'min_subtotal' => 'float',
        'max_discount' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'used_count' => 'integer',
        'meta' => 'array',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function isActive(?Carbon $moment = null): bool
    {
        $now = $moment ?: now();

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isAfter($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isBefore($now)) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $discount = match ($this->discount_type) {
            self::TYPE_PERCENT => ($subtotal * max(0, $this->discount_value)) / 100,
            default => max(0, $this->discount_value),
        };

        if ($this->max_discount !== null) {
            $discount = min($discount, max(0, $this->max_discount));
        }

        $discount = min($discount, $subtotal);

        return round($discount, 2);
    }
}
