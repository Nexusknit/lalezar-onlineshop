<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeProductFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_products_parent_category_slug_includes_products_assigned_to_child_category(): void
    {
        $creator = User::factory()->create();

        $parent = Category::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Smart Living',
            'slug' => 'smart-living',
            'status' => 'active',
        ]);

        $child = Category::query()->create([
            'creator_id' => $creator->id,
            'parent_id' => $parent->id,
            'name' => 'Commercial Solutions',
            'slug' => 'commercial-solutions',
            'status' => 'active',
        ]);

        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Smart Controller',
            'slug' => 'smart-controller',
            'sku' => 'SMRT-CTRL-001',
            'stock' => 12,
            'price' => 150000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $product->categories()->sync([$child->id]);

        $this->getJson('/api/home/products?category=smart-living')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.category.child', 'commercial-solutions')
            ->assertJsonPath('data.0.currency', 'IRR');
    }

    public function test_home_products_supports_combined_brand_category_price_color_size_and_rating_filters(): void
    {
        $creator = User::factory()->create();
        $reviewer = User::factory()->create();

        $parent = Category::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Smart Living',
            'slug' => 'smart-living',
            'status' => 'active',
        ]);

        $brand = Brand::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Pulse Smart',
            'slug' => 'pulse-smart',
            'status' => 'active',
        ]);

        $match = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Pulse Hub Pro',
            'slug' => 'pulse-hub-pro',
            'sku' => 'PULSE-001',
            'stock' => 8,
            'price' => 150,
            'currency' => 'IRR',
            'status' => 'active',
            'meta' => [
                'colors' => ['black', 'white'],
                'sizes' => ['m', 'l'],
            ],
        ]);

        $nonMatching = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Pulse Lite',
            'slug' => 'pulse-lite',
            'sku' => 'PULSE-002',
            'stock' => 8,
            'price' => 150,
            'currency' => 'IRR',
            'status' => 'active',
            'meta' => [
                'colors' => ['red'],
                'sizes' => ['xl'],
            ],
        ]);

        $match->categories()->sync([$parent->id]);
        $nonMatching->categories()->sync([$parent->id]);

        $match->brands()->sync([$brand->id]);
        $nonMatching->brands()->sync([$brand->id]);

        Comment::query()->create([
            'user_id' => $reviewer->id,
            'model_id' => $match->id,
            'model_type' => Product::class,
            'comment' => 'Great',
            'rating' => 4,
            'status' => 'published',
        ]);

        Comment::query()->create([
            'user_id' => $reviewer->id,
            'model_id' => $nonMatching->id,
            'model_type' => Product::class,
            'comment' => 'Okay',
            'rating' => 2,
            'status' => 'published',
        ]);

        $this->getJson('/api/home/products?category=smart-living&brand=pulse-smart&color=black&sizes=m&minPrice=120&maxPrice=200&rating=4')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.currency', 'IRR');
    }

    public function test_home_brand_slug_endpoint_returns_brand_and_active_products(): void
    {
        $creator = User::factory()->create();
        $brand = Brand::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Lalezar Atelier',
            'slug' => 'lalezar-atelier',
            'status' => 'active',
        ]);

        $activeProduct = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Atelier Lamp',
            'slug' => 'atelier-lamp',
            'sku' => 'ATL-001',
            'stock' => 20,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $draftProduct = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Hidden Lamp',
            'slug' => 'hidden-lamp',
            'sku' => 'ATL-002',
            'stock' => 20,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'draft',
        ]);

        $brand->products()->sync([$activeProduct->id, $draftProduct->id]);

        $this->getJson('/api/home/brands/lalezar-atelier')
            ->assertOk()
            ->assertJsonPath('brand.slug', 'lalezar-atelier')
            ->assertJsonPath('products.data.0.id', $activeProduct->id)
            ->assertJsonPath('products.data.0.currency', 'IRR')
            ->assertJsonMissing(['id' => $draftProduct->id]);
    }
}
