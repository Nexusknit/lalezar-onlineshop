<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Cart;
use App\Models\City;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\State;
use App\Models\User;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseSixCommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cart_is_persistent_and_can_be_merged_after_login(): void
    {
        $product = $this->product(stock: 10);

        $created = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.product.title', 'کابل افشان تست');

        $guestToken = (string) $created->json('token');
        $this->assertNotSame('', $guestToken);

        $this->withHeader('X-Cart-Token', $guestToken)
            ->patchJson("/api/cart/items/{$product->id}", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 3);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/cart/merge', ['guest_token' => $guestToken])
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 3);

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('carts', ['token' => $guestToken]);
    }

    public function test_shipping_options_respect_city_and_checkout_creates_shipment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $state = State::query()->create(['name' => 'تهران', 'slug' => 'tehran-phase-six', 'code' => 'P6']);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'تهران',
            'slug' => 'tehran-city-phase-six',
            'code' => 'P6-1',
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'city_id' => $city->id,
            'recipient_name' => 'کاربر تست',
            'phone' => '09120000000',
            'street_line1' => 'خیابان لاله‌زار',
        ]);
        $method = ShippingMethod::query()->create([
            'name' => 'پیک تهران',
            'code' => 'tehran-courier',
            'status' => 'active',
            'base_cost' => 25000,
            'cost_per_kg' => 5000,
            'max_weight_grams' => 5000,
            'free_threshold' => 500000,
            'city_ids' => [$city->id],
            'estimated_days_min' => 1,
            'estimated_days_max' => 1,
        ]);
        $product = $this->product(stock: 5, price: 100000);
        $product->update(['weight_grams' => 1300]);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertCreated();

        $this->postJson('/api/cart/shipping-options', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('options.0.id', $method->id)
            ->assertJsonPath('options.0.cost', 40000)
            ->assertJsonPath('options.0.total_weight_grams', 2600)
            ->assertJsonPath('options.0.weight_surcharge', 15000);

        $checkout = $this->postJson('/api/user/checkout', [
            'address_id' => $address->id,
            'shipping_method_id' => $method->id,
        ])->assertOk()
            ->assertJsonPath('shipping', '40000.00')
            ->assertJsonPath('shipment.status', 'preparing')
            ->assertJsonPath('shipment.shipping_method.id', $method->id);

        $this->assertDatabaseHas('shipments', [
            'invoice_id' => $checkout->json('id'),
            'shipping_method_id' => $method->id,
            'status' => 'preparing',
        ]);
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => Cart::query()->where('user_id', $user->id)->value('id'),
        ]);

        $invoice = Invoice::query()->findOrFail($checkout->json('id'));
        $invoice->update(['status' => InvoiceStatusService::PAID]);
        $admin = User::factory()->create(['accessibility' => true]);
        $permission = Permission::query()->create([
            'name' => 'ویرایش مرسوله',
            'slug' => 'shipment.update',
            'guard_name' => 'web',
        ]);
        $admin->permissions()->attach($permission);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/shipments/{$invoice->shipment->id}", [
            'status' => 'delivered',
            'carrier' => 'پست پیشتاز',
            'tracking_code' => '1234567890',
        ])->assertOk()
            ->assertJsonPath('status', 'delivered')
            ->assertJsonPath('tracking_code', '1234567890');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => InvoiceStatusService::DELIVERED,
        ]);
    }

    public function test_overweight_cart_does_not_fall_back_to_legacy_shipping(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $state = State::query()->create(['name' => 'تهران', 'slug' => 'tehran-overweight']);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'تهران',
            'slug' => 'tehran-city-overweight',
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'city_id' => $city->id,
            'recipient_name' => 'کاربر تست',
            'phone' => '09120000000',
            'street_line1' => 'خیابان لاله‌زار',
        ]);
        ShippingMethod::query()->create([
            'name' => 'پیک سبک',
            'code' => 'light-courier',
            'status' => 'active',
            'base_cost' => 25000,
            'max_weight_grams' => 1000,
            'city_ids' => [$city->id],
        ]);
        $product = $this->product(stock: 2);
        $product->update(['weight_grams' => 1500]);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/cart/shipping-options', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonCount(0, 'options');

        $this->postJson('/api/user/checkout', [
            'address_id' => $address->id,
        ])->assertStatus(422);
    }

    private function product(int $stock, float $price = 120000): Product
    {
        $creator = User::factory()->create();

        return Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'کابل افشان تست',
            'slug' => 'phase-six-test-cable-'.uniqid(),
            'sku' => 'P6-'.uniqid(),
            'stock' => $stock,
            'price' => $price,
            'currency' => 'IRR',
            'status' => 'active',
        ]);
    }
}
