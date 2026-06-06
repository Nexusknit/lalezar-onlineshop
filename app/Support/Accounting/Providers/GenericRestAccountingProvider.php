<?php

namespace App\Support\Accounting\Providers;

use App\Support\Accounting\AccountingConfiguration;
use App\Support\Accounting\Contracts\AccountingProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GenericRestAccountingProvider implements AccountingProviderInterface
{
    public function __construct(
        private readonly AccountingConfiguration $configuration
    ) {}

    public function name(): string
    {
        return 'generic_rest';
    }

    public function healthCheck(): array
    {
        $response = $this->request()->get($this->configuration->healthPath());
        $response->throw();

        return [
            'ok' => true,
            'status' => $response->status(),
            'data' => $this->responseData($response->json()),
        ];
    }

    public function fetchProducts(?string $cursor = null, ?int $perPage = null): array
    {
        $query = array_filter([
            'cursor' => $cursor,
            'per_page' => $perPage,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->request()->get($this->configuration->productsPath(), $query);
        $response->throw();

        $body = $response->json();
        $items = data_get($body, 'data', data_get($body, 'products', data_get($body, 'items', $body)));

        if (! is_array($items)) {
            throw new RuntimeException('Accounting products response must contain an array.');
        }

        return [
            'items' => array_values(array_filter($items, 'is_array')),
            'next_cursor' => $this->nullableString(
                data_get($body, 'next_cursor', data_get($body, 'meta.next_cursor'))
            ),
            'meta' => is_array(data_get($body, 'meta')) ? data_get($body, 'meta') : [],
        ];
    }

    public function pushInvoice(array $payload, string $idempotencyKey): array
    {
        $response = $this->request()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post($this->configuration->invoicesPath(), $payload);
        $response->throw();

        $body = $this->responseData($response->json());
        $externalId = $this->nullableString(
            data_get($body, 'id', data_get($body, 'invoice_id', data_get($body, 'number')))
        );

        return [
            'ok' => true,
            'status' => $response->status(),
            'external_id' => $externalId,
            'data' => $body,
        ];
    }

    private function request(): PendingRequest
    {
        if (! $this->configuration->configured()) {
            throw new RuntimeException('Accounting base URL is not configured.');
        }

        $request = Http::baseUrl($this->configuration->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(max(3, (int) config('accounting.timeout', 15)))
            ->withOptions([
                'verify' => (bool) config('accounting.verify_ssl', true),
            ]);

        $token = trim((string) config('accounting.token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $apiKey = trim((string) config('accounting.api_key', ''));
        if ($apiKey !== '') {
            $header = trim((string) config('accounting.api_key_header', 'X-API-Key')) ?: 'X-API-Key';
            $request = $request->withHeader($header, $apiKey);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $data = data_get($body, 'data', $body);

        return is_array($data) ? $data : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
