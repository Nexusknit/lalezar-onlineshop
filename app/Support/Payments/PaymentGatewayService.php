<?php

namespace App\Support\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentGatewayService
{
    public const PROVIDER_MOCK = 'mock_gateway';
    public const PROVIDER_ZARINPAL = 'zarinpal';

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return [
            self::PROVIDER_MOCK,
            self::PROVIDER_ZARINPAL,
        ];
    }

    public function resolveProvider(?string $requestedProvider = null): string
    {
        $provider = Str::lower(trim((string) ($requestedProvider ?? '')));
        if ($provider === '') {
            $provider = Str::lower((string) config('payment.default_provider', self::PROVIDER_MOCK));
        }

        if (! in_array($provider, self::providers(), true)) {
            throw ValidationException::withMessages([
                'method' => ['Unsupported payment gateway method.'],
            ]);
        }

        $enabled = (bool) config("payment.providers.{$provider}.enabled", false);
        if (! $enabled) {
            throw ValidationException::withMessages([
                'method' => ["Payment gateway '{$provider}' is not enabled."],
            ]);
        }

        return $provider;
    }

    /**
     * @return array{
     *   provider:string,
     *   authority:string,
     *   callback_token:string,
     *   redirect_url:string,
     *   meta:array<string,mixed>
     * }
     */
    public function initiate(Payment $payment, Invoice $invoice): array
    {
        $provider = $this->resolveProvider($payment->method);

        return match ($provider) {
            self::PROVIDER_ZARINPAL => $this->initiateZarinpal($payment, $invoice),
            default => $this->initiateMock($payment),
        };
    }

    /**
     * @return array{
     *   provider:string,
     *   authority:string,
     *   callback_token:string,
     *   redirect_url:string
     * }
     */
    public function buildGatewayPayload(Payment $payment): array
    {
        $provider = $this->resolveProvider($payment->method);
        $meta = (array) ($payment->meta ?? []);

        $authority = (string) data_get($meta, 'authority', (string) $payment->reference);
        $callbackToken = (string) data_get($meta, 'callback_token');
        $redirectUrl = (string) data_get($meta, 'redirect_url');

        if ($provider === self::PROVIDER_MOCK && $redirectUrl === '') {
            $redirectUrl = $this->buildCallbackUrl([
                'payment_id' => $payment->id,
                'authority' => $authority,
                'token' => $callbackToken,
                'status' => 'success',
            ]);
        }

        if ($provider === self::PROVIDER_ZARINPAL && $redirectUrl === '' && $authority !== '') {
            $redirectUrl = $this->zarinpalStartPayUrl($authority);
        }

        return [
            'provider' => $provider,
            'authority' => $authority,
            'callback_token' => $callbackToken,
            'redirect_url' => $redirectUrl,
        ];
    }

    /**
     * @return array{
     *   status:'success'|'failed',
     *   reference:?string,
     *   reason:?string
     * }
     */
    public function resolveCallbackOutcome(
        Payment $payment,
        Invoice $invoice,
        ?string $authority,
        ?string $gatewayStatus
    ): array {
        $provider = $this->resolveProvider($payment->method);

        if ($provider === self::PROVIDER_MOCK) {
            $normalizedStatus = Str::lower(trim((string) $gatewayStatus));
            if (! in_array($normalizedStatus, ['success', 'failed'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Mock gateway callback status must be success or failed.'],
                ]);
            }

            return [
                'status' => $normalizedStatus,
                'reference' => null,
                'reason' => null,
            ];
        }

        return $this->resolveZarinpalCallbackOutcome(
            $payment,
            $invoice,
            $authority,
            $gatewayStatus
        );
    }

    /**
     * @return array{
     *   provider:string,
     *   authority:string,
     *   callback_token:string,
     *   redirect_url:string,
     *   meta:array<string,mixed>
     * }
     */
    protected function initiateMock(Payment $payment): array
    {
        $meta = (array) ($payment->meta ?? []);
        $authority = (string) data_get($meta, 'authority', (string) $payment->reference);
        $callbackToken = (string) data_get($meta, 'callback_token');

        if ($authority === '') {
            $authority = 'AUTH-'.Str::upper(Str::random(16));
        }
        if ($callbackToken === '') {
            throw ValidationException::withMessages([
                'payment' => ['Payment callback token is missing.'],
            ]);
        }

        $redirectUrl = $this->buildCallbackUrl([
            'payment_id' => $payment->id,
            'authority' => $authority,
            'token' => $callbackToken,
            'status' => 'success',
        ]);

        return [
            'provider' => self::PROVIDER_MOCK,
            'authority' => $authority,
            'callback_token' => $callbackToken,
            'redirect_url' => $redirectUrl,
            'meta' => [
                'gateway' => self::PROVIDER_MOCK,
                'authority' => $authority,
                'callback_token' => $callbackToken,
                'redirect_url' => $redirectUrl,
            ],
        ];
    }

    /**
     * @return array{
     *   provider:string,
     *   authority:string,
     *   callback_token:string,
     *   redirect_url:string,
     *   meta:array<string,mixed>
     * }
     */
    protected function initiateZarinpal(Payment $payment, Invoice $invoice): array
    {
        $merchantId = trim((string) config('payment.providers.zarinpal.merchant_id', ''));
        if ($merchantId === '') {
            throw ValidationException::withMessages([
                'payment' => ['Zarinpal merchant id is missing.'],
            ]);
        }

        $timeout = max(3, (int) config('payment.providers.zarinpal.timeout', 10));
        $descriptionPrefix = trim((string) config('payment.providers.zarinpal.description', 'Online order payment'));

        $meta = (array) ($payment->meta ?? []);
        $callbackToken = (string) data_get($meta, 'callback_token');
        if ($callbackToken === '') {
            throw ValidationException::withMessages([
                'payment' => ['Payment callback token is missing.'],
            ]);
        }

        $callbackUrl = $this->buildCallbackUrl([
            'payment_id' => $payment->id,
            'token' => $callbackToken,
        ]);

        $amount = (int) round((float) $payment->amount);
        if ($amount < 1) {
            throw ValidationException::withMessages([
                'payment' => ['Payment amount must be greater than zero.'],
            ]);
        }

        $invoice->loadMissing('user');
        $description = trim("{$descriptionPrefix} #{$invoice->number}");
        if ($description === '') {
            $description = "Invoice #{$invoice->id}";
        }

        $payload = [
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'description' => $description,
            'metadata' => array_filter([
                'invoice_id' => (string) $invoice->id,
                'mobile' => (string) ($invoice->user?->phone ?? ''),
                'email' => (string) ($invoice->user?->email ?? ''),
            ]),
        ];

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->post($this->zarinpalRequestUrl(), $payload);

        if (! $response->ok()) {
            throw ValidationException::withMessages([
                'payment' => ['Unable to connect to zarinpal payment gateway.'],
            ]);
        }

        $body = $response->json();
        $data = is_array($body) ? ((array) data_get($body, 'data', [])) : [];
        $code = (int) data_get($data, 'code', 0);
        $authority = trim((string) data_get($data, 'authority', ''));
        $message = trim((string) data_get($data, 'message', ''));

        if ($code !== 100 || $authority === '') {
            $reason = $message !== '' ? $message : "Zarinpal request rejected with code {$code}.";
            throw ValidationException::withMessages([
                'payment' => [$reason],
            ]);
        }

        $redirectUrl = $this->zarinpalStartPayUrl($authority);

        return [
            'provider' => self::PROVIDER_ZARINPAL,
            'authority' => $authority,
            'callback_token' => $callbackToken,
            'redirect_url' => $redirectUrl,
            'meta' => [
                'gateway' => self::PROVIDER_ZARINPAL,
                'authority' => $authority,
                'callback_token' => $callbackToken,
                'redirect_url' => $redirectUrl,
                'request_payload' => [
                    'amount' => $amount,
                    'currency' => (string) $payment->currency,
                ],
            ],
        ];
    }

    /**
     * @return array{
     *   status:'success'|'failed',
     *   reference:?string,
     *   reason:?string
     * }
     */
    protected function resolveZarinpalCallbackOutcome(
        Payment $payment,
        Invoice $invoice,
        ?string $authority,
        ?string $gatewayStatus
    ): array {
        $normalizedAuthority = trim((string) ($authority ?? ''));
        if ($normalizedAuthority === '') {
            throw ValidationException::withMessages([
                'authority' => ['Zarinpal callback is missing authority.'],
            ]);
        }

        $status = Str::lower(trim((string) ($gatewayStatus ?? '')));
        if (! in_array($status, ['ok', 'success', 'paid', '1', 'true'], true)) {
            return [
                'status' => 'failed',
                'reference' => null,
                'reason' => $status === '' ? 'zarinpal_callback_not_ok' : "zarinpal_callback_{$status}",
            ];
        }

        $merchantId = trim((string) config('payment.providers.zarinpal.merchant_id', ''));
        if ($merchantId === '') {
            throw ValidationException::withMessages([
                'payment' => ['Zarinpal merchant id is missing.'],
            ]);
        }

        $timeout = max(3, (int) config('payment.providers.zarinpal.timeout', 10));
        $amount = (int) round((float) $invoice->total);

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->post($this->zarinpalVerifyUrl(), [
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'authority' => $normalizedAuthority,
            ]);

        if (! $response->ok()) {
            return [
                'status' => 'failed',
                'reference' => null,
                'reason' => 'zarinpal_verify_http_error',
            ];
        }

        $body = $response->json();
        $data = is_array($body) ? ((array) data_get($body, 'data', [])) : [];
        $code = (int) data_get($data, 'code', 0);

        if (! in_array($code, [100, 101], true)) {
            $message = trim((string) data_get($data, 'message', ''));
            $reason = $message !== '' ? $message : "zarinpal_verify_code_{$code}";

            return [
                'status' => 'failed',
                'reference' => null,
                'reason' => $reason,
            ];
        }

        $refId = (string) data_get($data, 'ref_id', '');
        $reference = trim($refId) !== '' ? trim($refId) : (string) $payment->reference;

        return [
            'status' => 'success',
            'reference' => $reference !== '' ? $reference : null,
            'reason' => null,
        ];
    }

    public function buildCallbackUrl(array $query): string
    {
        $base = trim((string) config('payment.callback_base_url', ''));

        if ($base === '' && app()->bound('request')) {
            $request = request();
            if ($request) {
                $base = trim((string) $request->getSchemeAndHttpHost());
            }
        }

        if ($base === '') {
            $base = trim((string) config('app.url', 'http://127.0.0.1:8000'));
        }

        $base = rtrim($base, '/');

        return $base.'/api/payments/callback?'.http_build_query($query);
    }

    protected function zarinpalRequestUrl(): string
    {
        return $this->isZarinpalSandbox()
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
            : 'https://payment.zarinpal.com/pg/v4/payment/request.json';
    }

    protected function zarinpalVerifyUrl(): string
    {
        return $this->isZarinpalSandbox()
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json'
            : 'https://payment.zarinpal.com/pg/v4/payment/verify.json';
    }

    protected function zarinpalStartPayUrl(string $authority): string
    {
        $host = $this->isZarinpalSandbox()
            ? 'https://sandbox.zarinpal.com'
            : 'https://payment.zarinpal.com';

        return "{$host}/pg/StartPay/{$authority}";
    }

    protected function isZarinpalSandbox(): bool
    {
        return (bool) config('payment.providers.zarinpal.sandbox', true);
    }
}
