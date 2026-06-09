<?php

namespace Tests\Feature\Api;

use App\Jobs\ImportAccountingProductsJob;
use App\Jobs\PushInvoiceToAccountingJob;
use App\Models\AccountingInvoiceMapping;
use App\Models\AccountingSyncLog;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Accounting\AccountingConfiguration;
use App\Support\Accounting\AccountingOutboxService;
use App\Support\Accounting\Contracts\AccountingProviderInterface;
use App\Support\Accounting\InvoicePayloadBuilder;
use App\Support\Accounting\ProductImportService;
use App\Support\Accounting\Providers\GenericRestAccountingProvider;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AccountingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_accounting_does_not_queue_invoice_sync_after_payment(): void
    {
        Queue::fake();
        config()->set('accounting.enabled', false);

        $context = $this->paidInvoiceContext();

        app(AccountingOutboxService::class)
            ->dispatchPaidInvoiceSafely($context['invoice']);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('accounting_sync_logs', 0);
    }

    public function test_successful_payment_queues_invoice_sync_when_enabled(): void
    {
        Queue::fake();
        $this->enableAccounting();
        $user = User::factory()->create(['accessibility' => true]);
        Sanctum::actingAs($user);
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'number' => 'INV-ACCOUNTING-PAYMENT-001',
            'status' => InvoiceStatusService::PAYMENT_PENDING,
            'currency' => 'IRR',
            'subtotal' => 500000,
            'total' => 500000,
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'amount' => 500000,
            'currency' => 'IRR',
            'method' => 'mock_gateway',
            'status' => 'pending',
        ]);

        $this->postJson("/api/user/payments/{$payment->id}/verify", [
            'status' => 'success',
            'reference' => 'REF-AUTO-SYNC-001',
        ])->assertOk()->assertJsonPath('invoice.status', InvoiceStatusService::PAID);

        Queue::assertPushed(PushInvoiceToAccountingJob::class);
        $this->assertDatabaseHas('accounting_sync_logs', [
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $invoice->id,
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);
    }

    public function test_paid_invoice_sync_is_successful_and_idempotent(): void
    {
        $this->enableAccounting();
        $provider = new FakeAccountingProvider;
        $this->app->instance(AccountingProviderInterface::class, $provider);
        $context = $this->paidInvoiceContext();

        $log = AccountingSyncLog::query()->create([
            'provider' => 'generic_rest',
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $context['invoice']->id,
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);

        $job = new PushInvoiceToAccountingJob($log->id, $context['invoice']->id);
        $job->handle(
            app(AccountingConfiguration::class),
            $provider,
            app(InvoicePayloadBuilder::class)
        );

        $mapping = AccountingInvoiceMapping::query()->where('invoice_id', $context['invoice']->id)->firstOrFail();
        $this->assertSame('succeeded', $mapping->status);
        $this->assertSame('ACC-INV-1001', $mapping->external_id);
        $this->assertSame(1, $provider->invoicePushCount);
        $this->assertSame('REF-ACCOUNTING-001', $provider->lastInvoicePayload['payment']['reference']);

        $secondLog = AccountingSyncLog::query()->create([
            'provider' => 'generic_rest',
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $context['invoice']->id,
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);
        (new PushInvoiceToAccountingJob($secondLog->id, $context['invoice']->id))->handle(
            app(AccountingConfiguration::class),
            $provider,
            app(InvoicePayloadBuilder::class)
        );

        $this->assertSame(1, $provider->invoicePushCount);
        $this->assertSame(
            AccountingSyncLog::STATUS_SUCCEEDED,
            $secondLog->fresh()->status
        );
        $this->assertTrue((bool) data_get($secondLog->fresh()->response, 'idempotent'));
    }

    public function test_failed_sync_can_be_retried_from_admin_api(): void
    {
        Queue::fake();
        $this->enableAccounting();
        $admin = $this->actingAsAdminWith('accounting.retry');
        $context = $this->paidInvoiceContext();
        $failedLog = AccountingSyncLog::query()->create([
            'provider' => 'generic_rest',
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $context['invoice']->id,
            'status' => AccountingSyncLog::STATUS_FAILED,
            'error' => 'Accounting service timeout.',
        ]);

        $response = $this->postJson("/api/admin/accounting/logs/{$failedLog->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('log.retry_of_id', $failedLog->id)
            ->assertJsonPath('log.triggered_by', $admin->id);

        Queue::assertPushed(PushInvoiceToAccountingJob::class, function (PushInvoiceToAccountingJob $job) use ($response): bool {
            return $job->syncLogId === $response->json('log.id');
        });
    }

    public function test_product_import_creates_mapping_and_updates_existing_product(): void
    {
        $this->enableAccounting();
        $admin = User::factory()->create();
        config()->set('accounting.product_creator_id', $admin->id);
        $provider = new FakeAccountingProvider;
        $provider->products = [[
            'id' => 'PRD-100',
            'name' => 'کلید محافظ جان',
            'sku' => 'RCCB-100',
            'stock' => 12,
            'price' => 3500000,
            'currency' => 'IRR',
            'status' => 'active',
        ]];
        $this->app->instance(AccountingProviderInterface::class, $provider);
        $log = AccountingSyncLog::query()->create([
            'provider' => 'generic_rest',
            'operation' => 'product_import',
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);

        (new ImportAccountingProductsJob($log->id))->handle(
            app(AccountingConfiguration::class),
            $provider,
            app(ProductImportService::class)
        );

        $this->assertDatabaseHas('products', [
            'sku' => 'RCCB-100',
            'name' => 'کلید محافظ جان',
            'stock' => 12,
        ]);
        $this->assertDatabaseHas('accounting_product_mappings', [
            'provider' => 'generic_rest',
            'external_id' => 'PRD-100',
        ]);
        $product = Product::query()->where('sku', 'RCCB-100')->firstOrFail();
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'stock_delta' => 12,
            'reason' => 'accounting_sync',
        ]);
        $this->assertDatabaseHas('price_histories', [
            'product_id' => $product->id,
            'new_price' => 3500000,
            'reason' => 'accounting_sync',
        ]);
        $this->assertSame(AccountingSyncLog::STATUS_SUCCEEDED, $log->fresh()->status);
    }

    public function test_product_import_cannot_reduce_stock_below_active_reservations(): void
    {
        $this->enableAccounting();
        $admin = User::factory()->create();
        config()->set('accounting.product_creator_id', $admin->id);
        $product = Product::query()->create([
            'creator_id' => $admin->id,
            'name' => 'کنتاکتور تست',
            'slug' => 'accounting-reserved-contactor',
            'sku' => 'CNT-RESERVED',
            'stock' => 10,
            'stock_reserved' => 6,
            'price' => 2000000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);
        $provider = new FakeAccountingProvider;
        $provider->products = [[
            'id' => 'PRD-RESERVED',
            'name' => 'کنتاکتور تست',
            'sku' => 'CNT-RESERVED',
            'stock' => 4,
            'price' => 2100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stock cannot be lower than active reserved stock.');

        try {
            app(ProductImportService::class)->import($provider);
        } finally {
            $this->assertSame(10, (int) $product->fresh()->stock);
            $this->assertSame(2000000.0, (float) $product->fresh()->price);
            $this->assertDatabaseMissing('accounting_product_mappings', [
                'external_id' => 'PRD-RESERVED',
            ]);
        }
    }

    public function test_paid_invoice_is_pushed_after_order_advances_beyond_paid_status(): void
    {
        $this->enableAccounting();
        $provider = new FakeAccountingProvider;
        $context = $this->paidInvoiceContext();
        $context['invoice']->update(['status' => InvoiceStatusService::PROCESSING]);
        $log = AccountingSyncLog::query()->create([
            'provider' => 'generic_rest',
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $context['invoice']->id,
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);

        (new PushInvoiceToAccountingJob($log->id, $context['invoice']->id))->handle(
            app(AccountingConfiguration::class),
            $provider,
            app(InvoicePayloadBuilder::class)
        );

        $this->assertSame(1, $provider->invoicePushCount);
        $this->assertSame(AccountingSyncLog::STATUS_SUCCEEDED, $log->fresh()->status);
    }

    public function test_provider_failure_is_recorded_without_changing_paid_invoice(): void
    {
        $this->enableAccounting();
        $provider = new FakeAccountingProvider;
        $provider->failInvoicePush = true;
        $context = $this->paidInvoiceContext();
        $log = AccountingSyncLog::query()->create([
            'provider' => 'generic_rest',
            'operation' => 'invoice_push',
            'syncable_type' => Invoice::class,
            'syncable_id' => $context['invoice']->id,
            'status' => AccountingSyncLog::STATUS_QUEUED,
        ]);

        try {
            (new PushInvoiceToAccountingJob($log->id, $context['invoice']->id))->handle(
                app(AccountingConfiguration::class),
                $provider,
                app(InvoicePayloadBuilder::class)
            );
            $this->fail('Expected accounting provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Accounting service unavailable.', $exception->getMessage());
        }

        $this->assertSame(InvoiceStatusService::PAID, $context['invoice']->fresh()->status);
        $this->assertSame(AccountingSyncLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertSame('failed', AccountingInvoiceMapping::query()->where('invoice_id', $context['invoice']->id)->value('status'));
    }

    public function test_generic_rest_provider_uses_configured_paths_and_idempotency_header(): void
    {
        config()->set('accounting.base_url', 'https://accounting.example.test/api');
        config()->set('accounting.token', 'test-token');
        Http::fake([
            'https://accounting.example.test/api/health' => Http::response(['ok' => true]),
            'https://accounting.example.test/api/products*' => Http::response([
                'data' => [['id' => 'P-1', 'name' => 'فیوز مینیاتوری']],
            ]),
            'https://accounting.example.test/api/invoices' => Http::response([
                'data' => ['id' => 'INV-REMOTE-1'],
            ], 201),
        ]);

        $provider = app(GenericRestAccountingProvider::class);

        $this->assertTrue((bool) data_get($provider->healthCheck(), 'ok'));
        $this->assertSame('P-1', data_get($provider->fetchProducts()['items'], '0.id'));
        $this->assertSame(
            'INV-REMOTE-1',
            data_get($provider->pushInvoice(['invoice_number' => 'INV-1'], 'stable-key'), 'external_id')
        );

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://accounting.example.test/api/invoices'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Idempotency-Key', 'stable-key'));
    }

    public function test_accounting_credentials_cannot_be_saved_in_settings(): void
    {
        $this->actingAsAdminWith('setting.update');

        $this->putJson('/api/admin/settings', [
            'group' => 'accounting',
            'settings' => [
                'enabled' => true,
                'token' => 'must-not-be-stored',
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('settings', [
            'group' => 'accounting',
            'key' => 'token',
        ]);
    }

    private function enableAccounting(): void
    {
        config()->set('accounting.enabled', true);
        config()->set('accounting.base_url', 'https://accounting.example.test');
        Setting::query()->create([
            'group' => 'accounting',
            'key' => 'enabled',
            'value' => true,
            'type' => 'boolean',
        ]);
    }

    /**
     * @return array{invoice:Invoice,user:User}
     */
    private function paidInvoiceContext(): array
    {
        $user = User::factory()->create();
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'number' => 'INV-ACCOUNTING-001',
            'status' => InvoiceStatusService::PAID,
            'currency' => 'IRR',
            'subtotal' => 500000,
            'discount' => 0,
            'tax' => 0,
            'total' => 500000,
            'issued_at' => now(),
        ]);
        Item::query()->create([
            'invoice_id' => $invoice->id,
            'name' => 'کابل افشان',
            'quantity' => 2,
            'unit_price' => 250000,
            'total' => 500000,
            'meta' => ['sku' => 'WIRE-100'],
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'amount' => 500000,
            'currency' => 'IRR',
            'method' => 'shetabit',
            'status' => 'paid',
            'reference' => 'REF-ACCOUNTING-001',
            'paid_at' => now(),
        ]);

        return ['invoice' => $invoice->fresh(), 'user' => $user];
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

class FakeAccountingProvider implements AccountingProviderInterface
{
    public array $products = [];

    public bool $failInvoicePush = false;

    public int $invoicePushCount = 0;

    public array $lastInvoicePayload = [];

    public function name(): string
    {
        return 'generic_rest';
    }

    public function healthCheck(): array
    {
        return ['ok' => true];
    }

    public function fetchProducts(?string $cursor = null, ?int $perPage = null): array
    {
        return ['items' => $this->products, 'next_cursor' => null, 'meta' => []];
    }

    public function pushInvoice(array $payload, string $idempotencyKey): array
    {
        if ($this->failInvoicePush) {
            throw new RuntimeException('Accounting service unavailable.');
        }

        $this->invoicePushCount++;
        $this->lastInvoicePayload = $payload;

        return [
            'ok' => true,
            'external_id' => 'ACC-INV-1001',
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
