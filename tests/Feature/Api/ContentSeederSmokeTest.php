<?php

namespace Tests\Feature\Api;

use App\Models\CouponUsage;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\News;
use Database\Seeders\ContentSeeder;
use Database\Seeders\StateCitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_seeder_populates_news_and_invoices(): void
    {
        $this->seed(StateCitySeeder::class);
        $this->seed(ContentSeeder::class);

        $this->assertGreaterThanOrEqual(1, News::query()->whereIn('status', ['active', 'special'])->count());
        $this->assertGreaterThanOrEqual(1, Invoice::query()->count());
        $this->assertGreaterThanOrEqual(1, Item::query()->count());
        $this->assertGreaterThanOrEqual(1, CouponUsage::query()->count());
    }
}
