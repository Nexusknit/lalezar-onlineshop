<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\Brand;
use App\Models\Category;
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

        $this->seedProducts($creator, $brands, $categories);
        $this->seedBlogs($creator, $categories);
        $this->seedTeamMembers($creator);
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
                'status' => 'published',
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
                'status' => 'published',
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
                'status' => 'published',
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
                'status' => 'published',
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
                'status' => 'published',
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
                'status' => 'published',
                'meta' => ['read_time' => '6 min', 'featured' => true],
            ],
            [
                'title' => 'How Smart Dimmers Trim Energy Bills',
                'cover_image' => 'blogs/smart-dimmers.jpg',
                'excerpt' => 'Precision dimming and schedules can reduce utility costs by 18%.',
                'body' => 'Smart dimmers let you map routines to occupancy and daylight data. The result: less glare, happier teams, and measurable savings for both homes and offices.',
                'categories' => ['smart-living'],
                'status' => 'published',
                'meta' => ['read_time' => '4 min'],
            ],
            [
                'title' => 'Facade Lighting Checklist for Boutique Hotels',
                'cover_image' => 'blogs/facade-lighting.jpg',
                'excerpt' => 'Guests decide in 10 seconds whether a hotel feels premium.',
                'body' => 'Uniform wall washers, discreet uplights, and a single accent color keep facades timeless. Add sensors so the show only runs when passersby are nearby.',
                'categories' => ['outdoor-lighting', 'commercial-solutions'],
                'status' => 'published',
                'meta' => ['read_time' => '5 min'],
            ],
            [
                'title' => 'Designing a Productive Studio with Warm Light',
                'cover_image' => 'blogs/studio-light.jpg',
                'excerpt' => 'Artists need accurate color during the day and calm amber hues by night.',
                'body' => 'Pair high CRI track lighting with a warm ambient glow. Task lights with adjustable lenses keep canvases glare-free, while amber night scenes help creators wind down.',
                'categories' => ['indoor-lighting'],
                'status' => 'published',
                'meta' => ['read_time' => '7 min'],
            ],
            [
                'title' => 'Spec’ing Lighting for Hybrid Workplaces',
                'cover_image' => 'blogs/hybrid-work.jpg',
                'excerpt' => 'Adaptive controls balance focus rooms, lounges, and huddle corners.',
                'body' => 'A hybrid office needs scenes for focus, collaboration, and video. Tuneable white fixtures synced with scheduling tools keep employees energized yet calm.',
                'categories' => ['commercial-solutions', 'smart-living'],
                'status' => 'published',
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
