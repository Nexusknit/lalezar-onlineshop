<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Like;
use App\Models\Product;
use App\Support\Loaders\ProductLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $likes = $request->user()
            ->likes()
            ->where('model_type', Product::class)
            ->with('model')
            ->latest()
            ->paginate($perPage)
            ->through(static function ($like) {
                $model = $like->model;
                if ($model instanceof Product) {
                    $model->loadMissing([
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
                }

                return [
                    'id' => $like->id,
                    'liked_at' => $like->created_at,
                    'type' => class_basename($like->model_type),
                    'model' => $model instanceof Product ? ProductLoader::make($model) : $model,
                ];
            });

        return response()->json($likes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
        ]);

        $product = Product::query()
            ->whereIn('status', ['active', 'special'])
            ->findOrFail($data['product_id']);

        $product->loadMissing([
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

        $like = Like::query()->firstOrCreate([
            'creator_id' => $request->user()->id,
            'model_id' => $product->id,
            'model_type' => Product::class,
        ]);

        return response()->json([
            'id' => $like->id,
            'liked_at' => $like->created_at,
            'type' => class_basename($like->model_type),
            'model' => ProductLoader::make($product),
        ], 201);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()
            ->likes()
            ->where('model_type', Product::class)
            ->where('model_id', $product->id)
            ->delete();

        return response()->json([
            'message' => 'Favorite removed successfully.',
        ]);
    }
}
