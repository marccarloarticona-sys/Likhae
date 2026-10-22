<?php

namespace App\Rules;

use App\Support\PayoutAccountDetails;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PayoutDestinationNumber implements ValidationRule
{
    private readonly ?string $type;

    private readonly ?string $method;

    public function __construct(mixed $type, mixed $method)
    {
        $this->type = is_string($type) ? $type : null;
        $this->method = is_string($method) ? $method : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($this->type, [PayoutAccountDetails::MOBILE_NUMBER, PayoutAccountDetails::ACCOUNT_NUMBER], true)) {
            return;
        }

        if ($message = PayoutAccountDetails::methodError($this->method, $this->type)) {
            $fail($message);

            return;
        }

        if (! is_string($value) || ! PayoutAccountDetails::isValidNumber($value, $this->type)) {
            $fail($this->type === PayoutAccountDetails::MOBILE_NUMBER
                ? 'Enter a Philippine mobile number as 09XX XXX XXXX or +63 9XX XXX XXXX.'
                : 'Enter an account number with 6 to 20 digits. Spaces and dashes are allowed.');
        }
    }
}
