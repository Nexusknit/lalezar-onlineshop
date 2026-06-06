<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Invoices\InvoiceStatusService;
use App\Support\Checkout\CheckoutPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_operational_kpis(): void
    {
        $admin = $this->actingAsAdminWith('dashboard.view');
        $customer = User::factory()->create(['phone' => '09124445566']);
        Product::query()->create([
            'creator_id' => $admin->id,
            'name' => 'کلید مینیاتوری',
            'slug' => 'miniature-circuit-breaker',
            'sku' => 'MCB-001',
            'stock' => 3,
            'price' => 250000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);
        Invoice::query()->create([
            'user_id' => $customer->id,
            'number' => 'INV-DASHBOARD-001',
            'status' => InvoiceStatusService::PAID,
            'currency' => 'IRR',
            'subtotal' => 500000,
            'total' => 500000,
        ]);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis.sales_total', 500000)
            ->assertJsonPath('kpis.paid_orders', 1)
            ->assertJsonPath('kpis.low_stock_products', 1)
            ->assertJsonPath('recent_invoices.0.number', 'INV-DASHBOARD-001');
    }

    public function test_admin_can_manage_coupons(): void
    {
        $this->actingAsAdminWith('coupon.all', 'coupon.store', 'coupon.update', 'coupon.delete');

        $created = $this->postJson('/api/admin/coupons', [
            'code' => 'bargh10',
            'title' => 'تخفیف تجهیزات برق',
            'discount_type' => Coupon::TYPE_PERCENT,
            'discount_value' => 10,
            'max_discount' => 500000,
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'BARGH10')
            ->json();

        $this->getJson('/api/admin/coupons?search=BARGH')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'تخفیف تجهیزات برق');

        $this->patchJson('/api/admin/coupons/'.$created['id'], [
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('status', 'inactive');

        $this->deleteJson('/api/admin/coupons/'.$created['id'])->assertOk();
        $this->assertSoftDeleted('coupons', ['id' => $created['id']]);
    }

    public function test_admin_can_moderate_comments(): void
    {
        $this->actingAsAdminWith('comment.all', 'comment.answer', 'comment.release', 'comment.reject');
        $customer = User::factory()->create(['phone' => '09124445566']);
        $product = Product::query()->create([
            'creator_id' => $customer->id,
            'name' => 'پریز برق',
            'slug' => 'electric-socket',
            'sku' => 'SOCKET-001',
            'stock' => 10,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);
        $comment = Comment::query()->create([
            'user_id' => $customer->id,
            'model_type' => Product::class,
            'model_id' => $product->id,
            'comment' => 'کیفیت محصول مناسب است.',
            'rating' => 5,
            'status' => 'pending',
        ]);

        $this->getJson('/api/admin/comments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment->id);

        $this->postJson("/api/admin/comments/{$comment->id}/answer", [
            'answer' => 'از ثبت نظر شما متشکریم.',
        ])->assertOk()->assertJsonPath('status', 'answered');

        $this->postJson("/api/admin/comments/{$comment->id}/release")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    public function test_admin_can_list_update_and_answer_tickets(): void
    {
        $this->actingAsAdminWith('ticket.all', 'ticket.update', 'ticket.sendMessage');
        $customer = User::factory()->create(['phone' => '09124445566']);
        $ticket = Ticket::query()->create([
            'user_id' => $customer->id,
            'subject' => 'پیگیری سفارش',
            'description' => 'سفارش چه زمانی ارسال می‌شود؟',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->getJson('/api/admin/tickets?search=4445566')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticket->id);

        $this->patchJson("/api/admin/tickets/{$ticket->id}", [
            'priority' => 'high',
        ])->assertOk()->assertJsonPath('priority', 'high');

        $this->postJson("/api/admin/tickets/{$ticket->id}/messages", [
            'message' => 'سفارش شما در حال آماده‌سازی است.',
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'answered',
        ]);
    }

    public function test_admin_can_update_non_secret_settings(): void
    {
        $this->actingAsAdminWith('setting.all', 'setting.update');

        $this->putJson('/api/admin/settings', [
            'group' => 'shipping',
            'settings' => [
                'free_shipping_threshold' => 5000000,
                'default_shipping_fee' => 250000,
                'enabled' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('settings.shipping.free_shipping_threshold', 5000000)
            ->assertJsonPath('settings.shipping.enabled', true)
            ->assertJsonPath('environment.secrets_managed_by_env', true);

        $this->assertDatabaseHas('settings', [
            'group' => 'shipping',
            'key' => 'default_shipping_fee',
        ]);
    }

    public function test_shipping_settings_are_used_by_server_side_pricing(): void
    {
        Setting::query()->create([
            'group' => 'shipping',
            'key' => 'enabled',
            'value' => true,
            'type' => 'boolean',
        ]);
        Setting::query()->create([
            'group' => 'shipping',
            'key' => 'default_shipping_fee',
            'value' => 250000,
            'type' => 'number',
        ]);
        Setting::query()->create([
            'group' => 'shipping',
            'key' => 'free_shipping_threshold',
            'value' => 5000000,
            'type' => 'number',
        ]);

        $paidShipping = CheckoutPricingService::calculate(1000000);
        $freeShipping = CheckoutPricingService::calculate(5000000);

        $this->assertSame(250000.0, $paidShipping['shipping']);
        $this->assertSame(1250000.0, $paidShipping['total']);
        $this->assertSame(0.0, $freeShipping['shipping']);
    }

    public function test_invoice_status_history_is_appended(): void
    {
        $admin = $this->actingAsAdminWith('invoice.updateStatus');
        $customer = User::factory()->create();
        $invoice = Invoice::query()->create([
            'user_id' => $customer->id,
            'number' => 'INV-HISTORY-001',
            'status' => InvoiceStatusService::PENDING,
            'currency' => 'IRR',
            'subtotal' => 100000,
            'total' => 100000,
        ]);

        $this->patchJson("/api/admin/invoices/{$invoice->id}/status", [
            'status' => InvoiceStatusService::PAYMENT_PENDING,
            'note' => 'ارسال به درگاه',
        ])
            ->assertOk()
            ->assertJsonPath('meta.status_history.0.updated_by', $admin->id)
            ->assertJsonPath('meta.status_history.0.note', 'ارسال به درگاه');
    }

    private function actingAsAdminWith(string ...$slugs): User
    {
        $user = User::factory()->create(['accessibility' => true]);

        $permissionIds = collect($slugs)->map(fn (string $slug) => Permission::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'guard_name' => 'web',
        ])->id)->all();

        $user->permissions()->sync($permissionIds);
        Sanctum::actingAs($user);

        return $user;
    }
}
