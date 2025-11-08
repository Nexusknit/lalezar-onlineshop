<?php

namespace App\Support\Loaders;

use App\Models\Brand;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class BrandLoader
{
    use ResolvesMediaUrls;

    public static function make(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'products' => $brand->relationLoaded('products')
                ? $brand->products->pluck('id')->values()->all()
                : [],
            'name' => $brand->name,
            'description' => $brand->description,
            'email' => $brand->email,
            'website' => $brand->website,
            'location' => $brand->location,
            'status' => $brand->status,
            'logo' => self::mediaUrl($brand->logo_path),
        ];
    }
}
