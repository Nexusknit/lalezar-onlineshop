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
  - `app/Support/Checkout/CheckoutPricingService.php`
  - `app/Support/Coupons/CouponService.php`
  - `app/Support/Invoices/InvoiceAllocationService.php`
  - `app/Support/Invoices/InvoiceStatusService.php`
  - `app/Support/Payments/PaymentGatewayService.php`
- Payment configuration must stay environment-driven. `APP_URL` is the default gateway callback base, `FRONTEND_URL` is the default payment result URL base, and gateway-specific callback envs should only override those when the domains differ.
- Invoice status transitions must go through `InvoiceStatusService::canTransition(...)`.
- Stock and coupon allocation changes must stay transactional and lock relevant rows.

## Conventions

- Keep controllers thin where possible; put reusable business rules in `app/Support`.
- Use request validation for all incoming payloads.
- Do not trust client-supplied totals, shipping, tax, discount, or status.
- Public endpoints should only return active/published entities unless a use case explicitly requires otherwise.
- Admin endpoints must require Sanctum auth and permission middleware.
- Add or update feature tests for checkout, payment, inventory, authorization, and accounting integration behavior.
- Keep `.env.example` aligned with any new config keys.

## High-Risk Areas

- Payment initiation and callback verification.
- Inventory reservation/release on payment failure and retry.
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
