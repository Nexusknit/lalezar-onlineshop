<?php

namespace App\Support\Phone;

use Illuminate\Validation\ValidationException;

class IranPhoneNormalizer
{
    public static function normalize(mixed $phone): ?string
    {
        if ($phone === null || is_array($phone) || is_object($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '98')) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        if (! preg_match('/^09\d{9}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    public static function normalizeOrFail(mixed $phone, string $field = 'phone'): string
    {
        $normalized = self::normalize($phone);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => ['Phone number format is invalid.'],
            ]);
        }

        return $normalized;
    }

    public static function normalizeNullableOrFail(mixed $phone, string $field = 'phone'): ?string
    {
        if ($phone === null || (! is_array($phone) && ! is_object($phone) && trim((string) $phone) === '')) {
            return null;
        }

        return self::normalizeOrFail($phone, $field);
    }
}
