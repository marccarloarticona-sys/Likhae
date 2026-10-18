<?php

namespace App\Support;

use Closure;

final class BuyerNameRules
{
    public static function personName(bool $allowSingleInitial = false): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowSingleInitial): void {
            $name = trim((string) $value);
            $pattern = $allowSingleInitial
                ? "/^[\\p{L}\\p{M}]+(?:[ \\x{27}\\x{2019}\\-][\\p{L}\\p{M}]+)*\\.?$/u"
                : "/^[\\p{L}\\p{M}]+(?:[ \\x{27}\\x{2019}\\-][\\p{L}\\p{M}]+)*$/u";
            $validCharacters = preg_match($pattern, $name) === 1;
            $letterCount = preg_match_all('/\\p{L}/u', $name);

            if (! $validCharacters || $letterCount === false || $letterCount < ($allowSingleInitial ? 1 : 2)) {
                $fail($allowSingleInitial
                    ? 'Enter a valid middle name or initial. Use letters, spaces, apostrophes, or hyphens.'
                    : 'Enter a name with at least two letters. Use letters, spaces, apostrophes, or hyphens.');
            }
        };
    }

    public static function extension(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && preg_match("/^[\\p{L}\\p{M}\\d]+(?:[ .\\x{27}\\x{2019}\\-][\\p{L}\\p{M}\\d]+)*\\.?$/u", trim((string) $value)) !== 1) {
                $fail('Enter a valid name suffix, such as Jr., Sr., or III.');
            }
        };
    }
}
