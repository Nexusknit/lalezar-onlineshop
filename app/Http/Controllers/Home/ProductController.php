<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Loaders\ProductLoader;
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
                'brands:id,name,slug',
                'categories:id,name,slug,parent_id',
                'categories.parent:id,name,slug',
                'tags:id,name,slug',
                'attributes:id,creator_id,model_id,model_type,key,value,amount',
                'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
                'comments' => static function ($query): void {
                    $query->whereIn('status', ['published', 'answered'])
                        ->with(['user:id,name'])
                        ->latest()
                        ->take(10);
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
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('content', 'like', "%{$term}%");
                });
            })
            ->latest()
            ->paginate($perPage)
            ->through(fn (Product $product) => ProductLoader::make($product));

        return response()->json($products);
    }

    public function single(Product $product): JsonResponse
    {
        $allowed = ['active', 'special'];

        abort_if(! in_array($product->status, $allowed, true), 404, 'Product is not available.');

        $product->load([
            'brands:id,name,slug',
            'categories:id,name,slug,parent_id',
            'categories.parent:id,name,slug',
            'tags:id,name,slug',
            'attributes:id,creator_id,model_id,model_type,key,value,amount',
            'galleries:id,creator_id,model_id,model_type,disk,path,title,alt,created_at',
            'comments' => static function ($query): void {
                $query->whereIn('status', ['published', 'answered'])
                    ->with(['user:id,name'])
                    ->latest();
            },
        ]);

        return response()->json(ProductLoader::make($product));
    }
}
