<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Support\Accounting\AccountingOutboxService;
use App\Support\Cart\CartService;
use App\Support\Checkout\CheckoutPricingService;
use App\Support\Coupons\CouponService;
use App\Support\Inventory\InventoryService;
use App\Support\Invoices\InvoiceAllocationService;
use App\Support\Invoices\InvoiceStatusService;
use App\Support\Payments\PaymentGatewayService;
use App\Support\Settings\StoreSettingService;
use App\Support\Shipping\ShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PaymentController extends Controller
{
    public function __construct(
        protected InvoiceAllocationService $invoiceAllocationService,
        protected PaymentGatewayService $paymentGatewayService,
        protected AccountingOutboxService $accountingOutboxService,
        protected CartService $cartService,
        protected ShippingQuoteService $shippingQuoteService,
        protected InventoryService $inventoryService
    ) {
        $this->middleware('auth:sanctum')->except('callback');
    }

    #[OA\Post(
        path: '/api/user/checkout',
        operationId: 'checkout',
        summary: 'Create an invoice based on cart items',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['address_id'],
                properties: [
                    new OA\Property(property: 'address_id', type: 'integer', example: 3),
                    new OA\Property(property: 'coupon_code', type: 'string', nullable: true, example: 'WELCOME10'),
                    new OA\Property(property: 'shipping_method_id', type: 'integer', nullable: true, example: 1),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            required: ['product_id', 'quantity'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'integer', example: 1),
                                new OA\Property(property: 'quantity', type: 'integer', example: 2),
                            ]
                        )
                    ),
                ]
            )
        ),
        tags: ['Checkout'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice created',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceResponse')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'shipping' => ['prohibited'],
            'tax' => ['prohibited'],
            'shipping_method_id' => ['nullable', 'integer', 'exists:shipping_methods,id'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        /** @var Address|null $address */
        $address = $user->addresses()->where('id', $data['address_id'])->first();

        abort_if(! $address, 422, 'Selected address not found.');

        $cart = $this->cartService->resolve($request);
        $checkoutItems = collect($data['items'] ?? []);
        if ($checkoutItems->isEmpty()) {
            $checkoutItems = $this->cartService->checkoutItems($cart);
            abort_if($checkoutItems->isEmpty(), 422, 'Cart is empty.');
        }
        $productIds = $checkoutItems->pluck('product_id')->unique()->values()->all();

        $couponCode = isset($data['coupon_code']) ? trim((string) $data['coupon_code']) : null;

        /** @var Invoice $invoice */
        $invoice = DB::transaction(function () use ($user, $address, $productIds, $checkoutItems, $data, $couponCode, $cart) {
            $products = Product::query()
                ->with('variants')
                ->whereIn('id', $productIds)
                ->whereIn('status', ['active', 'special'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_if($products->count() !== count($productIds), 422, 'One or more products are unavailable.');

            $variantIds = $checkoutItems->pluck('product_variant_id')->filter()->unique()->all();
            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $itemsPayload = $checkoutItems->map(function (array $item) use ($products, $variants) {
                /** @var Product|null $product */
                $product = $products->get($item['product_id']);

                abort_if(! $product, 422, 'Product '.$item['product_id'].' not available.');

                $quantity = (int) $item['quantity'];
                $variant = ! empty($item['product_variant_id'])
                    ? $variants->get((int) $item['product_variant_id'])
                    : null;
                abort_if(
                    $product->variants->where('status', 'active')->isNotEmpty() && ! $variant,
                    422,
                    "Select a variant for {$product->name}."
                );
                $this->inventoryService->assertPurchasable($product, $variant, $quantity);
                $unitPrice = $variant ? (float) $variant->price : (float) $product->price;

                return [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $unitPrice * $quantity,
                ];
            });

            $currency = $products->pluck('currency')->unique()->count() === 1
                ? $products->first()->currency
                : null;

            abort_if($currency === null, 422, 'Products must share a common currency.');

            $subtotal = (float) $itemsPayload->sum('total');
            $coupon = null;
            $discount = 0.0;

            if ($couponCode) {
                $coupon = CouponService::resolveValidCoupon(
                    $couponCode,
                    (float) $subtotal,
                    $currency,
                    $user,
                    true
                );
                $discount = $coupon->calculateDiscount((float) $subtotal);
            }

            $total = round(max(0, (float) $subtotal - $discount), 2);
            $shippingOption = $this->shippingQuoteService->selected(
                $total,
                $address->loadMissing('city.state'),
                isset($data['shipping_method_id']) ? (int) $data['shipping_method_id'] : null
            );
            $pricing = CheckoutPricingService::calculate($total, (float) $shippingOption['cost']);
            $shipping = $pricing['shipping'];
            $tax = $pricing['tax'];
            $grandTotal = $pricing['total'];

            $meta = $coupon ? [
                'coupon' => [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'discount_type' => $coupon->discount_type,
                    'discount_value' => $coupon->discount_value,
                    'calculated_discount' => $discount,
                ],
            ] : [];

            $meta['shipping'] = $shipping;
            $meta['shipping_method'] = $shippingOption;
            $meta['tax'] = $tax;
            $meta['allocation'] = [
                'reserved_at' => now()->toAtomString(),
                'last_action' => 'reserved',
                'state' => 'reserved',
            ];

            $invoice = Invoice::query()->create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'coupon_id' => $coupon?->id,
                'shipping_method_id' => $shippingOption['id'],
                'number' => $this->generateInvoiceNumber(),
                'status' => InvoiceStatusService::PENDING,
                'currency' => $currency,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'shipping' => $shipping,
                'total' => $grandTotal,
                'issued_at' => now(),
                'meta' => $meta,
            ]);

            $itemsPayload->each(function (array $payload) use ($invoice): void {
                /** @var Product $product */
                $product = $payload['product'];
                /** @var ProductVariant|null $variant */
                $variant = $payload['variant'];
                $quantity = (int) $payload['quantity'];

                Item::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'name' => $variant ? "{$product->name} - {$variant->name}" : $product->name,
                    'description' => $product->description,
                    'quantity' => $quantity,
                    'unit_price' => $payload['unit_price'],
                    'total' => $payload['total'],
                    'meta' => [
                        'sku' => $variant?->sku ?? $product->sku,
                        'barcode' => $variant?->barcode ?? $product->barcode,
                        'variant' => $variant?->options,
                        'product_status' => $product->status,
                    ],
                ]);

                $this->inventoryService->reserve($product, $variant, $quantity, $invoice);
            });

            if ($coupon) {
                $coupon->increment('used_count');

                CouponUsage::query()->create([
                    'coupon_id' => $coupon->id,
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'discount_amount' => $discount,
                    'used_at' => now(),
                ]);
            }

            Shipment::query()->create([
                'invoice_id' => $invoice->id,
                'shipping_method_id' => $shippingOption['id'],
                'status' => 'preparing',
            ]);

            $cart->items()->delete();

            return $invoice->fresh()->load([
                'items',
                'address.city.state',
                'coupon',
                'shippingMethod',
                'shipment.shippingMethod',
            ]);
        });

        return response()->json($invoice);
    }

    public function initiate(Request $request): JsonResponse
    {
        abort_if(
            ! StoreSettingService::boolean('payment', 'online_payment_enabled', true),
            422,
            'Online payment is currently disabled.'
        );

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')],
            'method' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        $provider = $this->paymentGatewayService->resolveProvider(
            isset($data['method']) ? (string) $data['method'] : null
        );

        $result = DB::transaction(function () use ($data, $user, $provider) {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()
                ->where('id', $data['invoice_id'])
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            abort_if(! $invoice, 404, 'Invoice not found.');

            $status = (string) $invoice->status;
            $allowedStatuses = [
                InvoiceStatusService::PENDING,
                InvoiceStatusService::PAYMENT_FAILED,
                InvoiceStatusService::PAYMENT_PENDING,
            ];

            abort_if(
                ! in_array($status, $allowedStatuses, true),
                422,
                'Invoice cannot be paid in its current status.'
            );

            $reservedForRetry = false;
            if ($status === InvoiceStatusService::PAYMENT_FAILED) {
                $this->invoiceAllocationService->reserveForRetry($invoice);
                $reservedForRetry = true;
            }

            /** @var Payment|null $pendingPayment */
            $pendingPayment = Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if ($pendingPayment) {
                if ((string) $invoice->status !== InvoiceStatusService::PAYMENT_PENDING) {
                    $invoice->update([
                        'status' => InvoiceStatusService::PAYMENT_PENDING,
                    ]);
                }

                return [
                    'invoice' => $invoice,
                    'payment' => $pendingPayment,
                    'created' => false,
                    'previous_status' => $status,
                    'reserved_for_retry' => $reservedForRetry,
                ];
            }

            abort_if(
                ! InvoiceStatusService::canTransition($status, InvoiceStatusService::PAYMENT_PENDING),
                422,
                'Invoice status transition is not allowed.'
            );

            $callbackToken = Str::random(40);

            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'amount' => $invoice->total,
                'currency' => $invoice->currency,
                'method' => $provider,
                'status' => 'pending',
                'reference' => null,
                'meta' => [
                    'gateway' => $provider,
                    'callback_token' => $callbackToken,
                ],
            ]);

            $invoice->update([
                'status' => InvoiceStatusService::PAYMENT_PENDING,
            ]);

            return [
                'invoice' => $invoice,
                'payment' => $payment,
                'created' => true,
                'previous_status' => $status,
                'reserved_for_retry' => $reservedForRetry,
            ];
        });

        /** @var Invoice $invoice */
        $invoice = $result['invoice'];
        /** @var Payment $payment */
        $payment = $result['payment'];
        $previousStatus = (string) ($result['previous_status'] ?? InvoiceStatusService::PENDING);
        $reservedForRetry = (bool) ($result['reserved_for_retry'] ?? false);
        $gateway = $this->paymentGatewayService->buildGatewayPayload($payment);

        if ((bool) $result['created']) {
            try {
                $gateway = $this->paymentGatewayService->initiate($payment, $invoice);
            } catch (ValidationException $validationException) {
                DB::transaction(function () use ($payment, $invoice, $previousStatus, $reservedForRetry, $validationException): void {
                    /** @var Payment|null $lockedPayment */
                    $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
                    /** @var Invoice|null $lockedInvoice */
                    $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

                    if ($lockedPayment) {
                        $paymentMeta = (array) ($lockedPayment->meta ?? []);
                        $paymentMeta['initiation_error'] = 'gateway_init_failed';
                        $paymentMeta['initiation_error_message'] = collect($validationException->errors())
                            ->flatten()
                            ->filter()
                            ->first() ?: 'Payment gateway initiation failed.';

                        $lockedPayment->update([
                            'status' => 'failed',
                            'meta' => $paymentMeta,
                        ]);
                    }

                    if ($lockedInvoice) {
                        $lockedInvoice->update([
                            'status' => $previousStatus,
                        ]);

                        if ($reservedForRetry && $previousStatus === InvoiceStatusService::PAYMENT_FAILED) {
                            $this->invoiceAllocationService->releaseForFailedPayment(
                                $lockedInvoice,
                                'gateway_initiation_failed'
                            );
                        }
                    }
                });

                throw $validationException;
            }

            DB::transaction(function () use ($payment, $gateway): void {
                /** @var Payment|null $lockedPayment */
                $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
                if (! $lockedPayment) {
                    return;
                }

                $meta = (array) ($lockedPayment->meta ?? []);
                $gatewayMeta = (array) ($gateway['meta'] ?? []);
                $meta = array_merge($meta, $gatewayMeta);

                $authority = trim((string) ($gateway['authority'] ?? ''));
                $lockedPayment->update([
                    'reference' => $authority !== '' ? $authority : $lockedPayment->reference,
                    'meta' => $meta,
                ]);
            });

            $payment = $payment->fresh();
            $gateway = $this->paymentGatewayService->buildGatewayPayload($payment);
        }

        return response()->json([
            'message' => $result['created']
                ? 'Payment initiation created successfully.'
                : 'Existing pending payment reused.',
            'invoice' => $this->loadInvoicePayload($invoice),
            'payment' => $payment->fresh(),
            'gateway' => $gateway,
        ]);
    }

    public function verify(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->user_id !== $request->user()->id, 404, 'Payment not found.');

        $data = $request->validate([
            'status' => ['required', Rule::in(['success', 'failed'])],
            'authority' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->finalizePayment(
            paymentId: $payment->id,
            status: $data['status'],
            authority: isset($data['authority']) ? trim((string) $data['authority']) : null,
            reference: isset($data['reference']) ? trim((string) $data['reference']) : null,
            reason: isset($data['reason']) ? trim((string) $data['reason']) : null,
            callbackToken: null,
            requireCallbackToken: false
        );

        $this->queueAccountingSync($result);

        return response()->json([
            'message' => $result['already_processed']
                ? 'Payment has already been processed.'
                : 'Payment verification completed.',
            'payment' => $result['payment'],
            'invoice' => $result['invoice'],
        ]);
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'integer', Rule::exists('payments', 'id')],
            'status' => ['nullable', 'string', 'max:50'],
            'gateway_status' => ['nullable', 'string', 'max:50'],
            'Status' => ['nullable', 'string', 'max:50'],
            'authority' => ['nullable', 'string', 'max:100'],
            'Authority' => ['nullable', 'string', 'max:100'],
            'token' => ['required', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->whereKey((int) $data['payment_id'])
            ->first();
        abort_if(! $payment, 404, 'Payment not found.');

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->whereKey($payment->invoice_id)
            ->first();
        abort_if(! $invoice, 422, 'Invoice not found for payment.');

        $authority = trim((string) ($data['authority'] ?? $data['Authority'] ?? ''));
        $statusInput = trim((string) ($data['status'] ?? $data['gateway_status'] ?? $data['Status'] ?? ''));

        $outcome = $this->paymentGatewayService->resolveCallbackOutcome(
            $payment,
            $invoice,
            $authority !== '' ? $authority : null,
            $statusInput !== '' ? $statusInput : null
        );
        $gatewayMeta = (array) ($outcome['meta'] ?? []);

        $normalizedStatus = (string) ($outcome['status'] ?? 'failed');
        $normalizedReference = isset($data['reference']) && trim((string) $data['reference']) !== ''
            ? trim((string) $data['reference'])
            : (isset($outcome['reference']) ? trim((string) $outcome['reference']) : null);
        $normalizedReason = isset($data['reason']) && trim((string) $data['reason']) !== ''
            ? trim((string) $data['reason'])
            : (isset($outcome['reason']) ? trim((string) $outcome['reason']) : null);

        $result = $this->finalizePayment(
            paymentId: (int) $data['payment_id'],
            status: $normalizedStatus,
            authority: $authority !== '' ? $authority : null,
            reference: $normalizedReference !== null && $normalizedReference !== '' ? $normalizedReference : null,
            reason: $normalizedReason !== null && $normalizedReason !== '' ? $normalizedReason : null,
            callbackToken: trim((string) $data['token']),
            requireCallbackToken: true,
            gatewayMeta: $gatewayMeta
        );

        $this->queueAccountingSync($result);

        $payload = [
            'message' => $result['already_processed']
                ? 'Payment callback already processed.'
                : 'Payment callback processed successfully.',
            'payment' => $result['payment'],
            'invoice' => $result['invoice'],
        ];

        if ($this->shouldReturnCallbackJson($request)) {
            return response()->json($payload);
        }

        $frontendRedirectUrl = $this->buildFrontendCallbackRedirectUrl(
            $result['payment'],
            $result['invoice'],
            $authority !== '' ? $authority : null
        );

        if ($frontendRedirectUrl !== null) {
            return redirect()->away($frontendRedirectUrl);
        }

        return response()->json($payload);
    }

    /**
     * @return array{payment:Payment,invoice:Invoice,already_processed:bool}
     */
    protected function finalizePayment(
        int $paymentId,
        string $status,
        ?string $authority,
        ?string $reference,
        ?string $reason,
        ?string $callbackToken,
        bool $requireCallbackToken,
        array $gatewayMeta = []
    ): array {
        return DB::transaction(function () use (
            $paymentId,
            $status,
            $authority,
            $reference,
            $reason,
            $callbackToken,
            $requireCallbackToken,
            $gatewayMeta
        ) {
            /** @var Payment|null $payment */
            $payment = Payment::query()->where('id', $paymentId)->lockForUpdate()->first();
            abort_if(! $payment, 404, 'Payment not found.');

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('id', $payment->invoice_id)->lockForUpdate()->first();
            abort_if(! $invoice, 422, 'Invoice not found for payment.');

            $paymentMeta = (array) ($payment->meta ?? []);
            $storedAuthority = (string) data_get($paymentMeta, 'authority', (string) $payment->reference);

            if ($authority !== null && $storedAuthority !== '' && ! hash_equals($storedAuthority, $authority)) {
                abort(422, 'Payment authority is invalid.');
            }

            if ($requireCallbackToken) {
                $storedCallbackToken = (string) data_get($paymentMeta, 'callback_token');
                abort_if(
                    $storedCallbackToken === '' || $callbackToken === null || ! hash_equals($storedCallbackToken, $callbackToken),
                    422,
                    'Payment callback token is invalid.'
                );
            }

            if ((string) $payment->status === 'paid') {
                return [
                    'payment' => $payment->fresh(),
                    'invoice' => $this->loadInvoicePayload($invoice),
                    'already_processed' => true,
                ];
            }

            $normalizedPaymentStatus = $status === 'success' ? 'paid' : 'failed';
            $targetInvoiceStatus = $status === 'success'
                ? InvoiceStatusService::PAID
                : InvoiceStatusService::PAYMENT_FAILED;

            abort_if(
                ! InvoiceStatusService::canTransition((string) $invoice->status, $targetInvoiceStatus),
                422,
                'Invoice status transition is not allowed.'
            );

            $paymentMeta['verified_at'] = now()->toAtomString();
            if ($reason) {
                $paymentMeta['reason'] = $reason;
            }
            if ($gatewayMeta !== []) {
                $paymentMeta['gateway_callback_log'] = $gatewayMeta;
            }

            $finalReference = $reference ?: $payment->reference;
            if ($normalizedPaymentStatus === 'paid' && (! is_string($finalReference) || $finalReference === '')) {
                $finalReference = 'PAID-'.Str::upper(Str::random(16));
            }

            $payment->update([
                'status' => $normalizedPaymentStatus,
                'reference' => $finalReference,
                'paid_at' => $normalizedPaymentStatus === 'paid' ? now() : null,
                'meta' => $paymentMeta,
            ]);

            $invoiceMeta = (array) ($invoice->meta ?? []);
            $invoiceMeta['payment'] = [
                'payment_id' => $payment->id,
                'status' => $normalizedPaymentStatus,
                'reference' => $payment->reference,
                'verified_at' => now()->toAtomString(),
            ];

            if ($reason) {
                $invoiceMeta['payment']['reason'] = $reason;
            }

            $invoice->update([
                'status' => $targetInvoiceStatus,
                'meta' => $invoiceMeta,
            ]);

            if ($targetInvoiceStatus === InvoiceStatusService::PAYMENT_FAILED) {
                $this->invoiceAllocationService->releaseForFailedPayment($invoice, $reason);
                $invoice = $invoice->fresh();
            } elseif ($targetInvoiceStatus === InvoiceStatusService::PAID) {
                $this->invoiceAllocationService->commitForPaidPayment($invoice);
                $invoice = $invoice->fresh();
            }

            return [
                'payment' => $payment->fresh(),
                'invoice' => $this->loadInvoicePayload($invoice),
                'already_processed' => false,
            ];
        });
    }

    protected function loadInvoicePayload(Invoice $invoice): Invoice
    {
        return $invoice->fresh()->load([
            'items',
            'payments',
            'address.city.state',
            'coupon',
            'shippingMethod',
            'shipment.shippingMethod',
        ]);
    }

    /**
     * @param  array{payment:Payment,invoice:Invoice,already_processed:bool}  $result
     */
    protected function queueAccountingSync(array $result): void
    {
        if (! $result['already_processed'] && (string) $result['invoice']->status === InvoiceStatusService::PAID) {
            $this->accountingOutboxService->dispatchPaidInvoiceSafely($result['invoice']);
        }
    }

    protected function generateInvoiceNumber(): string
    {
        return 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }

    protected function shouldReturnCallbackJson(Request $request): bool
    {
        $responseMode = Str::lower(trim((string) $request->query('response', '')));

        if (in_array($responseMode, ['json', 'api'], true)) {
            return true;
        }

        if (in_array($responseMode, ['redirect', 'web'], true)) {
            return false;
        }

        return $request->expectsJson() || $request->wantsJson();
    }

    protected function buildFrontendCallbackRedirectUrl(
        Payment $payment,
        Invoice $invoice,
        ?string $authority = null
    ): ?string {
        $baseUrl = trim((string) config('payment.frontend_callback_url', ''));
        if ($baseUrl === '') {
            return null;
        }

        $query = [
            'payment_id' => $payment->id,
            'payment_status' => (string) $payment->status,
            'invoice_id' => $invoice->id,
            'invoice_status' => (string) $invoice->status,
        ];

        if ($payment->reference !== null && trim((string) $payment->reference) !== '') {
            $query['reference'] = trim((string) $payment->reference);
        }

        if ($authority !== null && trim($authority) !== '') {
            $query['authority'] = trim($authority);
        }

        $reason = trim((string) data_get((array) ($payment->meta ?? []), 'reason', ''));
        if ($reason !== '') {
            $query['reason'] = $reason;
        }

        $delimiter = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$delimiter.http_build_query($query);
    }
}
