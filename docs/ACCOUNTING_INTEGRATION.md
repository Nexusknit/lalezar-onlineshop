# Optional Accounting Integration

The accounting integration is disabled by default and is isolated from checkout and payment state. A failed accounting request must never revert a successful local payment.

## Activation

1. Configure credentials in `.env`.
2. Configure the base URL and endpoint paths in `.env` or the admin settings page.
3. Run the database queue worker.
4. Enable the master accounting switch and the required product/invoice capabilities.

Secrets such as tokens and API keys are never stored in the `settings` table.

## Generic REST Contract

### Health check

`GET {base_url}{health_path}`

Any successful HTTP response is considered healthy. JSON response data is stored in the sync log.

### Product import

`GET {base_url}{products_path}?cursor={cursor}&per_page={per_page}`

Accepted list locations:

- Root JSON array
- `data`
- `products`
- `items`

Optional next-page cursor:

- `next_cursor`
- `meta.next_cursor`

Product fields:

| Site field | Accepted accounting field |
| --- | --- |
| External ID | `external_id`, `id`, or `code` |
| Name | `name` or `title` |
| SKU | `sku` or `code` |
| Stock | `stock` or `quantity` |
| Price | `price` or `sale_price` |
| Currency | `currency`, default `IRR` |
| Status | `status` or boolean `active` |
| Remote update time | `updated_at` |

Products are matched by accounting mapping first and SKU second. Missing products are created with the configured creator account. Remote products are never deleted automatically.

### Paid invoice push

`POST {base_url}{invoices_path}`

The request includes an `Idempotency-Key` header and a stable payload containing:

- Local invoice ID and number
- Issue/payment timestamps
- Currency and subtotal/discount/tax/shipping/total
- Payment method and reference
- Customer and delivery address
- Item product mapping, SKU, quantity and prices

Accepted external invoice ID fields in the response:

- `data.id` or `id`
- `data.invoice_id` or `invoice_id`
- `data.number` or `number`

The same local invoice is not posted again after a successful mapping.

## Queue And Retry

- Queue name: `ACCOUNTING_QUEUE`
- Product job: `ImportAccountingProductsJob`
- Invoice job: `PushInvoiceToAccountingJob`
- Retry delays: 1, 5 and 15 minutes
- Manual retries create a new sync log linked through `retry_of_id`
- Automatic product sync uses `ACCOUNTING_PRODUCT_SYNC_CRON`

The production scheduler and queue worker must both be active.

## Provider-Specific Adapter

When the accounting vendor documentation is available:

1. Add a provider implementing `AccountingProviderInterface`.
2. Map vendor authentication and payload fields inside that provider.
3. Bind/select the provider without changing checkout, payment, product, or invoice controllers.
4. Add contract tests for real response samples supplied by the vendor.
