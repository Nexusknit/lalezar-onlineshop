# Backend Deployment On Linux/cPanel

این راهنما برای deploy بک‌اند Laravel روی هاست Linux/cPanel است. دامنه و نام سایت هنوز نهایی نیستند؛ همه مقادیر وابسته به دامنه باید از `.env` خوانده شوند.

## Server Requirements

- PHP 8.2 یا بالاتر
- Composer 2
- MySQL 8 یا MariaDB سازگار
- PHP extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `session`, `tokenizer`, `xml`
- دسترسی cron برای queue/scheduler
- Document root باید روی پوشه `public` پروژه تنظیم شود.

اگر cPanel اجازه تنظیم document root روی `public` را نمی‌دهد، محتوای `public` باید در `public_html` قرار بگیرد و مسیرهای `../vendor/autoload.php` و `../bootstrap/app.php` در `index.php` مطابق مسیر واقعی پروژه اصلاح شود.

## Production Env Checklist

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
FRONTEND_URL=https://example.com
APP_LOCALE=fa
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=fa_IR

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

# اگر فقط یک فرانت دارید، CORS از FRONTEND_URL مشتق می‌شود.
# CORS_ALLOWED_ORIGINS=https://example.com
# اگر auth cookie/session بین subdomainها لازم شد، این‌ها را متناسب با دامنه واقعی تنظیم کنید.
# SANCTUM_STATEFUL_DOMAINS=example.com,www.example.com
# SESSION_DOMAIN=.example.com

OTP_PROVIDER=kavenegar
OTP_KAVENEGAR_ENABLED=true
KAVENEGAR_API_KEY=...
OTP_KAVENEGAR_SENDER=...
OTP_KAVENEGAR_TEMPLATE=...

PAYMENT_PROVIDER=shetabit
PAYMENT_MOCK_ENABLED=false
PAYMENT_SHETABIT_ENABLED=true
PAYMENT_SHETABIT_DRIVER=zarinpal
PAYMENT_SHETABIT_MERCHANT_ID=...
PAYMENT_SHETABIT_SANDBOX=false
PAYMENT_SHETABIT_CURRENCY=R
# پیش‌فرض‌ها از APP_URL و FRONTEND_URL ساخته می‌شوند؛ فقط در صورت تفاوت override کنید.
# PAYMENT_CALLBACK_BASE_URL=https://api.example.com
# PAYMENT_FRONTEND_CALLBACK_URL=https://example.com/payment/callback

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
FILESYSTEM_DISK=public
```

## Deploy Commands

روی سرور:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

در deployهای بعدی معمولا `key:generate` نباید دوباره اجرا شود.

## Queue And Cron

اگر supervisor در دسترس نیست، cron زیر را در cPanel اضافه کنید:

```bash
* * * * * cd /home/USER/path/to/lalezar-onlineshop && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/path/to/lalezar-onlineshop && /usr/local/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=90 >> /dev/null 2>&1
```

مسیر PHP روی هر هاست ممکن است متفاوت باشد؛ در cPanel بخش Terminal یا Select PHP Version مسیر درست را بررسی کنید.

## Payment Notes

- در production مقدار `PAYMENT_PROVIDER` باید `shetabit` باشد.
- `mock_gateway` فقط برای local/test است و باید با `PAYMENT_MOCK_ENABLED=false` غیرفعال شود.
- برای sandbox زرین‌پال از `PAYMENT_SHETABIT_SANDBOX=true` استفاده کنید.
- callback زرین‌پال باید به مسیر بک‌اند برسد: `APP_URL + /api/payments/callback`، مگر اینکه `PAYMENT_CALLBACK_BASE_URL` جدا تنظیم شده باشد.
- URL نتیجه پرداخت کاربر از `FRONTEND_URL + /payment/callback` ساخته می‌شود، مگر اینکه `PAYMENT_FRONTEND_CALLBACK_URL` جدا تنظیم شده باشد.

## Optional Accounting Integration

اتصال حسابداری در production باید پیش‌فرض خاموش باشد. وقتی در فاز حسابداری اضافه شد، envهای آن باید مستقل از checkout و payment باشند تا خاموش بودن حسابداری هیچ اثری روی ثبت سفارش و پرداخت نگذارد.
