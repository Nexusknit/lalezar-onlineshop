<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_show_product_and_delete_category_when_permissions_exist(): void
    {
        $admin = $this->createUserWithPermissions('product.all', 'category.delete');
        Sanctum::actingAs($admin);

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Admin Product',
            'slug' => 'admin-product',
            'sku' => 'ADM-PROD-001',
            'stock' => 8,
            'price' => 200000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $category = Category::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Admin Category',
            'slug' => 'admin-category',
            'status' => 'active',
        ]);

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('name', 'Admin Product');

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted successfully.');

        $this->assertSoftDeleted('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_admin_can_toggle_user_accessibility(): void
    {
        $admin = $this->createUserWithPermissions('user.accessibility');
        Sanctum::actingAs($admin);

        $target = User::factory()->create([
            'accessibility' => true,
        ]);

        $this->postJson("/api/admin/users/{$target->id}/accessibility", [
            'accessibility' => false,
        ])
            ->assertOk()
            ->assertJsonPath('id', $target->id)
            ->assertJsonPath('accessibility', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'accessibility' => 0,
        ]);
    }

    public function test_admin_product_show_requires_permission(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Denied Product',
            'slug' => 'denied-product',
            'sku' => 'DENY-PROD-001',
            'stock' => 1,
            'price' => 1000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertForbidden();
    }

    protected function createUserWithPermissions(string ...$slugs): User
    {
        $user = User::factory()->create([
            'accessibility' => true,
        ]);

        $permissionIds = collect($slugs)->map(static function (string $slug) {
            return Permission::query()->create([
                'name' => $slug,
                'slug' => $slug,
            ])->id;
        })->all();

        $user->permissions()->sync($permissionIds);

        return $user;
    }
}
