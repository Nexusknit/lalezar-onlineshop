<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Blog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\News;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()->first();

        if (! $creator) {
            $creator = User::factory()->create([
                'name' => 'مدیر محتوا',
                'email' => 'content@lalezarshop.ir',
            ]);
        }

        $categories = $this->seedCategories($creator);
        $brands = $this->seedBrands($creator);
        $this->seedCoupons();

        $this->seedProducts($creator, $brands, $categories);
        $this->seedBlogs($creator, $categories);
        $this->seedNews($creator, $categories);
        $this->seedInvoices();
        $this->seedTeamMembers($creator);
    }

    private function seedCoupons(): void
    {
        $definitions = [
            [
                'code' => 'WELCOME10',
                'title' => 'خوش‌آمد ۱۰ درصد',
                'description' => '۱۰٪ تخفیف برای سفارش‌های عادی.',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'min_subtotal' => 500000,
                'max_discount' => 800000,
                'currency' => 'IRR',
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addYear(),
                'max_uses' => 5000,
                'max_uses_per_user' => 3,
                'status' => 'active',
            ],
            [
                'code' => 'SAVE250K',
                'title' => '۲۵۰ هزار تومان تخفیف',
                'description' => 'تخفیف ثابت برای سفارش‌های بالای حداقل مشخص‌شده.',
                'discount_type' => 'fixed',
                'discount_value' => 250000,
                'min_subtotal' => 2000000,
                'max_discount' => null,
                'currency' => 'IRR',
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addYear(),
                'max_uses' => null,
                'max_uses_per_user' => 1,
                'status' => 'active',
            ],
        ];

        foreach ($definitions as $definition) {
            Coupon::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'discount_type' => $definition['discount_type'],
                    'discount_value' => $definition['discount_value'],
                    'min_subtotal' => $definition['min_subtotal'],
                    'max_discount' => $definition['max_discount'],
                    'currency' => $definition['currency'],
                    'starts_at' => $definition['starts_at'],
                    'ends_at' => $definition['ends_at'],
                    'max_uses' => $definition['max_uses'],
                    'max_uses_per_user' => $definition['max_uses_per_user'],
                    'status' => $definition['status'],
                    'meta' => null,
                ],
            );
        }
    }

    /**
     * @return Collection<string, Category>
     */
    private function seedCategories(User $creator): Collection
    {
        $definitions = [
            [
                'name' => 'نور و روشنایی',
                'slug' => 'lighting-decor',
                'description' => 'انواع چراغ، آویز و تجهیزات نورپردازی برای فضای داخلی و دکوراتیو.',
                'icon' => 'ph-lightbulb-filament-duotone',
                'image_path' => 'categories/lighting-decor.jpg',
                'order_column' => 1,
                'status' => 'active',
                'is_special' => true,
                'children' => [
                    [
                        'name' => 'روشنایی داخلی',
                        'slug' => 'indoor-lighting',
                        'description' => 'چراغ سقفی، دیواری و ایستاده مناسب منزل، دفتر و فروشگاه.',
                        'icon' => 'ph-lamp-duotone',
                        'image_path' => 'categories/indoor-lighting.jpg',
                        'order_column' => 2,
                    ],
                    [
                        'name' => 'روشنایی فضای باز',
                        'slug' => 'outdoor-lighting',
                        'description' => 'چراغ حیاطی، نما و محوطه با مقاومت بالا در شرایط محیطی.',
                        'icon' => 'ph-plant-duotone',
                        'image_path' => 'categories/outdoor-lighting.jpg',
                        'order_column' => 3,
                    ],
                ],
            ],
            [
                'name' => 'خانه هوشمند',
                'slug' => 'smart-living',
                'description' => 'کلید و سنسور هوشمند، کنترل روشنایی و تجهیزات اتوماسیون ساختمان.',
                'icon' => 'ph-plug-charging-duotone',
                'image_path' => 'categories/smart-living.jpg',
                'order_column' => 4,
                'status' => 'active',
                'children' => [
                    [
                        'name' => 'تجهیزات برق صنعتی',
                        'slug' => 'commercial-solutions',
                        'description' => 'پروژکتور، نور صنعتی و تجهیزات برقی مناسب کارگاه، سوله و کارخانه.',
                        'icon' => 'ph-buildings-duotone',
                        'image_path' => 'categories/commercial-lighting.jpg',
                        'order_column' => 5,
                    ],
                ],
            ],
        ];

        $categories = collect();

        foreach ($definitions as $definition) {
            $parent = $this->createCategory($creator, $definition);
            $categories->put($parent->slug, $parent);

            foreach ($definition['children'] ?? [] as $childDefinition) {
                $child = $this->createCategory($creator, $childDefinition, $parent->id);
                $categories->put($child->slug, $child);
            }
        }

        return $categories;
    }

    private function createCategory(User $creator, array $data, ?int $parentId = null): Category
    {
        $slug = $data['slug'] ?? Str::slug($data['name']);
        $summary = $data['summary'] ?? $data['description'] ?? null;
        $content = $data['content'] ?? $data['description'] ?? null;

        return Category::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'creator_id' => $creator->id,
                'parent_id' => $parentId,
                'name' => $data['name'],
                'summary' => $summary,
                'content' => $content,
                'icon' => $data['icon'] ?? null,
                'image_path' => $data['image_path'] ?? null,
                'order_column' => $data['order_column'] ?? 0,
                'status' => $data['status'] ?? 'active',
                'is_special' => $data['is_special'] ?? false,
            ],
        );
    }

    /**
     * @return Collection<string, Brand>
     */
    private function seedBrands(User $creator): Collection
    {
        $definitions = [
            [
                'name' => 'لاله‌زار الکتریک',
                'slug' => 'lalezar-atelier',
                'description' => 'تامین‌کننده چراغ و تجهیزات روشنایی خانگی و پروژه‌ای.',
                'email' => 'sales@lalezarshop.ir',
                'website' => 'https://lalezarshop.ir',
                'location' => 'تهران، بازار لاله‌زار',
                'status' => 'active',
                'logo_path' => 'brands/lalezar-atelier.svg',
                'meta' => ['founded' => 2012, 'featured' => true],
            ],
            [
                'name' => 'آریا نور',
                'slug' => 'nordic-filament',
                'description' => 'برند تخصصی چراغ‌های مدرن، ریلی و نور خطی.',
                'email' => 'info@aryanoor.ir',
                'website' => 'https://aryanoor.ir',
                'location' => 'تهران',
                'status' => 'active',
                'logo_path' => 'brands/nordic-filament.svg',
                'meta' => ['founded' => 2016],
            ],
            [
                'name' => 'توان صنعت نور',
                'slug' => 'aurum-industrial',
                'description' => 'تجهیزات برق صنعتی، پروژکتور و چراغ سوله با توان بالا.',
                'email' => 'industrial@tavannoor.ir',
                'website' => 'https://tavannoor.ir',
                'location' => 'اصفهان',
                'status' => 'active',
                'logo_path' => 'brands/aurum-industrial.svg',
                'meta' => ['founded' => 2010],
            ],
            [
                'name' => 'سبزتاب',
                'slug' => 'verde-outdoor',
                'description' => 'نورپردازی محوطه، باغ و نما با تجهیزات ضدآب.',
                'email' => 'outdoor@sabztab.ir',
                'website' => 'https://sabztab.ir',
                'location' => 'شیراز',
                'status' => 'active',
                'logo_path' => 'brands/verde-outdoor.svg',
                'meta' => ['founded' => 2018],
            ],
            [
                'name' => 'هوشمند پالس ایران',
                'slug' => 'pulse-smart',
                'description' => 'کلید، سنسور و ماژول‌های هوشمندسازی روشنایی و ساختمان.',
                'email' => 'support@pulsesmart.ir',
                'website' => 'https://pulsesmart.ir',
                'location' => 'تهران',
                'status' => 'active',
                'logo_path' => 'brands/pulse-smart.svg',
                'meta' => ['founded' => 2019, 'featured' => false],
            ],
        ];

        $brands = collect();

        foreach ($definitions as $definition) {
            $slug = $definition['slug'] ?? Str::slug($definition['name']);

            $brand = Brand::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'creator_id' => $creator->id,
                    'name' => $definition['name'],
                    'summary' => $definition['summary'] ?? $definition['description'] ?? null,
                    'content' => $definition['content'] ?? $definition['description'] ?? null,
                    'email' => $definition['email'] ?? null,
                    'website' => $definition['website'] ?? null,
                    'location' => $definition['location'] ?? null,
                    'status' => $definition['status'] ?? 'inactive',
                    'logo_path' => $definition['logo_path'] ?? null,
                    'meta' => $definition['meta'] ?? [],
                ],
            );

            $brands->put($slug, $brand);
        }

        return $brands;
    }

    private function seedProducts(User $creator, Collection $brands, Collection $categories): void
    {
        $definitions = [
            [
                'name' => 'چراغ آویز برنجی لونا',
                'slug' => 'luna-brass-pendant-light',
                'brand' => 'lalezar-atelier',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'sku' => 'LZ-PRD-001',
                'description' => 'چراغ آویز برنجی با پخش نور یکنواخت، مناسب میز غذاخوری و نشیمن.',
                'stock' => 35,
                'sold_count' => 140,
                'price' => 7800000,
                'discount_percent' => 12,
                'currency' => 'IRR',
                'status' => 'active',
                'meta' => [
                    'color_temperature' => '3000K',
                    'warranty_months' => 36,
                ],
            ],
            [
                'name' => 'چراغ ایستاده قوسی آریا',
                'slug' => 'aria-arc-floor-lamp',
                'brand' => 'nordic-filament',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'sku' => 'LZ-PRD-002',
                'description' => 'چراغ ایستاده با بدنه فلزی و نور قابل تنظیم برای سالن و اتاق مطالعه.',
                'stock' => 28,
                'sold_count' => 95,
                'price' => 5400000,
                'discount_percent' => 5,
                'currency' => 'IRR',
                'status' => 'active',
                'meta' => [
                    'dimmable' => true,
                    'materials' => ['aluminum', 'oak'],
                ],
            ],
            [
                'name' => 'پروژکتور صنعتی های‌بی مکس',
                'slug' => 'hb-max-industrial-floodlight',
                'brand' => 'aurum-industrial',
                'categories' => ['commercial-solutions'],
                'sku' => 'LZ-PRD-003',
                'description' => 'پروژکتور ۲۰۰ وات صنعتی مناسب سوله، کارگاه و محیط‌های پرتردد.',
                'stock' => 60,
                'sold_count' => 310,
                'price' => 12900000,
                'discount_percent' => 8,
                'currency' => 'IRR',
                'status' => 'active',
                'meta' => [
                    'ip_rating' => 'IP66',
                    'warranty_months' => 48,
                ],
            ],
            [
                'name' => 'چراغ نمای بلید فضای باز',
                'slug' => 'blade-outdoor-wall-light',
                'brand' => 'verde-outdoor',
                'categories' => ['outdoor-lighting', 'lighting-decor'],
                'sku' => 'LZ-PRD-004',
                'description' => 'چراغ خطی نما با پخش نور زاویه‌ای برای نورپردازی دیوار و ساختمان.',
                'stock' => 48,
                'sold_count' => 185,
                'price' => 9100000,
                'discount_percent' => 10,
                'currency' => 'IRR',
                'status' => 'active',
                'meta' => [
                    'beam_angle' => '30°',
                    'ip_rating' => 'IP65',
                ],
            ],
            [
                'name' => 'کیت خانه هوشمند پالس',
                'slug' => 'pulse-smart-home-kit',
                'brand' => 'pulse-smart',
                'categories' => ['smart-living'],
                'sku' => 'LZ-PRD-005',
                'description' => 'شامل کلید هوشمند، سنسور حرکت و هاب مرکزی برای کنترل روشنایی.',
                'stock' => 120,
                'sold_count' => 420,
                'price' => 4600000,
                'discount_percent' => 0,
                'currency' => 'IRR',
                'status' => 'active',
                'meta' => [
                    'protocols' => ['Matter', 'Zigbee'],
                    'app_support' => ['iOS', 'Android'],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $slug = $definition['slug'] ?? Str::slug($definition['name']);
            $brandId = $brands->get($definition['brand'])?->id;

            $product = Product::query()->updateOrCreate(
                ['sku' => $definition['sku']],
                [
                    'slug' => $slug,
                    'creator_id' => $creator->id,
                    'name' => $definition['name'],
                    'sku' => $definition['sku'],
                    'summary' => $definition['summary'] ?? $definition['description'] ?? null,
                    'content' => $definition['content'] ?? $definition['description'] ?? null,
                    'stock' => $definition['stock'] ?? 0,
                    'sold_count' => $definition['sold_count'] ?? 0,
                    'price' => $definition['price'] ?? 0,
                    'discount_percent' => $definition['discount_percent'],
                    'currency' => $definition['currency'] ?? 'IRR',
                    'status' => $definition['status'] ?? 'draft',
                    'meta' => $definition['meta'] ?? [],
                ],
            );

            $product->brands()->sync($brandId ? [$brandId] : []);

            $categoryIds = collect($definition['categories'] ?? [])
                ->map(fn (string $categorySlug) => $categories->get($categorySlug)?->id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $product->categories()->sync($categoryIds);
        }
    }

    private function seedBlogs(User $creator, Collection $categories): void
    {
        $definitions = [
            [
                'title' => 'راهنمای نورپردازی لایه‌ای برای خانه‌های ایرانی',
                'slug' => 'layered-lighting-guide',
                'cover_image' => 'blogs/layered-lighting.jpg',
                'excerpt' => 'ترکیب نور عمومی، موضعی و دکوراتیو باعث زیبایی و کارایی بیشتر فضا می‌شود.',
                'body' => 'برای نورپردازی بهتر منزل، ابتدا نور عمومی را با چراغ سقفی تامین کنید، سپس نور موضعی مانند آباژور و چراغ مطالعه را اضافه کنید و در نهایت از نور دکوراتیو برای ایجاد حس گرما استفاده کنید.',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'status' => 'active',
                'meta' => ['read_time' => '۶ دقیقه', 'featured' => true],
            ],
            [
                'title' => 'چطور کلیدهای هوشمند مصرف برق را کاهش می‌دهند',
                'slug' => 'smart-switch-energy-saving',
                'cover_image' => 'blogs/smart-dimmers.jpg',
                'excerpt' => 'با زمان‌بندی روشنایی و کنترل شدت نور، هزینه برق به شکل ملموس کم می‌شود.',
                'body' => 'کلیدهای دیمر هوشمند امکان زمان‌بندی و تنظیم شدت نور را فراهم می‌کنند. با این روش، در زمان‌های غیرضروری روشنایی کم‌تر شده و مصرف برق ماهانه کاهش پیدا می‌کند.',
                'categories' => ['smart-living'],
                'status' => 'active',
                'meta' => ['read_time' => '۴ دقیقه'],
            ],
            [
                'title' => 'چک‌لیست نورپردازی نما برای ساختمان‌های تجاری',
                'slug' => 'facade-lighting-checklist',
                'cover_image' => 'blogs/facade-lighting.jpg',
                'excerpt' => 'نورپردازی اصولی نما، هویت بصری مجموعه را حرفه‌ای‌تر نمایش می‌دهد.',
                'body' => 'در پروژه‌های تجاری، استفاده از چراغ نما با زاویه پخش مناسب، رنگ نور یکپارچه و سنسور روشنایی باعث جذابیت بیشتر نما و کاهش مصرف انرژی می‌شود.',
                'categories' => ['outdoor-lighting', 'commercial-solutions'],
                'status' => 'active',
                'meta' => ['read_time' => '۵ دقیقه'],
            ],
            [
                'title' => 'نور مناسب کارگاه و استودیو؛ ترکیب نور گرم و کاربردی',
                'slug' => 'workshop-studio-lighting-guide',
                'cover_image' => 'blogs/studio-light.jpg',
                'excerpt' => 'برای محیط کار، هم دقت رنگ مهم است و هم کاهش خستگی چشم.',
                'body' => 'در فضای کارگاهی و استودیویی، نور با شاخص نمود رنگ بالا در کنار نور محیطی ملایم، کیفیت کار را بالا می‌برد و خستگی چشم را کاهش می‌دهد.',
                'categories' => ['indoor-lighting'],
                'status' => 'active',
                'meta' => ['read_time' => '۷ دقیقه'],
            ],
            [
                'title' => 'راهنمای انتخاب روشنایی برای دفاتر مدرن و هیبریدی',
                'slug' => 'modern-office-lighting-guide',
                'cover_image' => 'blogs/hybrid-work.jpg',
                'excerpt' => 'طراحی درست روشنایی، تمرکز کارکنان را در محیط‌های کاری افزایش می‌دهد.',
                'body' => 'در دفاتر کاری جدید، باید برای اتاق جلسات، فضای تمرکز و ناحیه استراحت سناریوی نوری جدا تعریف شود. چراغ‌های قابل تنظیم بهترین گزینه برای این فضاها هستند.',
                'categories' => ['commercial-solutions', 'smart-living'],
                'status' => 'active',
                'meta' => ['read_time' => '۸ دقیقه'],
            ],
        ];

        foreach ($definitions as $index => $definition) {
            $slug = $definition['slug'] ?? Str::slug($definition['title']);

            $blog = Blog::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'creator_id' => $creator->id,
                    'title' => $definition['title'],
                    'cover_image' => $definition['cover_image'] ?? null,
                    'summary' => $definition['summary'] ?? $definition['excerpt'] ?? null,
                    'content' => $definition['content'] ?? $definition['body'] ?? null,
                    'status' => $definition['status'] ?? 'draft',
                    'published_at' => $definition['published_at'] ?? now()->subDays($index + 1),
                    'meta' => $definition['meta'] ?? [],
                ],
            );

            $categoryIds = collect($definition['categories'] ?? [])
                ->map(fn (string $categorySlug) => $categories->get($categorySlug)?->id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $blog->categories()->sync($categoryIds);
        }
    }

    private function seedNews(User $creator, Collection $categories): void
    {
        $definitions = [
            [
                'headline' => 'افتتاح شوروم جدید لاله‌زار در تهران',
                'slug' => 'lalezar-showroom-tehran-opening',
                'summary' => 'شوروم جدید با تمرکز بر روشنایی خانگی، اداری و صنعتی شروع به کار کرد.',
                'content' => 'در این شوروم، نمونه‌های متنوع چراغ، تجهیزات برق و محصولات هوشمند برای پروژه‌های ساختمانی و مصرف خانگی در دسترس مشتریان قرار گرفته است.',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'status' => 'active',
                'meta' => [
                    'priority' => 'high',
                    'cover_image' => 'news/showroom-tehran.jpg',
                ],
            ],
            [
                'headline' => 'پشتیبانی نسخه جدید محصولات هوشمند پالس از استاندارد Matter',
                'slug' => 'pulse-smart-matter-support',
                'summary' => 'نسخه جدید سیستم هوشمند پالس، هماهنگی بیشتری با برندهای مختلف دارد.',
                'content' => 'با این بروزرسانی، اتصال تجهیزات روشنایی هوشمند سریع‌تر شده و مدیریت سناریوهای نوری در اپلیکیشن پالس ساده‌تر انجام می‌شود.',
                'categories' => ['smart-living'],
                'status' => 'special',
                'meta' => [
                    'priority' => 'medium',
                    'cover_image' => 'news/matter-compatibility.jpg',
                ],
            ],
            [
                'headline' => 'گسترش خدمات برق صنعتی و بهینه‌سازی مصرف به سه استان جدید',
                'slug' => 'industrial-energy-optimization-expansion',
                'summary' => 'خدمات پروژه‌ای لاله‌زار در اصفهان، فارس و مازندران توسعه یافت.',
                'content' => 'در این طرح، کسب‌وکارها می‌توانند برای بازطراحی روشنایی، کاهش مصرف برق و نوسازی تجهیزات فرسوده از تیم فنی لاله‌زار خدمات دریافت کنند.',
                'categories' => ['commercial-solutions'],
                'status' => 'active',
                'meta' => [
                    'priority' => 'medium',
                    'cover_image' => 'news/retrofit-program.jpg',
                ],
            ],
            [
                'headline' => 'رونمایی از سری جدید تجهیزات روشنایی فضای باز',
                'slug' => 'new-outdoor-lighting-series-launch',
                'summary' => 'محصولات جدید فضای باز با مقاومت بالا در برابر شرایط محیطی معرفی شدند.',
                'content' => 'در این سری، چراغ‌های محوطه‌ای ضدآب، چراغ نمای خطی و تجهیزات کم‌مصرف ویژه فضای باز عرضه شده است.',
                'categories' => ['outdoor-lighting'],
                'status' => 'active',
                'meta' => [
                    'priority' => 'low',
                    'cover_image' => 'news/outdoor-preview.jpg',
                ],
            ],
        ];

        foreach ($definitions as $index => $definition) {
            $slug = $definition['slug'] ?? Str::slug($definition['headline']);

            $news = News::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'creator_id' => $creator->id,
                    'headline' => $definition['headline'],
                    'summary' => $definition['summary'] ?? null,
                    'content' => $definition['content'] ?? null,
                    'status' => $definition['status'] ?? 'draft',
                    'published_at' => $definition['published_at'] ?? now()->subDays($index + 1),
                    'meta' => $definition['meta'] ?? [],
                ],
            );

            $categoryIds = collect($definition['categories'] ?? [])
                ->map(fn (string $categorySlug) => $categories->get($categorySlug)?->id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $news->categories()->sync($categoryIds);
        }
    }

    private function seedInvoices(): void
    {
        $customer = User::query()->updateOrCreate(
            ['email' => 'customer@example.com'],
            [
                'name' => 'مشتری نمونه',
                'phone' => '09123334455',
                'password' => bcrypt('password'),
                'accessibility' => true,
            ],
        );

        $city = \App\Models\City::query()
            ->orderBy('id')
            ->first();

        if (! $city) {
            return;
        }

        $address = Address::query()->updateOrCreate(
            [
                'user_id' => $customer->id,
                'label' => 'آدرس اصلی',
            ],
            [
                'city_id' => $city->id,
                'recipient_name' => $customer->name,
                'phone' => $customer->phone,
                'street_line1' => 'تهران، خیابان لاله‌زار، پلاک ۱۲',
                'street_line2' => 'واحد ۴',
                'postal_code' => '1958833471',
                'building' => 'ساختمان مهر',
                'unit' => '4',
                'is_default' => true,
                'meta' => ['type' => 'business', 'label_fa' => 'دفتر مرکزی'],
            ],
        );

        $products = Product::query()
            ->whereIn('sku', ['LZ-PRD-001', 'LZ-PRD-003', 'LZ-PRD-005'])
            ->get()
            ->keyBy('sku');

        if ($products->isEmpty()) {
            return;
        }

        $welcomeCoupon = Coupon::query()->where('code', 'WELCOME10')->first();
        $saveCoupon = Coupon::query()->where('code', 'SAVE250K')->first();

        $definitions = [
            [
                'number' => 'INV-DEMO-1001',
                'status' => 'paid',
                'currency' => 'IRR',
                'issued_at' => now()->subDays(5),
                'due_at' => now()->subDays(2),
                'lines' => [
                    ['sku' => 'LZ-PRD-001', 'quantity' => 1],
                    ['sku' => 'LZ-PRD-005', 'quantity' => 2],
                ],
                'coupon' => $welcomeCoupon,
                'payment' => [
                    'method' => 'gateway',
                    'status' => 'paid',
                    'reference' => 'PAY-DEMO-1001',
                    'paid_at' => now()->subDays(5),
                ],
            ],
            [
                'number' => 'INV-DEMO-1002',
                'status' => 'pending',
                'currency' => 'IRR',
                'issued_at' => now()->subDays(1),
                'due_at' => now()->addDays(2),
                'lines' => [
                    ['sku' => 'LZ-PRD-003', 'quantity' => 1],
                ],
                'coupon' => $saveCoupon,
                'payment' => [
                    'method' => 'bank-transfer',
                    'status' => 'pending',
                    'reference' => 'PAY-DEMO-1002',
                    'paid_at' => null,
                ],
            ],
            [
                'number' => 'INV-DEMO-1003',
                'status' => 'failed',
                'currency' => 'IRR',
                'issued_at' => now()->subDays(3),
                'due_at' => now()->subDay(),
                'lines' => [
                    ['sku' => 'LZ-PRD-001', 'quantity' => 1],
                ],
                'coupon' => null,
                'payment' => [
                    'method' => 'gateway',
                    'status' => 'failed',
                    'reference' => 'PAY-DEMO-1003',
                    'paid_at' => null,
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $lineItems = collect($definition['lines'])
                ->map(function (array $line) use ($products): ?array {
                    /** @var Product|null $product */
                    $product = $products->get($line['sku']);
                    if (! $product) {
                        return null;
                    }

                    $quantity = max(1, (int) ($line['quantity'] ?? 1));
                    $unitPrice = (float) $product->price;

                    return [
                        'product' => $product,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total' => round($quantity * $unitPrice, 2),
                    ];
                })
                ->filter()
                ->values();

            if ($lineItems->isEmpty()) {
                continue;
            }

            $subtotal = (float) $lineItems->sum('total');
            /** @var Coupon|null $coupon */
            $coupon = $definition['coupon'] ?? null;
            $discount = $coupon ? $coupon->calculateDiscount($subtotal) : 0.0;
            $total = round(max(0, $subtotal - $discount), 2);

            $invoice = Invoice::query()->updateOrCreate(
                ['number' => $definition['number']],
                [
                    'user_id' => $customer->id,
                    'address_id' => $address->id,
                    'coupon_id' => $coupon?->id,
                    'status' => $definition['status'],
                    'currency' => $definition['currency'],
                    'subtotal' => $subtotal,
                    'tax' => 0,
                    'discount' => $discount,
                    'total' => $total,
                    'issued_at' => $definition['issued_at'],
                    'due_at' => $definition['due_at'],
                    'meta' => [
                        'seeded' => true,
                        'channel' => 'demo-seeder',
                    ],
                ],
            );

            Item::query()->where('invoice_id', $invoice->id)->delete();
            foreach ($lineItems as $lineItem) {
                /** @var Product $lineProduct */
                $lineProduct = $lineItem['product'];

                Item::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $lineProduct->id,
                    'name' => $lineProduct->name,
                    'description' => $lineProduct->description,
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['unit_price'],
                    'total' => $lineItem['total'],
                    'meta' => [
                        'sku' => $lineProduct->sku,
                        'product_status' => $lineProduct->status,
                    ],
                ]);
            }

            $payment = $definition['payment'] ?? null;
            if (is_array($payment) && ! empty($payment['reference'])) {
                Payment::query()->updateOrCreate(
                    [
                        'invoice_id' => $invoice->id,
                        'reference' => $payment['reference'],
                    ],
                    [
                        'user_id' => $customer->id,
                        'amount' => $total,
                        'currency' => $definition['currency'],
                        'method' => $payment['method'] ?? null,
                        'status' => $payment['status'] ?? 'pending',
                        'meta' => ['seeded' => true],
                        'paid_at' => $payment['paid_at'] ?? null,
                    ],
                );
            }

            if ($coupon) {
                CouponUsage::query()->updateOrCreate(
                    [
                        'coupon_id' => $coupon->id,
                        'invoice_id' => $invoice->id,
                    ],
                    [
                        'user_id' => $customer->id,
                        'discount_amount' => $discount,
                        'used_at' => $definition['issued_at'],
                    ],
                );
            }
        }

        Coupon::query()
            ->whereIn('code', ['WELCOME10', 'SAVE250K'])
            ->each(function (Coupon $coupon): void {
                $coupon->update([
                    'used_count' => $coupon->usages()->count(),
                ]);
            });
    }

    private function seedTeamMembers(User $creator): void
    {
        $definitions = [
            [
                'name' => 'یاسمن فراهانی',
                'title' => 'مدیر خلاقیت',
                'photo_path' => 'team/jasmine-farahani.jpg',
                'bio' => 'هدایت‌گر طراحی نور پروژه‌های فروشگاهی و فضاهای تجاری.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/jasmine-farahani',
                    'instagram' => 'https://www.instagram.com/jasmine.light',
                ],
                'order_column' => 1,
                'status' => 'active',
                'meta' => ['languages' => ['fa', 'en']],
            ],
            [
                'name' => 'نوید مرادی',
                'title' => 'مدیر فنی',
                'photo_path' => 'team/navid-moradi.jpg',
                'bio' => 'مسئول بررسی فنی محصولات، کنترل کیفیت و استاندارد نصب تجهیزات.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/navid-moradi',
                    'github' => 'https://github.com/navid-moradi',
                ],
                'order_column' => 2,
                'status' => 'active',
                'meta' => ['certifications' => ['LC'], 'languages' => ['fa', 'en', 'de']],
            ],
            [
                'name' => 'سارا خادم',
                'title' => 'مدیر بازرگانی',
                'photo_path' => 'team/sara-khadem.jpg',
                'bio' => 'مدیریت تامین کالا و انتخاب محصولات متناسب با نیاز بازار برق و روشنایی.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/sara-khadem',
                ],
                'order_column' => 3,
                'status' => 'active',
                'meta' => ['focus' => 'همکاری‌های خرده‌فروشی'],
            ],
            [
                'name' => 'کامران واحدی',
                'title' => 'مدیر ارتباط با مشتریان',
                'photo_path' => 'team/kamran-vahedi.jpg',
                'bio' => 'پاسخ‌گویی تخصصی به مشتریان پروژه‌ای و پیگیری نیازهای پس از خرید.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/kamran-vahedi',
                    'twitter' => 'https://twitter.com/kamranvahedi',
                ],
                'order_column' => 4,
                'status' => 'active',
                'meta' => ['nps' => 74],
            ],
            [
                'name' => 'النا یوسفی',
                'title' => 'استراتژیست برند',
                'photo_path' => 'team/elena-yousefi.jpg',
                'bio' => 'برنامه‌ریزی کمپین‌ها، محتوا و ارتباطات برند لاله‌زار با بازار هدف.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/elena-yousefi',
                    'behance' => 'https://www.behance.net/elenayousefi',
                ],
                'order_column' => 5,
                'status' => 'active',
                'meta' => ['focus' => 'سردبیری محتوا'],
            ],
        ];

        foreach ($definitions as $definition) {
            TeamMember::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'creator_id' => $creator->id,
                    'title' => $definition['title'] ?? null,
                    'photo_path' => $definition['photo_path'] ?? null,
                    'bio' => $definition['bio'] ?? null,
                    'social_links' => $definition['social_links'] ?? [],
                    'order_column' => $definition['order_column'] ?? 0,
                    'status' => $definition['status'] ?? 'active',
                    'meta' => $definition['meta'] ?? [],
                ],
            );
        }
    }
}
