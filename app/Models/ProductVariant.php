<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'price',
        'stock',
        'stock_reserved',
        'status',
        'options',
        'image',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
        'warranty',
        'min_order_quantity',
        'max_order_quantity',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'stock_reserved' => 'integer',
        'options' => 'array',
        'weight_grams' => 'integer',
        'length_mm' => 'integer',
        'width_mm' => 'integer',
        'height_mm' => 'integer',
        'min_order_quantity' => 'integer',
        'max_order_quantity' => 'integer',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    protected $appends = ['available_stock'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    protected function availableStock(): Attribute
    {
        return Attribute::get(
            fn (): int => max(0, (int) $this->stock - (int) $this->stock_reserved)
        );
    }
}
