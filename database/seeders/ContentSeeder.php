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
                'name' => 'Content Manager',
                'email' => 'content@example.com',
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
                'title' => 'Welcome 10%',
                'description' => '10% off for regular purchases.',
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
                'title' => 'Save 250K',
                'description' => 'Fixed discount for high-value carts.',
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
                'name' => 'Lighting & Decor',
                'description' => 'Statement fixtures and artisan shades for elevated interiors.',
                'icon' => 'ph-lightbulb-filament-duotone',
                'image_path' => 'categories/lighting-decor.jpg',
                'order_column' => 1,
                'status' => 'active',
                'is_special' => true,
                'children' => [
                    [
                        'name' => 'Indoor Lighting',
                        'description' => 'Pendant, wall, and floor lamps made for residential spaces.',
                        'icon' => 'ph-lamp-duotone',
                        'image_path' => 'categories/indoor-lighting.jpg',
                        'order_column' => 2,
                    ],
                    [
                        'name' => 'Outdoor Lighting',
                        'description' => 'Weather-proof fixtures and architectural highlights.',
                        'icon' => 'ph-plant-duotone',
                        'image_path' => 'categories/outdoor-lighting.jpg',
                        'order_column' => 3,
                    ],
                ],
            ],
            [
                'name' => 'Smart Living',
                'description' => 'Connected devices that blend lighting with automation.',
                'icon' => 'ph-plug-charging-duotone',
                'image_path' => 'categories/smart-living.jpg',
                'order_column' => 4,
                'status' => 'active',
                'children' => [
                    [
                        'name' => 'Commercial Solutions',
                        'description' => 'High-output systems and energy audits for offices and retail.',
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
                'name' => 'Lalezar Atelier',
                'description' => 'Signature Persian-crafted fixtures for luxury interiors.',
                'email' => 'atelier@lalezar.test',
                'website' => 'https://atelier.lalezar.test',
                'location' => 'Tehran, Iran',
                'status' => 'active',
                'logo_path' => 'brands/lalezar-atelier.svg',
                'meta' => ['founded' => 2012, 'featured' => true],
            ],
            [
                'name' => 'Nordic Filament',
                'description' => 'Scandinavian-inspired minimal fixtures with sustainable finishes.',
                'email' => 'hello@nordicfilament.test',
                'website' => 'https://nordicfilament.test',
                'location' => 'Copenhagen, Denmark',
                'status' => 'active',
                'logo_path' => 'brands/nordic-filament.svg',
                'meta' => ['founded' => 2016],
            ],
            [
                'name' => 'Aurum Industrial',
                'description' => 'High-output warehouse lighting and industrial-grade controls.',
                'email' => 'sales@aurumindustrial.test',
                'website' => 'https://aurumindustrial.test',
                'location' => 'Dubai, UAE',
                'status' => 'active',
                'logo_path' => 'brands/aurum-industrial.svg',
                'meta' => ['founded' => 2010],
            ],
            [
                'name' => 'Verde Outdoor',
                'description' => 'Landscape and facade systems built to survive desert climates.',
                'email' => 'studio@verdeoutdoor.test',
                'website' => 'https://verdeoutdoor.test',
                'location' => 'Shiraz, Iran',
                'status' => 'active',
                'logo_path' => 'brands/verde-outdoor.svg',
                'meta' => ['founded' => 2018],
            ],
            [
                'name' => 'Pulse Smart',
                'description' => 'IoT dimmers, sensors, and voice-ready smart home kits.',
                'email' => 'support@pulsesmart.test',
                'website' => 'https://pulsesmart.test',
                'location' => 'Berlin, Germany',
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
                'name' => 'Luna Brass Pendant',
                'brand' => 'lalezar-atelier',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'sku' => 'LZ-PRD-001',
                'description' => 'Hand-spun brass pendant with a double-diffuser optic for warm dining light.',
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
                'name' => 'Nordic Arc Floor Lamp',
                'brand' => 'nordic-filament',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'sku' => 'LZ-PRD-002',
                'description' => 'Matte graphite floor lamp with dim-to-warm LED strip.',
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
                'name' => 'Aurum Highbay Max',
                'brand' => 'aurum-industrial',
                'categories' => ['commercial-solutions'],
                'sku' => 'LZ-PRD-003',
                'description' => '200W industrial highbay with motion telemetry for warehouses.',
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
                'name' => 'Verde Facade Blade',
                'brand' => 'verde-outdoor',
                'categories' => ['outdoor-lighting', 'lighting-decor'],
                'sku' => 'LZ-PRD-004',
                'description' => 'Directional wall washer made for sandstone or travertine cladding.',
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
                'name' => 'Pulse Sync Starter Kit',
                'brand' => 'pulse-smart',
                'categories' => ['smart-living'],
                'sku' => 'LZ-PRD-005',
                'description' => 'Smart dimmer, motion sensor, and gateway kit with Matter support.',
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
                ['slug' => $slug],
                [
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
                'title' => 'Layered Lighting Ideas for Modern Apartments',
                'cover_image' => 'blogs/layered-lighting.jpg',
                'excerpt' => 'Use accent and task lights together to keep compact spaces flexible.',
                'body' => 'Layering fixtures lets residents change the mood without rewiring. Start with a soft cove glow, add pendants for drama, and finish with portable lamps for late-night reading.',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'status' => 'active',
                'meta' => ['read_time' => '6 min', 'featured' => true],
            ],
            [
                'title' => 'How Smart Dimmers Trim Energy Bills',
                'cover_image' => 'blogs/smart-dimmers.jpg',
                'excerpt' => 'Precision dimming and schedules can reduce utility costs by 18%.',
                'body' => 'Smart dimmers let you map routines to occupancy and daylight data. The result: less glare, happier teams, and measurable savings for both homes and offices.',
                'categories' => ['smart-living'],
                'status' => 'active',
                'meta' => ['read_time' => '4 min'],
            ],
            [
                'title' => 'Facade Lighting Checklist for Boutique Hotels',
                'cover_image' => 'blogs/facade-lighting.jpg',
                'excerpt' => 'Guests decide in 10 seconds whether a hotel feels premium.',
                'body' => 'Uniform wall washers, discreet uplights, and a single accent color keep facades timeless. Add sensors so the show only runs when passersby are nearby.',
                'categories' => ['outdoor-lighting', 'commercial-solutions'],
                'status' => 'active',
                'meta' => ['read_time' => '5 min'],
            ],
            [
                'title' => 'Designing a Productive Studio with Warm Light',
                'cover_image' => 'blogs/studio-light.jpg',
                'excerpt' => 'Artists need accurate color during the day and calm amber hues by night.',
                'body' => 'Pair high CRI track lighting with a warm ambient glow. Task lights with adjustable lenses keep canvases glare-free, while amber night scenes help creators wind down.',
                'categories' => ['indoor-lighting'],
                'status' => 'active',
                'meta' => ['read_time' => '7 min'],
            ],
            [
                'title' => 'Spec’ing Lighting for Hybrid Workplaces',
                'cover_image' => 'blogs/hybrid-work.jpg',
                'excerpt' => 'Adaptive controls balance focus rooms, lounges, and huddle corners.',
                'body' => 'A hybrid office needs scenes for focus, collaboration, and video. Tuneable white fixtures synced with scheduling tools keep employees energized yet calm.',
                'categories' => ['commercial-solutions', 'smart-living'],
                'status' => 'active',
                'meta' => ['read_time' => '8 min'],
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
                'headline' => 'Lalezar Opens New Experience Showroom in Tehran',
                'summary' => 'The new showroom blends interactive lighting zones with design consultations.',
                'content' => 'Located in Elahieh, the space features hospitality mockups, residential scenes, and a rapid sample lab for architects. The showroom is open daily except Fridays.',
                'categories' => ['lighting-decor', 'indoor-lighting'],
                'status' => 'active',
                'meta' => [
                    'priority' => 'high',
                    'cover_image' => 'news/showroom-tehran.jpg',
                ],
            ],
            [
                'headline' => 'Pulse Smart Line Receives Matter 1.3 Compatibility',
                'summary' => 'Firmware updates unlock smoother onboarding for mixed-brand smart homes.',
                'content' => 'The update includes faster pairing, improved scene sync, and lower standby draw. Existing customers can update through the Pulse mobile app with no extra hardware.',
                'categories' => ['smart-living'],
                'status' => 'special',
                'meta' => [
                    'priority' => 'medium',
                    'cover_image' => 'news/matter-compatibility.jpg',
                ],
            ],
            [
                'headline' => 'Commercial Retrofit Program Expanded to Three New Provinces',
                'summary' => 'Energy-audit and installation services now cover Isfahan, Fars, and Mazandaran.',
                'content' => 'The expanded program targets retail chains and offices needing lower operating costs. Participating clients receive ROI projections and phased upgrade plans.',
                'categories' => ['commercial-solutions'],
                'status' => 'active',
                'meta' => [
                    'priority' => 'medium',
                    'cover_image' => 'news/retrofit-program.jpg',
                ],
            ],
            [
                'headline' => 'Seasonal Outdoor Collection Preview Announced',
                'summary' => 'Design partners can request early access to new outdoor fixtures.',
                'content' => 'The preview includes corrosion-resistant bollards, adjustable facade blades, and low-voltage garden markers tuned for warm climates.',
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
                'name' => 'Demo Customer',
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
                'label' => 'Head Office',
            ],
            [
                'city_id' => $city->id,
                'recipient_name' => $customer->name,
                'phone' => $customer->phone,
                'street_line1' => 'No. 12, Andarzgoo Blvd',
                'street_line2' => 'Unit 4',
                'postal_code' => '1958833471',
                'building' => 'Mehr Building',
                'unit' => '4',
                'is_default' => true,
                'meta' => ['type' => 'business'],
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
                'name' => 'Jasmine Farahani',
                'title' => 'Creative Director',
                'photo_path' => 'team/jasmine-farahani.jpg',
                'bio' => 'Leads lighting narratives for hospitality and concept retail projects across the region.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/jasmine-farahani',
                    'instagram' => 'https://www.instagram.com/jasmine.light',
                ],
                'order_column' => 1,
                'status' => 'active',
                'meta' => ['languages' => ['fa', 'en']],
            ],
            [
                'name' => 'Navid Moradi',
                'title' => 'Head of Engineering',
                'photo_path' => 'team/navid-moradi.jpg',
                'bio' => 'Oversees photometric testing, supply chain audits, and installation guides.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/navid-moradi',
                    'github' => 'https://github.com/navid-moradi',
                ],
                'order_column' => 2,
                'status' => 'active',
                'meta' => ['certifications' => ['LC'], 'languages' => ['fa', 'en', 'de']],
            ],
            [
                'name' => 'Sara Khadem',
                'title' => 'Merchandising Lead',
                'photo_path' => 'team/sara-khadem.jpg',
                'bio' => 'Curates seasonal collections and builds tactile in-store experiences.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/sara-khadem',
                ],
                'order_column' => 3,
                'status' => 'active',
                'meta' => ['focus' => 'retail partnerships'],
            ],
            [
                'name' => 'Kamran Vahedi',
                'title' => 'Customer Success Manager',
                'photo_path' => 'team/kamran-vahedi.jpg',
                'bio' => 'Ensures designers and contractors have up-to-date specs and training.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/kamran-vahedi',
                    'twitter' => 'https://twitter.com/kamranvahedi',
                ],
                'order_column' => 4,
                'status' => 'active',
                'meta' => ['nps' => 74],
            ],
            [
                'name' => 'Elena Yousefi',
                'title' => 'Brand Strategist',
                'photo_path' => 'team/elena-yousefi.jpg',
                'bio' => 'Connects campaigns, events, and editorial content for the Lalezar community.',
                'social_links' => [
                    'linkedin' => 'https://www.linkedin.com/in/elena-yousefi',
                    'behance' => 'https://www.behance.net/elenayousefi',
                ],
                'order_column' => 5,
                'status' => 'active',
                'meta' => ['focus' => 'editorial'],
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
