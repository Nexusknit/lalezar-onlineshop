<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Searchable\ModelSearchAspect;
use Spatie\Searchable\Search;
use Spatie\Searchable\SearchResult;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->string('q', ''));

        if ($query === '') {
            return response()->json([
                'query' => $query,
                'count' => 0,
                'results' => [],
            ]);
        }

        $search = (new Search())
            ->registerModel(Product::class, static function (ModelSearchAspect $aspect): void {
                $aspect->addSearchableAttribute('name')
                    ->addSearchableAttribute('slug')
                    ->addSearchableAttribute('summary')
                    ->addSearchableAttribute('content')
                    ->where(static function ($query): void {
                        $query->whereIn('status', ['active', 'special']);
                    });
            })
            ->registerModel(Blog::class, static function (ModelSearchAspect $aspect): void {
                $aspect->addSearchableAttribute('title')
                    ->addSearchableAttribute('slug')
                    ->addSearchableAttribute('summary')
                    ->addSearchableAttribute('content')
                    ->where(static function ($query): void {
                        $query->whereIn('status', ['active', 'special'])
                            ->where(static function ($query): void {
                                $query->whereNull('published_at')
                                    ->orWhere('published_at', '<=', now());
                            });
                    });
            })
            ->registerModel(News::class, static function (ModelSearchAspect $aspect): void {
                $aspect->addSearchableAttribute('headline')
                    ->addSearchableAttribute('slug')
                    ->addSearchableAttribute('summary')
                    ->addSearchableAttribute('content')
                    ->where(static function ($query): void {
                        $query->whereIn('status', ['active', 'special'])
                            ->where(static function ($query): void {
                                $query->whereNull('published_at')
                                    ->orWhere('published_at', '<=', now());
                            });
                    });
            })
            ->registerModel(Category::class, static function (ModelSearchAspect $aspect): void {
                $aspect->addSearchableAttribute('name')
                    ->addSearchableAttribute('slug')
                    ->addSearchableAttribute('summary')
                    ->addSearchableAttribute('content')
                    ->where('status', 'active');
            })
            ->limitAspectResults(15);

        $results = $search->search($query);

        $formatted = $results->map(static function (SearchResult $result) {
            $model = $result->searchable;

            $data = match (true) {
                $model instanceof Product => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'slug' => $model->slug,
                    'price' => $model->price,
                    'currency' => $model->currency,
                    'status' => $model->status,
                ],
                $model instanceof Blog => [
                    'id' => $model->id,
                    'title' => $model->title,
                    'slug' => $model->slug,
                    'published_at' => $model->published_at,
                    'status' => $model->status,
                ],
                $model instanceof News => [
                    'id' => $model->id,
                    'headline' => $model->headline,
                    'slug' => $model->slug,
                    'published_at' => $model->published_at,
                    'status' => $model->status,
                ],
                $model instanceof Category => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'slug' => $model->slug,
                    'status' => $model->status,
                ],
                default => $model->toArray(),
            };

            return [
                'title' => $result->title,
                'type' => class_basename($model),
                'url' => $result->url,
                'data' => $data,
            ];
        });

        return response()->json([
            'query' => $query,
            'count' => $formatted->count(),
            'results' => $formatted->values(),
        ]);
    }
}
