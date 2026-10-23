<?php

namespace App\Support;

use App\Rules\PayoutDestinationNumber;
use Illuminate\Http\Request;

final class PayoutDestinationInput
{
    /** @return array<string, mixed> */
    public function validate(Request $request, bool $includeDefault = false): array
    {
        $rules = [
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Other'],
            'payout_bank_name' => ['nullable', 'string', 'max:120', 'required_if:payout_method,Bank Transfer'],
            'payout_account_name' => ['required', 'string', 'max:150'],
            'payout_account_type' => ['required', 'string', 'in:MOBILE_NUMBER,ACCOUNT_NUMBER'],
            'payout_account_number' => ['required', 'string', 'max:150', new PayoutDestinationNumber(
                $request->input('payout_account_type'),
                $request->input('payout_method'),
            )],
        ];

        if ($includeDefault) {
            $rules['make_default'] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);
        $data['payout_account_number'] = PayoutAccountDetails::normalizeNumber($data['payout_account_number'], $data['payout_account_type']);

        return $data;
    }
}
