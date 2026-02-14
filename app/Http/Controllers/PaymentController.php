<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Checkout\CheckoutPricingService;
use App\Support\Coupons\CouponService;
use App\Support\Invoices\InvoiceAllocationService;
use App\Support\Invoices\InvoiceStatusService;
use App\Support\Payments\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
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
        protected PaymentGatewayService $paymentGatewayService
    )
    {
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
                required: ['address_id', 'items'],
                properties: [
                    new OA\Property(property: 'address_id', type: 'integer', example: 3),
                    new OA\Property(property: 'coupon_code', type: 'string', nullable: true, example: 'WELCOME10'),
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        /** @var Address|null $address */
        $address = $user->addresses()->where('id', $data['address_id'])->first();

        abort_if(! $address, 422, 'Selected address not found.');

        $productIds = collect($data['items'])->pluck('product_id')->all();

        $couponCode = isset($data['coupon_code']) ? trim((string) $data['coupon_code']) : null;

        /** @var Invoice $invoice */
        $invoice = DB::transaction(function () use ($user, $address, $productIds, $data, $couponCode) {
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->whereIn('status', ['active', 'special'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_if($products->count() !== count($productIds), 422, 'One or more products are unavailable.');

            $itemsPayload = collect($data['items'])->map(function (array $item) use ($products) {
                /** @var Product|null $product */
                $product = $products->get($item['product_id']);

                abort_if(! $product, 422, 'Product '.$item['product_id'].' not available.');

                $quantity = (int) $item['quantity'];
                $stock = $product->stock !== null ? (int) $product->stock : null;

                abort_if($stock !== null && $stock < $quantity, 422, "Insufficient stock for {$product->name}.");

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'total' => $product->price * $quantity,
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
            $pricing = CheckoutPricingService::calculate($total);
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
            $meta['tax'] = $tax;
            $meta['allocation'] = [
                'reserved_at' => now()->toAtomString(),
                'last_action' => 'reserved',
            ];

            $invoice = Invoice::query()->create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'coupon_id' => $coupon?->id,
                'number' => $this->generateInvoiceNumber(),
                'status' => InvoiceStatusService::PENDING,
                'currency' => $currency,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'total' => $grandTotal,
                'issued_at' => now(),
                'meta' => $meta,
            ]);

            $itemsPayload->each(function (array $payload) use ($invoice): void {
                /** @var Product $product */
                $product = $payload['product'];
                $quantity = (int) $payload['quantity'];

                Item::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'quantity' => $quantity,
                    'unit_price' => $payload['unit_price'],
                    'total' => $payload['total'],
                    'meta' => [
                        'sku' => $product->sku,
                        'product_status' => $product->status,
                    ],
                ]);

                if ($product->stock !== null) {
                    $product->stock = max(0, (int) $product->stock - $quantity);
                }

                $product->sold_count = (int) ($product->sold_count ?? 0) + $quantity;
                $product->save();
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

            return $invoice->fresh()->load([
                'items',
                'address.city.state',
                'coupon',
            ]);
        });

        return response()->json($invoice);
    }

    public function initiate(Request $request): JsonResponse
    {
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
                DB::transaction(function () use ($payment, $invoice, $previousStatus, $reservedForRetry): void {
                    /** @var Payment|null $lockedPayment */
                    $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
                    /** @var Invoice|null $lockedInvoice */
                    $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

                    if ($lockedPayment) {
                        $paymentMeta = (array) ($lockedPayment->meta ?? []);
                        $paymentMeta['initiation_error'] = 'gateway_init_failed';

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

        return response()->json([
            'message' => $result['already_processed']
                ? 'Payment has already been processed.'
                : 'Payment verification completed.',
            'payment' => $result['payment'],
            'invoice' => $result['invoice'],
        ]);
    }

    public function callback(Request $request): JsonResponse
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
            requireCallbackToken: true
        );

        return response()->json([
            'message' => $result['already_processed']
                ? 'Payment callback already processed.'
                : 'Payment callback processed successfully.',
            'payment' => $result['payment'],
            'invoice' => $result['invoice'],
        ]);
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
        bool $requireCallbackToken
    ): array {
        return DB::transaction(function () use (
            $paymentId,
            $status,
            $authority,
            $reference,
            $reason,
            $callbackToken,
            $requireCallbackToken
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
        ]);
    }

    protected function generateInvoiceNumber(): string
    {
        return 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }
}
