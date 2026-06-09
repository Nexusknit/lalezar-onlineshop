<?php

namespace App\Support\Shipping;

use App\Models\Address;
use App\Models\ShippingMethod;
use App\Support\Checkout\CheckoutPricingService;
use Illuminate\Support\Collection;

class ShippingQuoteService
{
    public function options(float $subtotal, ?Address $address = null, int $weightGrams = 0): Collection
    {
        $activeMethods = ShippingMethod::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $methods = $activeMethods
            ->filter(fn (ShippingMethod $method): bool => $this->supports($method, $address, $weightGrams))
            ->map(fn (ShippingMethod $method): array => $this->quote($method, $subtotal, $weightGrams))
            ->values();

        if ($methods->isNotEmpty()) {
            return $methods;
        }

        if ($activeMethods->isNotEmpty()) {
            return collect();
        }

        $legacy = CheckoutPricingService::calculate($subtotal);

        return collect([[
            'id' => null,
            'code' => 'standard',
            'name' => 'ارسال استاندارد',
            'cost' => $legacy['shipping'],
            'tax' => $legacy['tax'],
            'total' => $legacy['total'],
            'estimated_days_min' => null,
            'estimated_days_max' => null,
            'total_weight_grams' => max(0, $weightGrams),
            'weight_surcharge' => 0,
        ]]);
    }

    public function selected(float $subtotal, ?Address $address, ?int $methodId, int $weightGrams = 0): array
    {
        $options = $this->options($subtotal, $address, $weightGrams);
        $selected = $methodId
            ? $options->firstWhere('id', $methodId)
            : $options->first();

        abort_if(! $selected, 422, 'Selected shipping method is not available for this address.');

        return $selected;
    }

    private function quote(ShippingMethod $method, float $subtotal, int $weightGrams): array
    {
        $weightSurcharge = $weightGrams > 0
            ? ceil($weightGrams / 1000) * (float) $method->cost_per_kg
            : 0;
        $cost = (float) $method->base_cost + $weightSurcharge;
        if ($method->free_threshold !== null && $subtotal >= (float) $method->free_threshold) {
            $cost = 0;
            $weightSurcharge = 0;
        }

        $pricing = CheckoutPricingService::calculate($subtotal, $cost);

        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'cost' => round(max(0, $cost), 2),
            'tax' => $pricing['tax'],
            'total' => $pricing['total'],
            'estimated_days_min' => $method->estimated_days_min,
            'estimated_days_max' => $method->estimated_days_max,
            'total_weight_grams' => max(0, $weightGrams),
            'weight_surcharge' => round(max(0, $weightSurcharge), 2),
        ];
    }

    private function supports(ShippingMethod $method, ?Address $address, int $weightGrams): bool
    {
        if ($method->max_weight_grams !== null && $weightGrams > (int) $method->max_weight_grams) {
            return false;
        }

        if (! $address) {
            return empty($method->state_ids) && empty($method->city_ids);
        }

        $cityIds = array_map('intval', (array) $method->city_ids);
        $stateIds = array_map('intval', (array) $method->state_ids);

        if ($cityIds !== [] && ! in_array((int) $address->city_id, $cityIds, true)) {
            return false;
        }

        $stateId = (int) ($address->city?->state_id ?? 0);

        return $stateIds === [] || in_array($stateId, $stateIds, true);
    }
}
