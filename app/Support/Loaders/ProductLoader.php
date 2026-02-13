<?php

namespace App\Support\Loaders;

use App\Models\Product;
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
            'price' => (float) $product->price,
            'currency' => $product->currency,
            'discount_percentage' => $product->discount_percent,
            'quantity' => $product->stock,
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
            'sold' => $product->sold_count,
            'createdAt' => optional($product->created_at)->toAtomString(),
            'updatedAt' => optional($product->updated_at)->toAtomString(),
        ];
    }

    protected static function primaryImage(Product $product): ?string
    {
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
