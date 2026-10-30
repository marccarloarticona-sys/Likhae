<?php

namespace App\Support;

final class PhilippineMobileNumber
{
    public static function normalize(string $value): ?string
    {
        $compact = preg_replace('/[\s().-]+/u', '', trim($value)) ?? '';

        if (preg_match('/^09\d{9}$/', $compact)) {
            return '+63'.substr($compact, 1);
        }

        if (preg_match('/^\+?639\d{9}$/', $compact)) {
            return '+63'.substr($compact, -10);
        }

        return null;
    }

    public static function format(string $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return $value;
        }

        $local = '0'.substr($normalized, 3);

        return substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7, 4);
    }

    public static function formatInternational(string $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return $value;
        }

        return substr($normalized, 0, 3).' '.substr($normalized, 3, 3).' '.substr($normalized, 6, 3).' '.substr($normalized, 9, 4);
    }
}
