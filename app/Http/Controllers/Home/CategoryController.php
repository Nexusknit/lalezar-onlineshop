<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function all(): JsonResponse
    {
        $categories = Category::query()
            ->with([
                'children:id,parent_id,name,slug,description,order_column,status,is_special',
            ])
            ->where('status', 'active')
            ->orderBy('order_column')
            ->get([
                'id',
                'creator_id',
                'parent_id',
                'name',
                'slug',
                'description',
                'order_column',
                'status',
                'is_special',
                'created_at',
                'updated_at',
            ]);

        return response()->json($categories);
    }

    public function single(Category $category): JsonResponse
    {
        abort_if($category->status !== 'active', 404, 'Category is not available.');

        $category->load([
            'parent:id,name,slug',
            'children:id,parent_id,name,slug,description,order_column,status,is_special',
            'products' => static function ($query): void {
                $query->select(['products.id', 'products.creator_id', 'products.name', 'products.slug', 'products.price', 'products.currency', 'products.status'])
                    ->whereIn('products.status', ['active', 'special'])
                    ->with([
                        'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
                    ])
                    ->latest();
            },
            'blogs' => static function ($query): void {
                $query->select(['blogs.id', 'blogs.creator_id', 'blogs.title', 'blogs.slug', 'blogs.status', 'blogs.published_at'])
                    ->whereIn('blogs.status', ['active', 'special'])
                    ->where(static function ($query): void {
                        $query->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    })
                    ->latest('published_at');
            },
            'news' => static function ($query): void {
                $query->select(['news.id', 'news.creator_id', 'news.headline', 'news.slug', 'news.status', 'news.published_at'])
                    ->whereIn('news.status', ['active', 'special'])
                    ->where(static function ($query): void {
                        $query->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    })
                    ->latest('published_at');
            },
        ]);

        return response()->json($category);
    }
}
