<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\State;
use App\Models\User;
use App\Support\Payments\PaymentGatewayService;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCommerceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_coupon_preview_returns_discount_summary(): void
    {
        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Coupon Preview Product',
            'slug' => 'coupon-preview-product',
            'sku' => 'CPN-PRV-001',
            'stock' => 12,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        Coupon::query()->create([
            'code' => 'WELCOME10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_subtotal' => 50000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $this->postJson('/api/cart/coupon', [
            'coupon_code' => 'welcome10',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('coupon.code', 'WELCOME10')
            ->assertJsonPath('summary.subtotal', 200000)
            ->assertJsonPath('summary.discount', 20000)
            ->assertJsonPath('summary.total', 180000)
            ->assertJsonPath('summary.currency', 'IRR');
    }

    public function test_authenticated_user_can_manage_addresses_and_checkout(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000001',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'QA Product',
            'slug' => 'qa-product',
            'sku' => 'QA-PROD-001',
            'stock' => 10,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => '09120000001',
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);

        $addressResponse
            ->assertCreated()
            ->assertJsonPath('city_id', $city->id)
            ->assertJsonPath('is_default', true);

        $addressId = (int) $addressResponse->json('id');

        $this->postJson('/api/cart/check', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.available', true);

        $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('address_id', $addressId)
            ->assertJsonPath('currency', 'IRR');

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'address_id' => $addressId,
            'currency' => 'IRR',
        ]);

        $this->assertDatabaseHas('items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'name' => 'QA Product',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
            'sold_count' => 2,
        ]);
    }

    public function test_authenticated_user_can_add_and_remove_favorites(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000002',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Favorite Product',
            'slug' => 'favorite-product',
            'sku' => 'FAV-PROD-001',
            'stock' => 5,
            'price' => 50000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $this->postJson('/api/user/favorites', [
            'product_id' => $product->id,
        ])
            ->assertCreated()
            ->assertJsonPath('model.id', $product->id)
            ->assertJsonPath('model.title', 'Favorite Product');

        $this->postJson('/api/user/favorites', [
            'product_id' => $product->id,
        ])->assertCreated();

        $this->assertDatabaseCount('likes', 1);

        $this->getJson('/api/user/favorites')
            ->assertOk()
            ->assertJsonPath('data.0.model.id', $product->id);

        $this->deleteJson("/api/user/favorites/{$product->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Favorite removed successfully.');

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_authenticated_user_can_submit_product_review_comment(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000004',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Commentable Product',
            'slug' => 'commentable-product',
            'sku' => 'CMT-PROD-001',
            'stock' => 4,
            'price' => 75000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $this->postJson('/api/user/comments', [
            'model_type' => 'product',
            'model_id' => $product->id,
            'comment' => 'Great product and good quality.',
            'rating' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('comment.status', 'pending')
            ->assertJsonPath('comment.rating', 5);

        $this->assertDatabaseHas('comments', [
            'user_id' => $user->id,
            'model_id' => $product->id,
            'model_type' => Product::class,
            'status' => 'pending',
            'rating' => 5,
        ]);
    }

    public function test_checkout_applies_coupon_and_records_usage(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000005',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => '09120000005',
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);
        $addressResponse->assertCreated();
        $addressId = (int) $addressResponse->json('id');

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Coupon Checkout Product',
            'slug' => 'coupon-checkout-product',
            'sku' => 'CPN-CHK-001',
            'stock' => 10,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $coupon = Coupon::query()->create([
            'code' => 'SAVE50K',
            'discount_type' => 'fixed',
            'discount_value' => 50000,
            'min_subtotal' => 100000,
            'currency' => 'IRR',
            'max_uses' => 10,
            'max_uses_per_user' => 1,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'coupon_code' => 'save50k',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('coupon_id', $coupon->id)
            ->assertJsonPath('discount', '50000.00')
            ->assertJsonPath('total', '150000.00')
            ->assertJsonPath('meta.coupon.code', 'SAVE50K');

        $invoiceId = (int) $response->json('id');

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'invoice_id' => $invoiceId,
        ]);

        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'used_count' => 1,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
            'sold_count' => 2,
        ]);
    }

    public function test_checkout_rejects_client_supplied_shipping_and_tax_fields(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000006',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => '09120000006',
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);
        $addressResponse->assertCreated();
        $addressId = (int) $addressResponse->json('id');

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Shipping Tax Product',
            'slug' => 'shipping-tax-product',
            'sku' => 'SHP-TAX-001',
            'stock' => 5,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'shipping' => 7000,
            'tax' => 1000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping', 'tax']);
    }

    public function test_checkout_applies_server_side_shipping_and_tax_policy(): void
    {
        Config::set('checkout.shipping.flat_fee', 7000);
        Config::set('checkout.shipping.free_threshold', null);
        Config::set('checkout.tax.enabled', true);
        Config::set('checkout.tax.rate_percent', 10);

        $user = User::factory()->create([
            'phone' => '09120000010',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => '09120000010',
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);
        $addressResponse->assertCreated();
        $addressId = (int) $addressResponse->json('id');

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Server Pricing Product',
            'slug' => 'server-pricing-product',
            'sku' => 'SRV-PRC-001',
            'stock' => 5,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', '100000.00')
            ->assertJsonPath('discount', '0.00')
            ->assertJsonPath('tax', '10700.00')
            ->assertJsonPath('total', '117700.00')
            ->assertJsonPath('meta.shipping', 7000)
            ->assertJsonPath('meta.tax', 10700);
    }

    public function test_user_can_initiate_payment_and_reuse_existing_pending_payment(): void
    {
        $context = $this->createCheckoutInvoiceContext('09120000011');
        $invoiceId = $context['invoice_id'];

        $firstInitiate = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ]);

        $firstInitiate
            ->assertOk()
            ->assertJsonPath('invoice.id', $invoiceId)
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAYMENT_PENDING)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('gateway.provider', 'mock_gateway');

        $paymentId = (int) $firstInitiate->json('payment.id');
        $authority = (string) $firstInitiate->json('gateway.authority');
        $callbackToken = (string) $firstInitiate->json('gateway.callback_token');
        $redirectUrl = (string) $firstInitiate->json('gateway.redirect_url');

        $this->assertNotSame('', $authority);
        $this->assertNotSame('', $callbackToken);
        $this->assertStringContainsString('/api/payments/callback', $redirectUrl);

        $secondInitiate = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ]);

        $secondInitiate
            ->assertOk()
            ->assertJsonPath('message', 'Existing pending payment reused.')
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('payment.status', 'pending');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'status' => InvoiceStatusService::PAYMENT_PENDING,
        ]);
    }

    public function test_user_can_verify_payment_success_and_invoice_becomes_paid(): void
    {
        $context = $this->createCheckoutInvoiceContext('09120000012');
        $invoiceId = $context['invoice_id'];

        $initiateResponse = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ])->assertOk();

        $paymentId = (int) $initiateResponse->json('payment.id');

        $this->postJson("/api/user/payments/{$paymentId}/verify", [
            'status' => 'success',
            'reference' => 'REF-PAID-001',
        ])
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('invoice.id', $invoiceId)
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAID);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'paid',
            'reference' => 'REF-PAID-001',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'status' => InvoiceStatusService::PAID,
        ]);
    }

    public function test_user_cannot_verify_another_users_payment(): void
    {
        $context = $this->createCheckoutInvoiceContext('09120000013');
        $invoiceId = $context['invoice_id'];

        $initiateResponse = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ])->assertOk();

        $paymentId = (int) $initiateResponse->json('payment.id');

        $otherUser = User::factory()->create([
            'phone' => '09120000014',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson("/api/user/payments/{$paymentId}/verify", [
            'status' => 'success',
        ])->assertNotFound();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'pending',
        ]);
    }

    public function test_payment_callback_validates_token_and_can_mark_payment_as_failed(): void
    {
        $context = $this->createCheckoutInvoiceContext('09120000015');
        $invoiceId = $context['invoice_id'];

        $initiateResponse = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ])->assertOk();

        $paymentId = (int) $initiateResponse->json('payment.id');
        $authority = (string) $initiateResponse->json('gateway.authority');
        $callbackToken = (string) $initiateResponse->json('gateway.callback_token');

        $this->postJson('/api/payments/callback', [
            'payment_id' => $paymentId,
            'status' => 'failed',
            'authority' => $authority,
            'token' => 'invalid-token',
        ])->assertStatus(422);

        $this->postJson('/api/payments/callback', [
            'payment_id' => $paymentId,
            'status' => 'failed',
            'authority' => $authority,
            'token' => $callbackToken,
            'reason' => 'gateway_timeout',
        ])
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('payment.status', 'failed')
            ->assertJsonPath('invoice.id', $invoiceId)
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAYMENT_FAILED);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'status' => InvoiceStatusService::PAYMENT_FAILED,
        ]);
    }

    public function test_failed_payment_releases_stock_and_coupon_usage(): void
    {
        $context = $this->createCheckoutInvoiceContextWithCoupon('09120000150');
        $invoiceId = $context['invoice_id'];
        $productId = $context['product_id'];
        $couponId = $context['coupon_id'];

        $initiateResponse = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ])->assertOk();

        $paymentId = (int) $initiateResponse->json('payment.id');
        $authority = (string) $initiateResponse->json('gateway.authority');
        $callbackToken = (string) $initiateResponse->json('gateway.callback_token');

        $this->postJson('/api/payments/callback', [
            'payment_id' => $paymentId,
            'status' => 'failed',
            'authority' => $authority,
            'token' => $callbackToken,
            'reason' => 'gateway_timeout',
        ])
            ->assertOk()
            ->assertJsonPath('payment.status', 'failed')
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAYMENT_FAILED);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock' => 10,
            'sold_count' => 0,
        ]);

        $this->assertDatabaseMissing('coupon_usages', [
            'invoice_id' => $invoiceId,
            'coupon_id' => $couponId,
        ]);

        $this->assertDatabaseHas('coupons', [
            'id' => $couponId,
            'used_count' => 0,
        ]);

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $allocationMeta = (array) data_get((array) $invoice->meta, 'allocation', []);
        $this->assertNotEmpty($allocationMeta['released_at'] ?? null);
    }

    public function test_retry_payment_after_failure_re_reserves_stock_and_coupon_usage(): void
    {
        $context = $this->createCheckoutInvoiceContextWithCoupon('09120000151');
        $invoiceId = $context['invoice_id'];
        $productId = $context['product_id'];
        $couponId = $context['coupon_id'];

        $firstInitiate = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ])->assertOk();

        $firstPaymentId = (int) $firstInitiate->json('payment.id');
        $firstAuthority = (string) $firstInitiate->json('gateway.authority');
        $firstToken = (string) $firstInitiate->json('gateway.callback_token');

        $this->postJson('/api/payments/callback', [
            'payment_id' => $firstPaymentId,
            'status' => 'failed',
            'authority' => $firstAuthority,
            'token' => $firstToken,
            'reason' => 'temporary_gateway_error',
        ])->assertOk();

        $retryInitiate = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
        ]);

        $retryInitiate
            ->assertOk()
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAYMENT_PENDING)
            ->assertJsonPath('payment.status', 'pending');

        $retryPaymentId = (int) $retryInitiate->json('payment.id');
        $this->assertNotSame($firstPaymentId, $retryPaymentId);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock' => 9,
            'sold_count' => 1,
        ]);

        $this->assertDatabaseHas('coupon_usages', [
            'invoice_id' => $invoiceId,
            'coupon_id' => $couponId,
        ]);

        $this->assertDatabaseHas('coupons', [
            'id' => $couponId,
            'used_count' => 1,
        ]);

        $this->postJson("/api/user/payments/{$retryPaymentId}/verify", [
            'status' => 'success',
            'reference' => 'REF-RETRY-PAID-001',
        ])
            ->assertOk()
            ->assertJsonPath('payment.id', $retryPaymentId)
            ->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAID);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'status' => InvoiceStatusService::PAID,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock' => 9,
            'sold_count' => 1,
        ]);
    }

    public function test_user_can_initiate_payment_with_zarinpal_when_configured(): void
    {
        $context = $this->createCheckoutInvoiceContext('09120000152');
        $invoiceId = $context['invoice_id'];

        Config::set('payment.providers.zarinpal.enabled', true);
        Config::set('payment.providers.zarinpal.merchant_id', 'test-merchant-id');
        Config::set('payment.providers.zarinpal.sandbox', true);
        Config::set('payment.default_provider', PaymentGatewayService::PROVIDER_ZARINPAL);

        Http::fake([
            'https://sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'authority' => 'A000000000000000000000000000000000',
                ],
                'errors' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
            'method' => PaymentGatewayService::PROVIDER_ZARINPAL,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('invoice.id', $invoiceId)
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAYMENT_PENDING)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.method', PaymentGatewayService::PROVIDER_ZARINPAL)
            ->assertJsonPath('gateway.provider', PaymentGatewayService::PROVIDER_ZARINPAL)
            ->assertJsonPath('gateway.authority', 'A000000000000000000000000000000000')
            ->assertJsonPath(
                'gateway.redirect_url',
                'https://sandbox.zarinpal.com/pg/StartPay/A000000000000000000000000000000000'
            );

        Http::assertSentCount(1);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoiceId,
            'status' => 'pending',
            'method' => PaymentGatewayService::PROVIDER_ZARINPAL,
            'reference' => 'A000000000000000000000000000000000',
        ]);
    }

    public function test_zarinpal_callback_can_mark_payment_as_paid_after_gateway_verify(): void
    {
        $context = $this->createCheckoutInvoiceContext('09120000153');
        $invoiceId = $context['invoice_id'];

        Config::set('payment.providers.zarinpal.enabled', true);
        Config::set('payment.providers.zarinpal.merchant_id', 'test-merchant-id');
        Config::set('payment.providers.zarinpal.sandbox', true);
        Config::set('payment.default_provider', PaymentGatewayService::PROVIDER_ZARINPAL);

        Http::fake([
            'https://sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'authority' => 'B000000000000000000000000000000000',
                ],
                'errors' => [],
            ], 200),
            'https://sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Verified',
                    'ref_id' => '7788990011',
                ],
                'errors' => [],
            ], 200),
        ]);

        $initiate = $this->postJson('/api/user/payments/initiate', [
            'invoice_id' => $invoiceId,
            'method' => PaymentGatewayService::PROVIDER_ZARINPAL,
        ])->assertOk();

        $paymentId = (int) $initiate->json('payment.id');
        $callbackToken = (string) $initiate->json('gateway.callback_token');

        $this->getJson('/api/payments/callback?'.http_build_query([
            'payment_id' => $paymentId,
            'Authority' => 'B000000000000000000000000000000000',
            'Status' => 'OK',
            'token' => $callbackToken,
        ]))
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('payment.reference', '7788990011')
            ->assertJsonPath('invoice.id', $invoiceId)
            ->assertJsonPath('invoice.status', InvoiceStatusService::PAID);

        Http::assertSentCount(2);
    }

    public function test_authenticated_user_can_list_and_view_only_own_invoices(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000007',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => '09120000007',
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);
        $addressResponse->assertCreated();
        $addressId = (int) $addressResponse->json('id');

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Invoice Product',
            'slug' => 'invoice-product',
            'sku' => 'INV-PROD-001',
            'stock' => 10,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $checkoutResponse = $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ])->assertOk();

        $ownInvoiceId = (int) $checkoutResponse->json('id');

        $otherUser = User::factory()->create();
        $otherAddress = $otherUser->addresses()->create([
            'city_id' => $city->id,
            'street_line1' => 'Other St',
        ]);

        $otherInvoice = Invoice::query()->create([
            'user_id' => $otherUser->id,
            'address_id' => $otherAddress->id,
            'number' => 'INV-OTHER-0001',
            'status' => 'pending',
            'currency' => 'IRR',
            'subtotal' => 100000,
            'tax' => 0,
            'discount' => 0,
            'total' => 100000,
            'issued_at' => now(),
        ]);

        $this->getJson('/api/user/invoices')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownInvoiceId])
            ->assertJsonMissing(['id' => $otherInvoice->id]);

        $this->getJson("/api/user/invoices/{$ownInvoiceId}")
            ->assertOk()
            ->assertJsonPath('id', $ownInvoiceId);

        $this->getJson("/api/user/invoices/{$otherInvoice->id}")
            ->assertNotFound();
    }

    /**
     * @return array{user:User,invoice_id:int}
     */
    protected function createCheckoutInvoiceContext(string $phone): array
    {
        $user = User::factory()->create([
            'phone' => $phone,
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => $phone,
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);
        $addressResponse->assertCreated();
        $addressId = (int) $addressResponse->json('id');

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => "Payment Product {$phone}",
            'slug' => "payment-product-{$phone}",
            'sku' => "PAY-{$phone}",
            'stock' => 10,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $checkoutResponse = $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);
        $checkoutResponse->assertOk();

        return [
            'user' => $user,
            'invoice_id' => (int) $checkoutResponse->json('id'),
        ];
    }

    /**
     * @return array{user:User,invoice_id:int,product_id:int,coupon_id:int}
     */
    protected function createCheckoutInvoiceContextWithCoupon(string $phone): array
    {
        $user = User::factory()->create([
            'phone' => $phone,
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
        ]);

        $addressResponse = $this->postJson('/api/user/addresses', [
            'city_id' => $city->id,
            'label' => 'Home',
            'recipient_name' => 'Ehsan',
            'phone' => $phone,
            'street_line1' => 'Valiasr St',
            'is_default' => true,
        ]);
        $addressResponse->assertCreated();
        $addressId = (int) $addressResponse->json('id');

        $creator = User::factory()->create();
        $product = Product::query()->create([
            'creator_id' => $creator->id,
            'name' => "Coupon Payment Product {$phone}",
            'slug' => "coupon-payment-product-{$phone}",
            'sku' => "CPAY-{$phone}",
            'stock' => 10,
            'price' => 100000,
            'currency' => 'IRR',
            'status' => 'active',
        ]);

        $coupon = Coupon::query()->create([
            'code' => "CPN{$phone}",
            'discount_type' => 'fixed',
            'discount_value' => 10000,
            'min_subtotal' => 50000,
            'currency' => 'IRR',
            'max_uses' => 50,
            'max_uses_per_user' => 5,
            'status' => 'active',
        ]);

        $checkoutResponse = $this->postJson('/api/user/checkout', [
            'address_id' => $addressId,
            'coupon_code' => $coupon->code,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);
        $checkoutResponse->assertOk();

        return [
            'user' => $user,
            'invoice_id' => (int) $checkoutResponse->json('id'),
            'product_id' => (int) $product->id,
            'coupon_id' => (int) $coupon->id,
        ];
    }
}
