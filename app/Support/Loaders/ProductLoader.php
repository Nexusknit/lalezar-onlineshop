<?php

namespace App\Support\Loaders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class ProductLoader
{
    use ResolvesMediaUrls;

    public static function make(Product $product): array
    {
        $primaryCategory = $product->categories->first();
        $primaryBrand = $product->brands->first();
        $categoryPayload = [
            'name' => $primaryCategory?->parent?->name ?? $primaryCategory?->name,
            'child' => $primaryCategory?->slug,
        ];

        return [
            'sku' => $product->sku,
            'id' => $product->id,
            'title' => $product->name,
            'slug' => $product->slug,
            'image' => self::primaryImage($product),
            'category' => $categoryPayload,
            'price' => self::displayPrice($product),
            'currency' => $product->currency,
            'discount_percentage' => $product->discount_percent,
            'quantity' => self::availableQuantity($product),
            'stock_total' => self::totalStock($product),
            'stock_reserved' => self::reservedStock($product),
            'related_images' => self::relatedImages($product),
            'brand' => $primaryBrand?->name,
            'brand_slug' => $primaryBrand?->slug,
            'description' => $product->description,
            'additionalInformation' => self::additionalInformation($product),
            'tags' => $product->tags->pluck('name')->values()->all(),
            'feature_lists' => (array) data_get($product->meta, 'features', []),
            'reviews' => self::reviews($product),
            'sizes' => (array) data_get($product->meta, 'sizes', []),
            'colors' => (array) data_get($product->meta, 'colors', []),
            'variants' => $product->relationLoaded('variants')
                ? $product->variants->where('status', 'active')->map(fn (ProductVariant $variant) => self::variant($variant))->values()->all()
                : [],
            'barcode' => $product->barcode,
            'weight_grams' => $product->weight_grams,
            'dimensions_mm' => [
                'length' => $product->length_mm,
                'width' => $product->width_mm,
                'height' => $product->height_mm,
            ],
            'warranty' => $product->warranty,
            'min_order_quantity' => $product->min_order_quantity,
            'max_order_quantity' => $product->max_order_quantity,
            'sold' => $product->sold_count,
            'createdAt' => optional($product->created_at)->toAtomString(),
            'updatedAt' => optional($product->updated_at)->toAtomString(),
        ];
    }

    public static function variant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => (float) $variant->price,
            'quantity' => max(0, (int) $variant->stock - (int) $variant->stock_reserved),
            'stock_total' => (int) $variant->stock,
            'stock_reserved' => (int) $variant->stock_reserved,
            'status' => $variant->status,
            'options' => $variant->options ?? [],
            'image' => $variant->image ? self::mediaUrl($variant->image) : null,
            'weight_grams' => $variant->weight_grams,
            'dimensions_mm' => [
                'length' => $variant->length_mm,
                'width' => $variant->width_mm,
                'height' => $variant->height_mm,
            ],
            'warranty' => $variant->warranty,
            'min_order_quantity' => $variant->min_order_quantity,
            'max_order_quantity' => $variant->max_order_quantity,
        ];
    }

    protected static function displayPrice(Product $product): float
    {
        if ($product->relationLoaded('variants') && $product->variants->where('status', 'active')->isNotEmpty()) {
            return (float) $product->variants->where('status', 'active')->min('price');
        }

        return (float) $product->price;
    }

    protected static function availableQuantity(Product $product): int
    {
        if ($product->relationLoaded('variants') && $product->variants->where('status', 'active')->isNotEmpty()) {
            return (int) $product->variants->where('status', 'active')
                ->sum(fn (ProductVariant $variant): int => max(0, (int) $variant->stock - (int) $variant->stock_reserved));
        }

        return max(0, (int) $product->stock - (int) $product->stock_reserved);
    }

    protected static function totalStock(Product $product): int
    {
        return $product->relationLoaded('variants') && $product->variants->isNotEmpty()
            ? (int) $product->variants->sum('stock')
            : (int) $product->stock;
    }

    protected static function reservedStock(Product $product): int
    {
        return $product->relationLoaded('variants') && $product->variants->isNotEmpty()
            ? (int) $product->variants->sum('stock_reserved')
            : (int) $product->stock_reserved;
    }

    protected static function primaryImage(Product $product): ?string
    {
        $primaryGallery = $product->galleries->firstWhere('is_primary', true);
        if ($primaryGallery) {
            return self::galleryUrl($primaryGallery);
        }

        $featured = data_get($product->meta, 'primary_image');

        if ($featured) {
            return self::mediaUrl($featured);
        }

        return self::galleryUrl($product->galleries->first());
    }

    /**
     * @return list<string|null>
     */
    protected static function relatedImages(Product $product): array
    {
        return $product->galleries
            ->map(fn ($gallery) => self::galleryUrl($gallery))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{key:string,value:?string}>
     */
    protected static function additionalInformation(Product $product): array
    {
        return $product->attributes
            ->map(fn ($attribute) => [
                'key' => $attribute->key,
                'value' => $attribute->value ?? $attribute->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function reviews(Product $product): array
    {
        return $product->comments
            ->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'rating' => $comment->rating,
                    'comment' => $comment->comment,
                    'name' => $comment->user?->name,
                    'user' => null,
                    'date' => optional($comment->created_at)->toDateString(),
                ];
            })
            ->values()
            ->all();
    }
}
