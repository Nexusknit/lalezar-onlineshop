<?php

namespace App\Support\Accounting\Contracts;

interface AccountingProviderInterface
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array;

    /**
     * @return array{items:list<array<string,mixed>>,next_cursor:?string,meta:array<string,mixed>}
     */
    public function fetchProducts(?string $cursor = null, ?int $perPage = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pushInvoice(array $payload, string $idempotencyKey): array;
}
