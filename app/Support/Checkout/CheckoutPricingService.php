<?php

namespace App\Support\Checkout;

class CheckoutPricingService
{
    /**
     * @return array{shipping:float,tax:float,total:float}
     */
    public static function calculate(float $subtotalAfterDiscount): array
    {
        $subtotal = self::normalizeMoney($subtotalAfterDiscount);
        $shipping = self::resolveShipping($subtotal);
        $tax = self::resolveTax($subtotal, $shipping);

        return [
            'shipping' => $shipping,
            'tax' => $tax,
            'total' => self::normalizeMoney($subtotal + $shipping + $tax),
        ];
    }

    protected static function resolveShipping(float $subtotalAfterDiscount): float
    {
        $flatFee = self::normalizeMoney((float) config('checkout.shipping.flat_fee', 0));
        $freeThresholdRaw = config('checkout.shipping.free_threshold');
        $freeThreshold = is_numeric($freeThresholdRaw)
            ? self::normalizeMoney((float) $freeThresholdRaw)
            : null;

        if ($flatFee <= 0) {
            return 0.0;
        }

        if ($freeThreshold !== null && $subtotalAfterDiscount >= $freeThreshold) {
            return 0.0;
        }

        return $flatFee;
    }

    protected static function resolveTax(float $subtotalAfterDiscount, float $shipping): float
    {
        $enabled = (bool) config('checkout.tax.enabled', false);
        $ratePercent = max(0, (float) config('checkout.tax.rate_percent', 0));

        if (! $enabled || $ratePercent <= 0) {
            return 0.0;
        }

        $taxableBase = self::normalizeMoney($subtotalAfterDiscount + $shipping);

        return self::normalizeMoney(($taxableBase * $ratePercent) / 100);
    }

    protected static function normalizeMoney(float $amount): float
    {
        return round(max(0, $amount), 2);
    }
}
