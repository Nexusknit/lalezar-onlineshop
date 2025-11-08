<?php

namespace App\Support\Loaders;

use App\Models\Category;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class CategoryLoader
{
    use ResolvesMediaUrls;

    public static function make(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent' => $category->parent?->name,
            'slug' => $category->slug,
            'children' => $category->relationLoaded('children')
                ? $category->children->pluck('name')->values()->all()
                : [],
            'icon' => $category->icon,
            'image' => self::mediaUrl($category->image_path),
            'products' => $category->relationLoaded('products')
                ? $category->products->pluck('id')->values()->all()
                : [],
        ];
    }
}
