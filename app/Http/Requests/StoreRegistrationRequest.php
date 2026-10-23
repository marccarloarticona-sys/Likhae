<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\Logistics\LogisticsCenter;
use App\Support\RiderVehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $google = $this->hasSession()
            ? $this->session()->get('google_buyer_registration')
            : null;
        $googleEmail = is_array($google) ? ($google['email'] ?? null) : null;

        $this->merge([
            'account_type' => $googleEmail ? 'buyer' : strtolower(trim((string) $this->input('account_type'))),
            'email' => mb_strtolower(trim((string) ($googleEmail ?: $this->input('email')))),
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'middle_initial' => filled($this->input('middle_initial')) ? trim((string) $this->input('middle_initial')) : null,
            'contact_number' => $this->normalizeContactNumber((string) $this->input('contact_number')),
            'sex' => strtolower(trim((string) $this->input('sex'))),
            'plate_number' => filled($this->input('plate_number'))
                ? mb_strtoupper(trim((string) $this->input('plate_number')))
                : null,
            'drivers_license_number' => filled($this->input('drivers_license_number'))
                ? mb_strtoupper(trim((string) $this->input('drivers_license_number')))
                : null,
            'dti_registration_number' => filled($this->input('dti_registration_number'))
                ? trim((string) $this->input('dti_registration_number'))
                : null,
            'business_name' => filled($this->input('business_name'))
                ? trim((string) $this->input('business_name'))
                : null,
            'seller_type' => strtolower(trim((string) $this->input('seller_type'))),
            'tin' => filled($this->input('tin')) ? trim((string) $this->input('tin')) : null,
            'business_registration_number' => filled($this->input('business_registration_number'))
                ? mb_strtoupper(trim((string) $this->input('business_registration_number')))
                : null,
        ]);
    }

    public function rules(): array
    {
        $type = strtolower((string) $this->input('account_type'));
        $isSeller = $type === 'seller';
        $isLogistics = $type === 'logistics';
        $isRider = $type === 'rider';

        $businessRegistrationRules = [Rule::requiredIf($isLogistics), 'nullable', 'string', 'max:100'];
        if ($isSeller) {
            $businessRegistrationRules[] = Rule::unique('seller_profiles', 'business_registration_number');
        } elseif ($isLogistics) {
            $businessRegistrationRules[] = 'regex:/^[A-Za-z]{2}\d{9}$/';
            $businessRegistrationRules[] = Rule::unique('logistics_centers', 'business_registration_number');
        }

        $businessNameRules = [Rule::requiredIf($isSeller || $isLogistics), 'nullable', 'string'];
        $businessNameRules[] = $isSeller ? 'min:3' : 'min:1';
        $businessNameRules[] = $isSeller ? 'max:30' : 'max:200';
        if ($isSeller) {
            $businessNameRules[] = Rule::unique('seller_profiles', 'business_name');
        } elseif ($isLogistics) {
            $businessNameRules[] = Rule::unique('logistics_centers', 'business_name');
        }

        $tinRules = [Rule::requiredIf($isSeller || $isLogistics), 'nullable', 'string', 'regex:/^\d{3}-\d{3}-\d{3}-\d{3}$/'];
        if ($isSeller || $isLogistics) {
            $tinRules[] = Rule::unique('seller_profiles', 'tin');
            $tinRules[] = Rule::unique('logistics_centers', 'tin');
        }

        return [
            'account_type' => ['required', Rule::in(['buyer', 'seller', 'logistics', 'rider'])],
            'first_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL][\pL\s\x{27}.\x{2D}]*$/u'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'last_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL][\pL\s\x{27}.\x{2D}]*$/u'],
            'sex' => ['required', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            // Mobile registration consumes this one-time token after the
            // email verification endpoint has confirmed ownership.
            'email_verification_token' => ['sometimes', 'nullable', 'string', 'size:64'],
            'contact_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^(?:\+63|0)9\d{9}$/',
                Rule::unique('users', 'contact_number'),
            ],
            'birthday' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'age' => ['required', 'integer', 'between:18,120'],

            'region' => ['required', 'string', 'max:150'],
            'region_code' => ['required', 'string', 'max:50'],
            'province' => ['required', 'string', 'max:150'],
            'province_code' => ['required', 'string', 'max:50'],
            'municipality' => ['required', 'string', 'max:150'],
            'municipality_code' => ['required', 'string', 'max:50'],
            'barangay' => ['required', 'string', 'max:150'],
            'barangay_code' => ['required', 'string', 'max:50'],
            'postal_code' => ['required', 'string', 'regex:/^\d{4}$/'],
            'house_number' => ['nullable', 'string', 'max:100'],
            'street' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            // Location is not required to submit a registration. If a client
            // provides it, still validate the coordinate range.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'business_name' => $businessNameRules,
            'line_of_business' => [Rule::requiredIf($isSeller), 'nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('archived_at')],
            'seller_type' => [Rule::requiredIf($isSeller), 'nullable', Rule::in(['individual', 'sole_proprietorship', 'corporation'])],
            'tin' => $tinRules,
            'business_registration_number' => $businessRegistrationRules,
            'dti_registration_number' => ['nullable', 'string', 'max:100', Rule::unique('logistics_centers', 'dti_registration_number')],

            'target_logistics_center_id' => [
                Rule::requiredIf($isRider),
                'nullable',
                'integer',
                Rule::exists('logistics_centers', 'id')->where('status', 'ACTIVE'),
            ],
            'vehicle_type' => [Rule::requiredIf($isRider), 'nullable', Rule::in(RiderVehicleType::acceptedValues())],
            'plate_number' => [
                Rule::requiredIf($isRider),
                'nullable',
                'string',
                'max:50',
                Rule::unique('rider_profiles', 'plate_number'),
            ],
            'drivers_license_number' => [
                Rule::requiredIf($isRider),
                'nullable',
                'string',
                'max:100',
                Rule::unique('rider_profiles', 'drivers_license_number'),
            ],

            'valid_id' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'business_permit' => [Rule::requiredIf($isLogistics), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'dti_certificate' => [Rule::requiredIf($isSeller && $this->input('seller_type') === 'sole_proprietorship'), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'sec_certificate' => [Rule::requiredIf(($isSeller && $this->input('seller_type') === 'corporation') || $isLogistics), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'bir_form_2303' => [Rule::requiredIf(($isSeller && in_array($this->input('seller_type'), ['sole_proprietorship', 'corporation'], true)) || $isLogistics), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'or_cr' => [Rule::requiredIf($isRider), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'drivers_license' => [Rule::requiredIf($isRider), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'store_avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'store_banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],

            'password' => ['required', 'confirmed', 'max:72', Password::min(8)->mixedCase()->numbers()->symbols()],
            'terms_acknowledged' => ['required', 'accepted'],
            'privacy_acknowledged' => ['required', 'accepted'],
            'policy_acceptances' => ['required', 'array', 'min:2'],
            'policy_acceptances.*' => ['required', 'string', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('birthday') || ! $this->filled('age')) {
                return;
            }

            try {
                $calculated = Carbon::parse($this->input('birthday'))->age;
            } catch (\Throwable) {
                return;
            }

            if ((int) $this->input('age') !== $calculated) {
                $validator->errors()->add('age', 'The age must match the selected birthday.');
            }
        }, function (Validator $validator): void {
            if (strtolower((string) $this->input('account_type')) !== 'rider') {
                return;
            }

            $provinceCode = trim((string) $this->input('province_code'));
            $municipalityCode = trim((string) $this->input('municipality_code'));
            $centerId = (int) $this->input('target_logistics_center_id');

            if ($provinceCode === '' || $municipalityCode === '' || $centerId <= 0) {
                return;
            }

            $eligible = LogisticsCenter::query()
                ->eligibleForRiderAddress($provinceCode, $municipalityCode)
                ->whereKey($centerId)
                ->exists();

            if (! $eligible) {
                $validator->errors()->add(
                    'target_logistics_center_id',
                    'Select an active logistics hub in the same province and municipality as your registered address.',
                );
            }
        }];
    }

    public function accountTypeConstant(): string
    {
        return match (strtolower((string) $this->input('account_type'))) {
            'seller' => User::TYPE_SELLER,
            'logistics' => User::TYPE_LOGISTICS,
            'rider' => User::TYPE_RIDER,
            default => User::TYPE_BUYER,
        };
    }

    private function normalizeContactNumber(string $value): string
    {
        $number = preg_replace('/[^0-9+]/', '', trim($value)) ?? '';

        if (preg_match('/^9\d{9}$/', $number)) {
            return '+63'.$number;
        }

        return $number;
    }
}
