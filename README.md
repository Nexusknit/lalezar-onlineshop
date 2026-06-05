# Lalezar Online Shop Backend

بک‌اند فروشگاه اینترنتی لوازم الکتریکی با Laravel 12 پیاده‌سازی شده و API اصلی فرانت‌اند Nuxt را فراهم می‌کند.

## Stack

- PHP 8.2+
- Laravel 12
- Laravel Sanctum برای احراز هویت API
- SQLite برای اجرای local و test
- MySQL برای محیط production پیشنهادی
- Mock gateway برای توسعه و Shetabit/driver زرین‌پال برای پرداخت واقعی
- Kavenegar به عنوان provider قابل تنظیم OTP

## امکانات فعلی

- ورود با OTP و صدور token
- API عمومی محصولات، دسته‌بندی‌ها، برندها، خبرها، بلاگ، تیم، موقعیت‌ها و جست‌وجو
- بررسی سبد خرید، اعمال کوپن، checkout و ثبت invoice
- پرداخت mock و callback پرداخت
- پنل کاربر شامل پروفایل، آدرس‌ها، علاقه‌مندی‌ها، دیدگاه‌ها، تیکت‌ها و فاکتورها
- پنل مدیریت شامل کاربران، محصولات، دسته‌بندی‌ها، برندها، خبرها، بلاگ‌ها، نقش‌ها، مجوزها، دیدگاه‌ها، تیکت‌ها، استان/شهر و فاکتورها
- تست‌های Feature برای قراردادهای API، احراز هویت، فلو خرید کاربر، فیلتر محصولات و seed smoke

## راه‌اندازی local

```bash
cp .env.example .env
touch database/database.sqlite
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

برای اجرای local بدون MySQL این مقادیر در `.env` کافی هستند:

```dotenv
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://127.0.0.1:3000
APP_LOCALE=fa
APP_FAKER_LOCALE=fa_IR
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/lalezar-onlineshop/database/database.sqlite
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
PAYMENT_PROVIDER=mock_gateway
PAYMENT_MOCK_ENABLED=true
```

## تست‌ها

```bash
composer test
php artisan test
php artisan test --filter UserCommerceFlowTest
```

`phpunit.xml` تست‌ها را با SQLite in-memory، queue sync و session/cache array اجرا می‌کند؛ بنابراین تست‌ها نباید به دیتابیس local یا سرویس خارجی وابسته باشند.

## پرداخت و OTP

- در local از `PAYMENT_PROVIDER=mock_gateway` استفاده شود.
- پرداخت واقعی از provider `shetabit` و driver زرین‌پال استفاده می‌کند. برای production مقدارهای `PAYMENT_PROVIDER=shetabit`، `PAYMENT_SHETABIT_ENABLED=true` و `PAYMENT_MOCK_ENABLED=false` لازم است.
- `PAYMENT_CALLBACK_BASE_URL` پیش‌فرض از `APP_URL` خوانده می‌شود و `PAYMENT_FRONTEND_CALLBACK_URL` از `FRONTEND_URL` + مسیر `/payment/callback` ساخته می‌شود؛ فقط برای دامنه متفاوت override شوند.
- مقدارهای مبلغ فعلی پروژه بر اساس `IRR` هستند؛ بنابراین برای Shetabit مقدار `PAYMENT_SHETABIT_CURRENCY=R` نگه داشته شود مگر اینکه schema قیمت‌ها به تومان تغییر کند.
- OTP فقط با Kavenegar پشتیبانی می‌شود؛ اتصال واقعی فقط با `OTP_KAVENEGAR_ENABLED=true` و کلیدهای Kavenegar فعال شود.
- ایمیل در مسیرهای فعلی اجباری نیست و می‌تواند روی `MAIL_MAILER=log` بماند. کاربرد production آن برای رسید سفارش، اعلان تیکت/پشتیبانی، هشدارهای مدیریتی و ایمیل‌های عملیاتی آینده است.

## Deploy روی Linux/cPanel

راهنمای deploy و envهای production در `DEPLOYMENT_CPANEL.md` ثبت شده است.

## امنیت و production hardening

- logout واقعی از مسیر `POST /api/auth/logout` انجام می‌شود و token فعلی Sanctum را revoke می‌کند.
- tokenهای عادی با `SANCTUM_TOKEN_EXPIRATION` و tokenهای impersonation با `SANCTUM_IMPERSONATION_EXPIRATION` محدود می‌شوند.
- شماره‌های موبایل ایران به فرم `09xxxxxxxxx` normalize می‌شوند و تلاش‌های اشتباه OTP با `OTP_VERIFY_MAX_ATTEMPTS` و `OTP_LOCK_MINUTES` قفل می‌شوند.
- seed ادمین با `ADMIN_SEED_*` کنترل می‌شود و دیگر رمز public مثل `password` ندارد.
- عملیات mutating پنل admin در جدول `audit_logs` ثبت می‌شود.
- upload فقط روی diskهای تعریف‌شده در `UPLOAD_ALLOWED_DISKS` مجاز است.
- `payment.meta` در JSON مخفی است تا توکن callback پرداخت از پاسخ‌های عمومی نشت نکند.
- CORS باید در production فقط به دامنه‌های واقعی frontend محدود شود.

## اتصال اختیاری حسابداری

اتصال به نرم‌افزار حسابداری باید optional بماند و نباید checkout، پرداخت، ثبت invoice یا نمایش سفارش را در حالت غیرفعال مختل کند. طراحی پیشنهادی برای فازهای بعدی:

- تنظیمات admin برای فعال/غیرفعال کردن integration
- adapter جدا برای هر نرم‌افزار حسابداری
- sync کالا، موجودی و قیمت به صورت job/queue
- ارسال invoice پرداخت‌شده به حسابداری بعد از تایید پرداخت
- ثبت وضعیت sync و خطاها در جدول جدا
- عدم rollback سفارش موفق سایت در صورت خطای سرویس حسابداری

## فرمان‌های مفید

```bash
php artisan route:list --path=api
php artisan migrate:fresh --seed
php artisan config:clear
php artisan cache:clear
php artisan test
```

## وضعیت فاز صفر

وضعیت واقعی اجرای فاز صفر در فایل root پروژه ثبت شده است:

`/Users/ehsntb/Documents/MyProjects/elektrika/PHASE_0_STATUS.md`
