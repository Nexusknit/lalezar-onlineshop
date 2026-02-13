<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Loaders\ProductLoader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 12);
        $perPage = $perPage > 0 ? min($perPage, 100) : 12;

        $category = trim((string) $request->string('category', ''));
        $categoryChild = trim((string) $request->string('category_child', ''));
        $tag = trim((string) $request->string('tag', ''));
        $queryTerm = trim((string) $request->string('q', ''));
        $sort = strtolower(trim((string) $request->string('sort', '')));

        $brandSlugs = $this->csvValues($request->query('brand'));
        $colorVariants = $this->buildValueVariants($this->csvValues($request->query('color')));
        $sizeVariants = $this->buildValueVariants($this->csvValues($request->query('sizes')));

        $minPriceInput = $request->query('min_price', $request->query('minPrice'));
        $maxPriceInput = $request->query('max_price', $request->query('maxPrice'));

        $minPrice = is_numeric($minPriceInput) ? (float) $minPriceInput : null;
        $maxPrice = is_numeric($maxPriceInput) ? (float) $maxPriceInput : null;

        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
        }

        $rating = $request->filled('rating') ? max(1, min(5, (int) $request->integer('rating'))) : null;
        $needsRatingAggregate = in_array($sort, ['rating', 'rating_desc'], true);

        $query = Product::query()
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
            ->whereIn('status', ['active', 'special']);

        if ($needsRatingAggregate) {
            $query->withAvg(['comments as published_rating_avg' => static function (Builder $comments): void {
                $comments->whereIn('status', ['published', 'answered'])->whereNotNull('rating');
            }], 'rating');
        }

        if ($category !== '' && $category !== 'all') {
            $query->whereHas('categories', static function (Builder $categoryQuery) use ($category): void {
                $categoryQuery
                    ->where('slug', $category)
                    ->orWhereHas('parent', static function (Builder $parentQuery) use ($category): void {
                        $parentQuery->where('slug', $category);
                    });
            });
        }

        if ($categoryChild !== '') {
            $query->whereHas('categories', static function (Builder $categoryQuery) use ($categoryChild): void {
                $categoryQuery->where('slug', $categoryChild);
            });
        }

        if ($tag !== '') {
            $query->whereHas('tags', static function (Builder $tagQuery) use ($tag): void {
                $tagQuery->where('slug', $tag);
            });
        }

        if ($brandSlugs !== []) {
            $query->whereHas('brands', static function (Builder $brandQuery) use ($brandSlugs): void {
                $brandQuery->whereIn('slug', $brandSlugs);
            });
        }

        if ($queryTerm !== '') {
            $query->where(static function (Builder $textQuery) use ($queryTerm): void {
                $textQuery->where('name', 'like', "%{$queryTerm}%")
                    ->orWhere('slug', 'like', "%{$queryTerm}%")
                    ->orWhere('summary', 'like', "%{$queryTerm}%")
                    ->orWhere('content', 'like', "%{$queryTerm}%");
            });
        }

        if ($minPrice !== null) {
            $query->where('price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $query->where('price', '<=', $maxPrice);
        }

        if ($colorVariants !== []) {
            $query->where(static function (Builder $colorQuery) use ($colorVariants): void {
                foreach ($colorVariants as $color) {
                    $colorQuery->orWhereJsonContains('meta->colors', $color);
                }
            });
        }

        if ($sizeVariants !== []) {
            $query->where(static function (Builder $sizeQuery) use ($sizeVariants): void {
                foreach ($sizeVariants as $size) {
                    $sizeQuery->orWhereJsonContains('meta->sizes', $size);
                }
            });
        }

        if ($rating !== null) {
            $ratingSql = "(select avg(comments.rating) from comments
                where comments.model_id = products.id
                and comments.model_type = ?
                and comments.status in (?, ?)
                and comments.deleted_at is null
                and comments.rating is not null)";

            $query
                ->whereRaw("{$ratingSql} >= ?", [Product::class, 'published', 'answered', $rating])
                ->whereRaw("{$ratingSql} < ?", [Product::class, 'published', 'answered', $rating + 1]);
        }

        $this->applySort($query, $sort);

        $products = $query
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

    /**
     * @return list<string>
     */
    protected function csvValues(mixed $value): array
    {
        $parts = [];

        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value)) {
            $parts = explode(',', $value);
        }

        return collect($parts)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    protected function buildValueVariants(array $values): array
    {
        $variants = collect($values)
            ->flatMap(static function (string $value): array {
                $normalized = trim($value);
                if ($normalized === '') {
                    return [];
                }

                return [
                    $normalized,
                    strtolower($normalized),
                    strtoupper($normalized),
                    ucfirst(strtolower($normalized)),
                    ucwords(strtolower($normalized)),
                ];
            })
            ->unique()
            ->values()
            ->all();

        return $variants;
    }

    protected function applySort(Builder $query, string $sort): void
    {
        if (in_array($sort, ['low', 'price_asc'], true)) {
            $query->orderBy('price');
            return;
        }

        if (in_array($sort, ['high', 'price_desc'], true)) {
            $query->orderByDesc('price');
            return;
        }

        if (in_array($sort, ['sale', 'discount'], true)) {
            $query->orderByDesc('discount_percent')->latest();
            return;
        }

        if (in_array($sort, ['top_selling', 'sold'], true)) {
            $query->orderByDesc('sold_count')->latest();
            return;
        }

        if (in_array($sort, ['old', 'oldest'], true)) {
            $query->oldest();
            return;
        }

        if (in_array($sort, ['rating', 'rating_desc'], true)) {
            $query->orderByDesc('published_rating_avg')->latest();
            return;
        }

        $query->latest();
    }
}
