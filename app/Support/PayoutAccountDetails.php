<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

final class PayoutAccountDetails
{
    public const MOBILE_NUMBER = 'MOBILE_NUMBER';

    public const ACCOUNT_NUMBER = 'ACCOUNT_NUMBER';

    public static function inferType(?string $type, ?string $method, ?string $number): string
    {
        if (in_array($type, [self::MOBILE_NUMBER, self::ACCOUNT_NUMBER], true)) {
            return $type;
        }

        if (in_array($method, ['GCash', 'Maya'], true)) {
            return self::MOBILE_NUMBER;
        }

        if ($method === 'Bank Transfer') {
            return self::ACCOUNT_NUMBER;
        }

        return self::isValidNumber((string) $number, self::MOBILE_NUMBER)
            ? self::MOBILE_NUMBER
            : self::ACCOUNT_NUMBER;
    }

    public static function typeFor(?Model $destination): string
    {
        if (! $destination) {
            return self::ACCOUNT_NUMBER;
        }

        return self::inferType(
            $destination->payout_account_type,
            $destination->payout_method,
            $destination->payout_account_number,
        );
    }

    public static function typeLabel(string $type): string
    {
        return $type === self::MOBILE_NUMBER ? 'Mobile number' : 'Account number';
    }

    public static function formatHint(string $type): string
    {
        return $type === self::MOBILE_NUMBER
            ? 'Use 09XX XXX XXXX or +63 9XX XXX XXXX.'
            : 'Use 6 to 20 digits. Spaces and dashes are accepted.';
    }

    public static function methodError(?string $method, ?string $type): ?string
    {
        if (in_array($method, ['GCash', 'Maya'], true) && $type !== self::MOBILE_NUMBER) {
            return 'GCash and Maya destinations must use a mobile number.';
        }

        if ($method === 'Bank Transfer' && $type !== self::ACCOUNT_NUMBER) {
            return 'Bank transfers must use an account number.';
        }

        return null;
    }

    public static function isValidNumber(string $value, ?string $type): bool
    {
        $compact = preg_replace('/[\s().-]+/', '', trim($value)) ?? '';

        if ($type === self::MOBILE_NUMBER) {
            return preg_match('/^(?:09\d{9}|\+639\d{9}|639\d{9})$/', $compact) === 1;
        }

        if ($type === self::ACCOUNT_NUMBER) {
            return preg_match('/^\d{6,20}$/', $compact) === 1;
        }

        return false;
    }

    public static function normalizeNumber(string $value, string $type): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';

        if ($type === self::MOBILE_NUMBER && str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }

    public static function formatNumber(?string $value, string $type): string
    {
        $value = trim((string) $value);
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($type === self::MOBILE_NUMBER) {
            if (str_starts_with($digits, '63') && strlen($digits) === 12) {
                $digits = '0'.substr($digits, 2);
            } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
                $digits = '0'.$digits;
            }

            if (preg_match('/^09\d{9}$/', $digits) === 1) {
                return substr($digits, 0, 4).' '.substr($digits, 4, 3).' '.substr($digits, 7, 4);
            }

            return $value;
        }

        $compact = preg_replace('/[\s().-]+/', '', $value) ?? '';
        if (strlen($digits) >= 6 && strlen($digits) <= 20 && preg_match('/^\d+$/', $compact) === 1) {
            return implode(' ', str_split($digits, 4));
        }

        return $value;
    }
}
