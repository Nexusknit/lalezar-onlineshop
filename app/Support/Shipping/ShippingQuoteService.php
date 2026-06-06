<?php

namespace App\Support\Shipping;

use App\Models\Address;
use App\Models\ShippingMethod;
use App\Support\Checkout\CheckoutPricingService;
use Illuminate\Support\Collection;

class ShippingQuoteService
{
    public function options(float $subtotal, ?Address $address = null): Collection
    {
        $methods = ShippingMethod::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ShippingMethod $method): bool => $this->supports($method, $address))
            ->map(fn (ShippingMethod $method): array => $this->quote($method, $subtotal))
            ->values();

        if ($methods->isNotEmpty()) {
            return $methods;
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
        ]]);
    }

    public function selected(float $subtotal, ?Address $address, ?int $methodId): array
    {
        $options = $this->options($subtotal, $address);
        $selected = $methodId
            ? $options->firstWhere('id', $methodId)
            : $options->first();

        abort_if(! $selected, 422, 'Selected shipping method is not available for this address.');

        return $selected;
    }

    private function quote(ShippingMethod $method, float $subtotal): array
    {
        $cost = (float) $method->base_cost;
        if ($method->free_threshold !== null && $subtotal >= (float) $method->free_threshold) {
            $cost = 0;
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
        ];
    }

    private function supports(ShippingMethod $method, ?Address $address): bool
    {
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
