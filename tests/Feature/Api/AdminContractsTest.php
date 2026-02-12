<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_seeder_covers_all_controller_permission_middleware_slugs(): void
    {
        $controllerFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $controllerFiles[] = $file->getPathname();
        }

        $usedSlugs = [];
        foreach ($controllerFiles as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            if (preg_match_all('/permission:([A-Za-z0-9_.]+)/', $source, $matches)) {
                foreach ($matches[1] as $slug) {
                    $usedSlugs[$slug] = true;
                }
            }
        }

        $seedSource = file_get_contents(base_path('database/seeders/PermissionSeeder.php')) ?: '';
        $declaredSlugs = [];
        if (preg_match_all("/'([a-z0-9]+(?:[.][A-Za-z0-9]+)+)'/", $seedSource, $matches)) {
            foreach ($matches[1] as $slug) {
                $declaredSlugs[$slug] = true;
            }
        }

        $missing = array_keys(array_diff_key($usedSlugs, $declaredSlugs));
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            'Missing permission slug(s) in PermissionSeeder: '.implode(', ', $missing)
        );
    }

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

    public function test_admin_product_index_can_filter_by_search_parameter(): void
    {
        $admin = $this->createUserWithPermissions('product.all');
        Sanctum::actingAs($admin);

        $creator = User::factory()->create();
        $matched = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Searchable Lamp',
            'slug' => 'searchable-lamp',
            'sku' => 'SRCH-LAMP-001',
            'stock' => 5,
            'price' => 99000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $notMatched = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Desk Fan',
            'slug' => 'desk-fan',
            'sku' => 'FAN-001',
            'stock' => 5,
            'price' => 88000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/admin/products?search=lamp');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matched->id));
        $this->assertFalse($ids->contains($notMatched->id));
    }

    public function test_admin_can_create_user_without_email(): void
    {
        $admin = $this->createUserWithPermissions('user.store');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/users', [
            'name' => 'Phone Only User',
            'phone' => '09123334455',
            'password' => 'secret123',
        ])
            ->assertCreated()
            ->assertJsonPath('phone', '09123334455')
            ->assertJsonPath('email', '09123334455@phone.local');

        $this->assertDatabaseHas('users', [
            'phone' => '09123334455',
            'email' => '09123334455@phone.local',
        ]);
    }

    public function test_admin_user_index_can_filter_by_phone_search_parameter(): void
    {
        $admin = $this->createUserWithPermissions('user.all');
        Sanctum::actingAs($admin);

        $matched = User::factory()->create([
            'name' => 'Matched User',
            'phone' => '09125550001',
            'email' => 'matched@example.com',
        ]);

        $notMatched = User::factory()->create([
            'name' => 'Other User',
            'phone' => '09126660002',
            'email' => 'other@example.com',
        ]);

        $response = $this->getJson('/api/admin/users?search=5550001');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matched->id));
        $this->assertFalse($ids->contains($notMatched->id));
    }

    public function test_admin_upload_requires_permission(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->post('/api/admin/uploads', [
            'file' => UploadedFile::fake()->image('catalog.jpg'),
        ], [
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    public function test_admin_can_upload_image_when_permission_exists(): void
    {
        Storage::fake('public');

        $admin = $this->createUserWithPermissions('upload.store');
        Sanctum::actingAs($admin);

        $response = $this->post('/api/admin/uploads', [
            'file' => UploadedFile::fake()->image('catalog.jpg'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('disk', 'public');

        $path = (string) $response->json('path');
        $this->assertNotSame('', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_impersonation_token_has_expiration(): void
    {
        $admin = $this->createUserWithPermissions('user.login');
        Sanctum::actingAs($admin);

        $target = User::factory()->create();

        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id);

        $this->assertNotNull($response->json('token'));
        $this->assertNotNull($response->json('expires_at'));

        $tokenRow = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $target->id)
            ->where('name', 'impersonation-token')
            ->latest('id')
            ->first();

        $this->assertNotNull($tokenRow);
        $this->assertNotNull($tokenRow->expires_at);
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
