<?php

namespace App\Support\Accounting;

use App\Models\AccountingProductMapping;
use App\Models\Product;
use App\Models\User;
use App\Support\Accounting\Contracts\AccountingProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImportService
{
    /**
     * @return array{received:int,created:int,updated:int,unchanged:int,pages:int}
     */
    public function import(AccountingProviderInterface $provider): array
    {
        $result = ['received' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'pages' => 0];
        $cursor = null;
        $seenCursors = [];
        $perPage = max(1, min(500, (int) config('accounting.per_page', 100)));
        $maxPages = max(1, (int) config('accounting.max_pages', 100));

        do {
            $page = $provider->fetchProducts($cursor, $perPage);
            $result['pages']++;

            foreach ($page['items'] as $remoteProduct) {
                $result['received']++;
                $action = $this->importOne($remoteProduct, $provider->name());
                $result[$action]++;
            }

            $nextCursor = $page['next_cursor'] ?? null;
            if ($nextCursor === null || $nextCursor === '' || isset($seenCursors[$nextCursor])) {
                break;
            }

            $seenCursors[$nextCursor] = true;
            $cursor = $nextCursor;
        } while ($result['pages'] < $maxPages);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return 'created'|'updated'|'unchanged'
     */
    private function importOne(array $remote, string $provider): string
    {
        $normalized = $this->normalize($remote);
        $checksum = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($normalized, $checksum, $remote, $provider): string {
            $mapping = AccountingProductMapping::query()
                ->where('provider', $provider)
                ->where('external_id', $normalized['external_id'])
                ->lockForUpdate()
                ->first();

            if ($mapping && hash_equals((string) $mapping->checksum, $checksum)) {
                $mapping->update(['last_synced_at' => now()]);

                return 'unchanged';
            }

            $product = $mapping?->product;
            if (! $product && $normalized['sku'] !== null) {
                $product = Product::withTrashed()->where('sku', $normalized['sku'])->lockForUpdate()->first();
            }

            $created = ! $product;
            if (! $product) {
                $product = new Product;
                $product->creator_id = $this->creatorId();
                $product->slug = $this->uniqueSlug($normalized['name'], $normalized['external_id']);
            }

            if ($product->trashed()) {
                $product->restore();
            }

            $product->fill([
                'name' => $normalized['name'],
                'sku' => $normalized['sku'],
                'stock' => $normalized['stock'],
                'price' => $normalized['price'],
                'currency' => $normalized['currency'],
                'status' => $normalized['status'],
            ]);
            $product->save();

            AccountingProductMapping::query()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'provider' => $provider,
                    'external_id' => $normalized['external_id'],
                    'checksum' => $checksum,
                    'remote_updated_at' => $normalized['remote_updated_at'],
                    'last_synced_at' => now(),
                    'meta' => ['remote' => $remote],
                ]
            );

            return $created ? 'created' : 'updated';
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function normalize(array $remote): array
    {
        $externalId = $this->stringValue(
            data_get($remote, 'external_id', data_get($remote, 'id', data_get($remote, 'code')))
        );
        $name = $this->stringValue(data_get($remote, 'name', data_get($remote, 'title')));

        if ($externalId === null || $name === null) {
            throw new RuntimeException('Each accounting product requires external_id/id and name.');
        }

        $sku = $this->stringValue(data_get($remote, 'sku', data_get($remote, 'code')));
        $status = Str::lower((string) data_get($remote, 'status', 'active'));
        $active = data_get($remote, 'active');
        if (is_bool($active)) {
            $status = $active ? 'active' : 'draft';
        } elseif (! in_array($status, ['active', 'special'], true)) {
            $status = 'draft';
        }

        return [
            'external_id' => $externalId,
            'name' => $name,
            'sku' => $sku,
            'stock' => max(0, (int) data_get($remote, 'stock', data_get($remote, 'quantity', 0))),
            'price' => max(0, (float) data_get($remote, 'price', data_get($remote, 'sale_price', 0))),
            'currency' => Str::upper((string) data_get($remote, 'currency', 'IRR')),
            'status' => $status,
            'remote_updated_at' => data_get($remote, 'updated_at'),
        ];
    }

    private function creatorId(): int
    {
        $configured = (int) config('accounting.product_creator_id', 0);
        if ($configured > 0 && User::query()->whereKey($configured)->exists()) {
            return $configured;
        }

        $userId = User::query()
            ->whereHas('roles', static fn ($query) => $query->where('slug', 'super-admin'))
            ->value('id') ?? User::query()->value('id');

        if (! $userId) {
            throw new RuntimeException('No user is available as creator for imported accounting products.');
        }

        return (int) $userId;
    }

    private function uniqueSlug(string $name, string $externalId): string
    {
        $base = Str::slug($name) ?: 'accounting-product-'.Str::slug($externalId);
        $slug = $base;
        $counter = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function stringValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
