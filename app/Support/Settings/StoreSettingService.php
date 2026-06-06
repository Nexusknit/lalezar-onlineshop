<?php

namespace App\Support\Settings;

use App\Models\Setting;

class StoreSettingService
{
    public static function value(string $group, string $key, mixed $default = null): mixed
    {
        $setting = Setting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    public static function boolean(string $group, string $key, bool $default): bool
    {
        $value = self::value($group, $key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function number(string $group, string $key, float $default): float
    {
        $value = self::value($group, $key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
