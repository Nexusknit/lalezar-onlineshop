# AGENTS.md

## Project Role

This repository is the Laravel backend API for the electrical supplies online shop. It owns authentication, catalog data, admin APIs, user account APIs, checkout, invoices, payments, coupons, comments, tickets, and media upload.

## Stack

- PHP 8.2+
- Laravel 12
- Laravel Sanctum
- MySQL by default
- L5 Swagger annotations
- Kavenegar OTP integration
- Mock gateway for local/test payments
- Shetabit Multipay provider for production payment gateway integration with Zarinpal as the first configured driver

## Local Commands

- Install PHP dependencies: `composer install`
- Install JS dependencies: `npm install`
- Copy env: `cp .env.example .env`
- Generate app key: `php artisan key:generate`
- Migrate and seed: `php artisan migrate --seed`
- Serve API: `php artisan serve`
- Run queue worker locally: `php artisan queue:listen --tries=1`
- Run tests: `composer test` or `php artisan test`
- Format PHP: `vendor/bin/pint`

## Architecture Notes

- API routes are in `routes/api.php`.
- Admin authorization uses controller-level `permission:*` middleware. New admin actions must define permission slugs and seed them in `database/seeders/PermissionSeeder.php`.
- Auth uses OTP challenge storage through cache and token issuance through Sanctum.
- Public catalog payload shaping lives in `app/Support/Loaders/*`.
- Checkout and payment rules are split across:
  - `app/Support/Cart/CartService.php`
  - `app/Support/Checkout/CheckoutPricingService.php`
  - `app/Support/Shipping/ShippingQuoteService.php`
  - `app/Support/Coupons/CouponService.php`
  - `app/Support/Invoices/InvoiceAllocationService.php`
  - `app/Support/Invoices/InvoiceStatusService.php`
  - `app/Support/Payments/PaymentGatewayService.php`
- Payment configuration must stay environment-driven. `APP_URL` is the default gateway callback base, `FRONTEND_URL` is the default payment result URL base, and gateway-specific callback envs should only override those when the domains differ.
- Invoice status transitions must go through `InvoiceStatusService::canTransition(...)`.
- `Invoice` is the order aggregate for this project. Do not introduce a parallel `Order` model without a migration plan for payments, items, coupons, accounting mappings, and shipment ownership.
- Authenticated carts are identified by `user_id`; guest carts use the `X-Cart-Token` header. Login must merge the guest cart into the user cart.
- Checkout must prefer persisted server cart items. Legacy item payloads are only retained for API compatibility and tests.
- Shipping methods may restrict service by state/city and order amount. Final shipping cost must always be recalculated on the backend.
- The current shipment model is one shipment per invoice. Multi-package fulfillment is a later capability and must not be simulated in invoice metadata.
- Stock and coupon allocation changes must stay transactional and lock relevant rows.
- Runtime merchant settings are stored through `app/Support/Settings/StoreSettingService.php`; code must retain config/env fallbacks when a setting has not been saved.
- Admin dashboard, coupon, moderation, support, access-control, invoice, and settings endpoints must remain permission-aware and covered by feature tests.
- Optional accounting integration boundaries live in `app/Support/Accounting`; provider-specific details must implement `AccountingProviderInterface` without entering checkout core.
- Accounting sync must use the outbox/log tables and queued jobs. A provider or queue failure must never revert a successful local payment.

## Conventions

- Keep controllers thin where possible; put reusable business rules in `app/Support`.
- Use request validation for all incoming payloads.
- Do not trust client-supplied totals, shipping, tax, discount, or status.
- Public endpoints should only return active/published entities unless a use case explicitly requires otherwise.
- Admin endpoints must require Sanctum auth and permission middleware.
- Payment credentials, SMS keys, and future accounting credentials must never be persisted in the general settings table.
- Accounting tokens/API keys remain environment-only; base URL, paths and feature switches may be managed as non-secret settings.
- Add or update feature tests for checkout, payment, inventory, authorization, and accounting integration behavior.
- Keep `.env.example` aligned with any new config keys.

## High-Risk Areas

- Payment initiation and callback verification.
- Inventory reservation/release on payment failure and retry.
- Cart merge quantities, shipping eligibility, and shipment/invoice status synchronization.
- Coupon usage limits and release/re-reserve behavior.
- Admin impersonation tokens.
- File upload disk selection and public media URLs.
- Permission seeding and controller middleware slug drift.

## Accounting Integration Guidance

The accounting integration must be optional and disabled by default. It should never block normal storefront operation unless the merchant explicitly enables strict sync mode.

Recommended boundaries:

- Add integration settings/configuration separately from checkout core.
- Use adapter classes per accounting provider.
- Store remote product and invoice identifiers in dedicated mapping tables or explicit external-id fields.
- Use queued sync jobs and an outbox/log table for retries, status, payload snapshots, and error messages.
- Product sync should support importing/updating product name, SKU, stock, price, and status.
- Invoice sync should run after successful payment and be idempotent by invoice number/payment reference.
- Failed sync should be visible in admin, retryable, and must not corrupt local invoice/payment state.

## Before Finishing Backend Work

- Run `composer test` when dependencies and database configuration are available.
- For API contract changes, update OpenAPI annotations and related frontend endpoint/store usage.
- For new permissions, update `PermissionSeeder`, admin UI permission checks, and tests.
