<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 12);
        $perPage = $perPage > 0 ? min($perPage, 100) : 12;

        $products = Product::query()
            ->with([
                'creator:id,name',
                'categories:id,name,slug',
                'tags:id,name,slug',
                'attributes:id,creator_id,model_id,model_type,key,value,amount',
                'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
            ])
            ->withCount([
                'likes',
                'comments as approved_comments_count' => function ($query): void {
                    $query->whereIn('status', ['published', 'answered']);
                },
            ])
            ->whereIn('status', ['active', 'special'])
            ->when($request->filled('category'), static function ($query) use ($request): void {
                $slug = $request->string('category');
                $query->whereHas('categories', static function ($query) use ($slug): void {
                    $query->where('slug', $slug);
                });
            })
            ->when($request->filled('tag'), static function ($query) use ($request): void {
                $slug = $request->string('tag');
                $query->whereHas('tags', static function ($query) use ($slug): void {
                    $query->where('slug', $slug);
                });
            })
            ->when($request->filled('q'), static function ($query) use ($request): void {
                $term = $request->string('q');
                $query->where(static function ($query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($products);
    }

    public function single(Product $product): JsonResponse
    {
        $allowed = ['active', 'special'];

        abort_if(! in_array($product->status, $allowed, true), 404, 'Product is not available.');

        $product->load([
            'creator:id,name',
            'categories:id,name,slug',
            'tags:id,name,slug',
            'attributes:id,creator_id,model_id,model_type,key,value,amount',
            'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
            'likes:id,creator_id,model_id,model_type,created_at',
            'comments' => static function ($query): void {
                $query->whereIn('status', ['published', 'answered'])
                    ->with(['user:id,name'])
                    ->latest();
            },
        ])->loadCount([
            'likes',
            'comments as approved_comments_count' => static function ($query): void {
                $query->whereIn('status', ['published', 'answered']);
            },
        ]);

        $product->setRelation('likes', $product->likes->take(20));
        $product->setRelation('comments', $product->comments->take(20));

        return response()->json($product);
    }
}
