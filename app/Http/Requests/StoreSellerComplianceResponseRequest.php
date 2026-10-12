<?php

namespace App\Http\Requests;

use App\Models\Admin\SellerComplianceCase;
use Illuminate\Foundation\Http\FormRequest;

class StoreSellerComplianceResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('case');
        $seller = $this->user()?->sellerProfile;

        return $this->user()?->isAccountType('SELLER') === true
            && $seller
            && $case instanceof SellerComplianceCase
            && (int) $case->seller_profile_id === (int) $seller->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'explanation' => trim((string) $this->input('explanation', '')),
            'corrective_action' => trim((string) $this->input('corrective_action', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'explanation' => ['required', 'string', 'min:10', 'max:10000'],
            'corrective_action' => ['required', 'string', 'min:5', 'max:10000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }
}
