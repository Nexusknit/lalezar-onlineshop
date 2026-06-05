<?php

namespace App\Support\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentGatewayService
{
    public const PROVIDER_MOCK = 'mock_gateway';

    public const PROVIDER_SHETABIT = 'shetabit';

    public const PROVIDER_ZARINPAL = 'zarinpal';

    public function __construct(
        protected ShetabitPaymentClient $shetabitPaymentClient
    ) {}

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return [
            self::PROVIDER_MOCK,
            self::PROVIDER_SHETABIT,
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
            self::PROVIDER_SHETABIT => $this->initiateShetabit($payment, $invoice),
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

        if ($provider === self::PROVIDER_SHETABIT && $redirectUrl === '' && $authority !== '') {
            $redirectUrl = $this->shetabitStartPayUrl($authority);
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

        if ($provider === self::PROVIDER_SHETABIT) {
            return $this->resolveShetabitCallbackOutcome(
                $payment,
                $invoice,
                $authority,
                $gatewayStatus
            );
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
    protected function initiateShetabit(Payment $payment, Invoice $invoice): array
    {
        $driver = $this->shetabitDriver();
        $merchantId = trim((string) config('payment.providers.shetabit.merchant_id', ''));
        if ($driver === 'zarinpal' && $merchantId === '') {
            throw ValidationException::withMessages([
                'payment' => ['Shetabit Zarinpal merchant id is missing.'],
            ]);
        }

        $meta = (array) ($payment->meta ?? []);
        $callbackToken = (string) data_get($meta, 'callback_token');
        if ($callbackToken === '') {
            throw ValidationException::withMessages([
                'payment' => ['Payment callback token is missing.'],
            ]);
        }

        $amount = (int) round((float) $payment->amount);
        if ($amount < 1) {
            throw ValidationException::withMessages([
                'payment' => ['Payment amount must be greater than zero.'],
            ]);
        }

        $callbackUrl = $this->buildCallbackUrl([
            'payment_id' => $payment->id,
            'token' => $callbackToken,
        ]);

        $invoice->loadMissing('user');
        $description = $this->paymentDescription($invoice, 'payment.providers.shetabit.description');
        $details = array_filter([
            'description' => $description,
            'invoice_id' => (string) $invoice->id,
            'invoice_number' => (string) $invoice->number,
            'mobile' => (string) ($invoice->user?->phone ?? ''),
            'email' => (string) ($invoice->user?->email ?? ''),
        ]);

        $requestPayload = [
            'driver' => $driver,
            'mode' => $this->shetabitMode(),
            'amount' => $amount,
            'currency' => $this->shetabitCurrency(),
            'callback_url' => $callbackUrl,
            'description' => $description,
            'details' => $details,
        ];

        try {
            $purchase = $this->shetabitPaymentClient->purchase(
                $this->shetabitConfig($callbackUrl),
                $driver,
                $amount,
                $callbackUrl,
                $details
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'payment' => [$this->gatewayExceptionMessage($exception, 'Unable to initiate Shetabit payment.')],
            ]);
        }

        $authority = trim((string) ($purchase['authority'] ?? ''));
        $redirectUrl = trim((string) ($purchase['redirect_url'] ?? ''));
        if ($authority === '' || $redirectUrl === '') {
            throw ValidationException::withMessages([
                'payment' => ['Shetabit payment gateway did not return a usable authority or redirect URL.'],
            ]);
        }

        return [
            'provider' => self::PROVIDER_SHETABIT,
            'authority' => $authority,
            'callback_token' => $callbackToken,
            'redirect_url' => $redirectUrl,
            'meta' => [
                'gateway' => self::PROVIDER_SHETABIT,
                'driver' => $driver,
                'mode' => $this->shetabitMode(),
                'authority' => $authority,
                'callback_token' => $callbackToken,
                'redirect_url' => $redirectUrl,
                'gateway_request' => $this->sanitizeGatewayPayload($requestPayload),
                'gateway_response' => $this->sanitizeGatewayPayload([
                    'authority' => $authority,
                    'redirect_url' => $redirectUrl,
                ]),
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
     *   reason:?string,
     *   meta?:array<string,mixed>
     * }
     */
    protected function resolveShetabitCallbackOutcome(
        Payment $payment,
        Invoice $invoice,
        ?string $authority,
        ?string $gatewayStatus
    ): array {
        $normalizedAuthority = trim((string) ($authority ?? ''));
        if ($normalizedAuthority === '') {
            throw ValidationException::withMessages([
                'authority' => ['Shetabit callback is missing authority.'],
            ]);
        }

        $status = Str::lower(trim((string) ($gatewayStatus ?? '')));
        if (! in_array($status, ['ok', 'success', 'paid', '1', 'true'], true)) {
            return [
                'status' => 'failed',
                'reference' => null,
                'reason' => $status === '' ? 'shetabit_callback_not_ok' : "shetabit_callback_{$status}",
                'meta' => [
                    'gateway_callback' => $this->sanitizeGatewayPayload([
                        'authority' => $normalizedAuthority,
                        'status' => $status,
                    ]),
                ],
            ];
        }

        try {
            $receipt = $this->shetabitPaymentClient->verify(
                $this->shetabitConfig(),
                $this->shetabitDriver(),
                (int) round((float) $invoice->total),
                $normalizedAuthority
            );
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'reference' => null,
                'reason' => $this->gatewayExceptionMessage($exception, 'shetabit_verify_failed'),
                'meta' => [
                    'gateway_callback' => $this->sanitizeGatewayPayload([
                        'authority' => $normalizedAuthority,
                        'status' => $status,
                    ]),
                    'gateway_verify_error' => $this->gatewayExceptionMessage($exception, 'shetabit_verify_failed'),
                ],
            ];
        }

        $reference = trim((string) ($receipt['reference'] ?? ''));

        return [
            'status' => 'success',
            'reference' => $reference !== '' ? $reference : null,
            'reason' => null,
            'meta' => [
                'gateway_callback' => $this->sanitizeGatewayPayload([
                    'authority' => $normalizedAuthority,
                    'status' => $status,
                ]),
                'gateway_verify_response' => $this->sanitizeGatewayPayload((array) ($receipt['details'] ?? [])),
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

    /**
     * @return array<string,mixed>
     */
    protected function shetabitConfig(?string $callbackUrl = null): array
    {
        $config = require \Shetabit\Multipay\Payment::getDefaultConfigPath();
        $driver = $this->shetabitDriver();
        $driverConfig = (array) data_get($config, "drivers.{$driver}", []);

        if ($driver === 'zarinpal') {
            $driverConfig = array_merge($driverConfig, [
                'mode' => $this->shetabitMode(),
                'merchantId' => trim((string) config('payment.providers.shetabit.merchant_id', '')),
                'callbackUrl' => $callbackUrl ?: (string) data_get($driverConfig, 'callbackUrl', ''),
                'description' => (string) config('payment.providers.shetabit.description', 'پرداخت سفارش '.config('app.name', 'فروشگاه')),
                'currency' => $this->shetabitCurrency(),
            ]);
        }

        data_set($config, 'default', $driver);
        data_set($config, "drivers.{$driver}", $driverConfig);

        return $config;
    }

    protected function shetabitDriver(): string
    {
        return Str::lower(trim((string) config('payment.providers.shetabit.driver', 'zarinpal'))) ?: 'zarinpal';
    }

    protected function shetabitMode(): string
    {
        return (bool) config('payment.providers.shetabit.sandbox', true) ? 'sandbox' : 'normal';
    }

    protected function shetabitCurrency(): string
    {
        $currency = Str::upper(trim((string) config('payment.providers.shetabit.currency', 'R')));

        return in_array($currency, ['R', 'T'], true) ? $currency : 'R';
    }

    protected function shetabitStartPayUrl(string $authority): string
    {
        if ($this->shetabitDriver() !== 'zarinpal') {
            return '';
        }

        $host = $this->shetabitMode() === 'sandbox'
            ? 'https://sandbox.zarinpal.com'
            : 'https://www.zarinpal.com';

        return "{$host}/pg/StartPay/{$authority}";
    }

    protected function paymentDescription(Invoice $invoice, string $configKey): string
    {
        $descriptionPrefix = trim((string) config($configKey, 'پرداخت سفارش '.config('app.name', 'فروشگاه')));
        $description = trim("{$descriptionPrefix} #{$invoice->number}");

        return $description !== '' ? $description : "Invoice #{$invoice->id}";
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function sanitizeGatewayPayload(array $payload): array
    {
        $sensitiveKeys = [
            'api_key',
            'apikey',
            'callback_token',
            'callbacktoken',
            'client_secret',
            'clientsecret',
            'merchant_id',
            'merchantid',
            'password',
            'secret',
            'token',
        ];

        foreach ($payload as $key => $value) {
            $normalizedKey = Str::lower(str_replace(['-', '_'], '', (string) $key));
            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $payload[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitizeGatewayPayload($value);

                continue;
            }

            if (is_string($value) && str_contains($value, 'token=')) {
                $payload[$key] = preg_replace('/([?&]token=)[^&]+/i', '$1[redacted]', $value) ?? '[redacted]';
            }
        }

        return $payload;
    }

    protected function gatewayExceptionMessage(Throwable $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : $fallback;
    }
}
