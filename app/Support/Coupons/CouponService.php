<?php

namespace App\Support\Coupons;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    public static function resolveValidCoupon(
        string $code,
        float $subtotal,
        ?string $currency = null,
        ?User $user = null,
        bool $lockForUpdate = false
    ): Coupon {
        $normalized = self::normalizeCode($code);

        if ($normalized === '') {
            self::fail('Coupon code is required.');
        }

        $query = Coupon::query()->where('code', $normalized);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var Coupon|null $coupon */
        $coupon = $query->first();

        if (! $coupon) {
            self::fail('Coupon code is invalid.');
        }

        self::validateCoupon($coupon, $subtotal, $currency, $user);

        return $coupon;
    }

    public static function validateCoupon(
        Coupon $coupon,
        float $subtotal,
        ?string $currency = null,
        ?User $user = null
    ): void {
        if (! $coupon->isActive()) {
            self::fail('Coupon is not active.');
        }

        if ($subtotal < max(0, (float) $coupon->min_subtotal)) {
            self::fail('Order subtotal is lower than coupon minimum amount.');
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            self::fail('Coupon usage limit has been reached.');
        }

        if ($currency && $coupon->currency) {
            if (Str::upper($currency) !== Str::upper($coupon->currency)) {
                self::fail('Coupon currency does not match cart currency.');
            }
        }

        if ($user && $coupon->max_uses_per_user !== null) {
            $usedByUser = CouponUsage::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->count();

            if ($usedByUser >= $coupon->max_uses_per_user) {
                self::fail('You have already reached your usage limit for this coupon.');
            }
        }
    }

    protected static function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'coupon_code' => [$message],
        ]);
    }
}
