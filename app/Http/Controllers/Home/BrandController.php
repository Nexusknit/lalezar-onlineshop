<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Loaders\BrandLoader;
use App\Support\Loaders\ProductLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $status = $request->string('status', 'active');

        $brands = Brand::query()
            ->with([
                'products:id',
            ])
            ->when($status && $status !== 'all', static function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => BrandLoader::make($brand));

        return response()->json($brands);
    }

    public function single(Request $request, Brand $brand): JsonResponse
    {
        abort_if(! in_array($brand->status, ['active', 'special'], true), 404, 'Brand is not available.');

        $perPage = (int) $request->integer('per_page', 24);
        $perPage = $perPage > 0 ? min($perPage, 100) : 24;

        $products = $brand->products()
            ->whereIn('products.status', ['active', 'special'])
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
            ->latest('products.created_at')
            ->paginate($perPage)
            ->through(fn (Product $product) => ProductLoader::make($product));

        return response()->json([
            'brand' => BrandLoader::make($brand),
            'products' => $products,
        ]);
    }
}
