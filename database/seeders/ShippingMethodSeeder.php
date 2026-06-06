<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        ShippingMethod::query()->updateOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'ارسال استاندارد',
                'status' => 'active',
                'base_cost' => (float) config('checkout.shipping.flat_fee', 0),
                'free_threshold' => config('checkout.shipping.free_threshold'),
                'estimated_days_min' => 2,
                'estimated_days_max' => 5,
                'sort_order' => 10,
            ]
        );

        ShippingMethod::query()->updateOrCreate(
            ['code' => 'freight'],
            [
                'name' => 'باربری ویژه کالاهای حجیم',
                'status' => 'inactive',
                'base_cost' => 0,
                'estimated_days_min' => 1,
                'estimated_days_max' => 4,
                'sort_order' => 20,
            ]
        );
    }
}
