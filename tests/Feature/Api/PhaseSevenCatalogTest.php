<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\State;
use App\Models\User;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseSevenCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_purchase_reserves_commits_and_records_inventory_movements(): void
    {
        [$user, $address, $product, $variant] = $this->commerceContext();
        Sanctum::actingAs($user);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('items.0.variant.id', $variant->id)
            ->assertJsonPath('items.0.unit_price', 180000);

        $checkout = $this->postJson('/api/user/checkout', [
            'address_id' => $address->id,
        ])
            ->assertOk()
            ->assertJsonPath('items.0.product_variant_id', $variant->id)
            ->assertJsonPath('items.0.unit_price', '180000.00');

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock' => 7,
            'stock_reserved' => 2,
        ]);

        $payment = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $checkout->json('id'),
        ])->assertOk();

        $this->postJson("/api/user/payments/{$payment->json('payment.id')}/verify", [
            'status' => 'success',
            'reference' => 'PHASE7-PAID-001',
        ])
            ->assertOk()
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAID);

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock' => 5,
            'stock_reserved' => 0,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sold_count' => 2,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'reserve',
            'reserved_delta' => 2,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'stock_delta' => -2,
            'reserved_delta' => -2,
        ]);
    }

    public function test_failed_variant_payment_releases_and_retry_reserves_without_reducing_total_stock(): void
    {
        [$user, $address, $product, $variant] = $this->commerceContext();
        Sanctum::actingAs($user);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertCreated();
        $checkout = $this->postJson('/api/user/checkout', ['address_id' => $address->id])->assertOk();
        $payment = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $checkout->json('id'),
        ])->assertOk();

        $this->postJson("/api/user/payments/{$payment->json('payment.id')}/verify", [
            'status' => 'failed',
            'reason' => 'phase_seven_test',
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock' => 7,
            'stock_reserved' => 0,
        ]);

        $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $checkout->json('id'),
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock' => 7,
            'stock_reserved' => 1,
        ]);
    }

    public function test_cancelling_reserved_variant_order_releases_inventory(): void
    {
        [$user, $address, $product, $variant] = $this->commerceContext();
        Sanctum::actingAs($user);
        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertCreated();
        $checkout = $this->postJson('/api/user/checkout', ['address_id' => $address->id])->assertOk();

        $admin = User::factory()->create(['accessibility' => true]);
        $permission = Permission::query()->create([
            'name' => 'تغییر وضعیت فاکتور',
            'slug' => 'invoice.updateStatus',
            'guard_name' => 'web',
        ]);
        $admin->permissions()->attach($permission);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/invoices/{$checkout->json('id')}/status", [
            'status' => InvoiceStatusService::CANCELLED,
            'note' => 'لغو توسط مدیر',
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock' => 7,
            'stock_reserved' => 0,
        ]);
    }

    public function test_refunding_paid_variant_order_restores_sold_inventory(): void
    {
        [$user, $address, $product, $variant] = $this->commerceContext();
        Sanctum::actingAs($user);
        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertCreated();
        $checkout = $this->postJson('/api/user/checkout', ['address_id' => $address->id])->assertOk();
        $payment = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $checkout->json('id'),
        ])->assertOk();
        $this->postJson("/api/user/payments/{$payment->json('payment.id')}/verify", [
            'status' => 'success',
            'reference' => 'PHASE7-REFUND-001',
        ])->assertOk();

        $admin = User::factory()->create(['accessibility' => true]);
        $permission = Permission::query()->create([
            'name' => 'تغییر وضعیت فاکتور',
            'slug' => 'invoice.updateStatus',
            'guard_name' => 'web',
        ]);
        $admin->permissions()->attach($permission);
        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/invoices/{$checkout->json('id')}/status", [
            'status' => InvoiceStatusService::REFUNDED,
            'note' => 'استرداد تست',
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock' => 7,
            'stock_reserved' => 0,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sold_count' => 0,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'restore',
            'stock_delta' => 1,
        ]);
    }

    public function test_coupon_preview_uses_selected_variant_price(): void
    {
        [$user, $address, $product, $variant] = $this->commerceContext();
        Sanctum::actingAs($user);
        Coupon::query()->create([
            'code' => 'VARIANT10',
            'title' => 'تخفیف مدل',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'currency' => 'IRR',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->postJson('/api/cart/coupon', [
            'coupon_code' => 'VARIANT10',
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('summary.subtotal', 360000)
            ->assertJsonPath('summary.discount', 36000);
    }

    public function test_admin_can_manage_variant_and_primary_gallery(): void
    {
        [$user, $address, $product] = $this->commerceContext();
        $admin = User::factory()->create(['accessibility' => true]);
        $permissions = collect(['product.update', 'relation.attachGallery'])
            ->map(fn (string $slug) => Permission::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'guard_name' => 'web',
            ]));
        $admin->permissions()->attach($permissions->pluck('id'));
        Sanctum::actingAs($admin);

        $createdVariant = $this->postJson("/api/admin/products/{$product->id}/variants", [
            'name' => 'سه پل ۳۲ آمپر',
            'sku' => 'MCB-3P-32A',
            'price' => 540000,
            'stock' => 4,
            'status' => 'active',
            'options' => ['تعداد پل' => 'سه پل'],
        ])->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/variants/{$createdVariant->json('id')}", [
            'price' => 560000,
            'stock' => 6,
        ])->assertOk();

        $firstGallery = $this->postJson('/api/admin/relations/galleries', [
            'model_type' => 'product',
            'model_id' => $product->id,
            'path' => 'products/phase-seven-1.jpg',
            'sort_order' => 2,
            'is_primary' => true,
        ])->assertCreated();
        $secondGallery = $this->postJson('/api/admin/relations/galleries', [
            'model_type' => 'product',
            'model_id' => $product->id,
            'path' => 'products/phase-seven-2.jpg',
            'sort_order' => 1,
            'is_primary' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('galleries', [
            'id' => $firstGallery->json('id'),
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('galleries', [
            'id' => $secondGallery->json('id'),
            'sort_order' => 1,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('price_histories', [
            'product_variant_id' => $createdVariant->json('id'),
            'new_price' => 560000,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $createdVariant->json('id'),
            'stock_delta' => 2,
            'reason' => 'admin_update',
        ]);
    }

    private function commerceContext(): array
    {
        $user = User::factory()->create();
        $state = State::query()->create(['name' => 'تهران', 'slug' => 'phase-seven-state']);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'تهران',
            'slug' => 'phase-seven-city',
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'city_id' => $city->id,
            'recipient_name' => 'خریدار تست',
            'phone' => '09120000000',
            'street_line1' => 'خیابان لاله‌زار',
        ]);
        ShippingMethod::query()->create([
            'name' => 'ارسال عادی',
            'code' => 'phase-seven-shipping',
            'status' => 'active',
            'base_cost' => 0,
        ]);
        $product = Product::query()->create([
            'creator_id' => $user->id,
            'name' => 'کلید مینیاتوری',
            'slug' => 'phase-seven-breaker',
            'sku' => 'MCB-BASE',
            'stock' => 0,
            'price' => 150000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'تک پل ۱۶ آمپر',
            'sku' => 'MCB-1P-16A',
            'price' => 180000,
            'stock' => 7,
            'status' => 'active',
            'options' => ['تعداد پل' => 'تک پل', 'جریان نامی' => '۱۶ آمپر'],
        ]);

        return [$user, $address, $product, $variant];
    }
}
