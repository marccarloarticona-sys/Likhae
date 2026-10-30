@props([
    'address' => null,
    'fieldClass' => 'rounded-xl border border-stone-300 px-3 py-2.5 text-sm',
    'labelClass' => 'grid gap-1 text-sm',
    'wrapperClass' => '',
    'includeDetails' => true,
    'streetRequired' => true,
    'allowedProvinceCode' => null,
    'allowedMunicipalityCode' => null,
])

@php
    $provinceCode = old('province_code', $address?->province_code);
    $regionCode = old('region_code', preg_match('/^\d{2}/', (string) $provinceCode) ? substr((string) $provinceCode, 0, 2).'00000000' : '');
@endphp

<div class="{{ $wrapperClass }}" data-postal-address data-postal-base="{{ \Illuminate\Support\Str::beforeLast(route('address.philippines.regions', [], false), '/regions') }}" data-postal-allowed-province-code="{{ $allowedProvinceCode }}" data-postal-allowed-municipality-code="{{ $allowedMunicipalityCode }}">
    <input type="hidden" name="region_code" value="{{ $regionCode }}" data-postal-region-code>
    <label class="{{ $labelClass }}"><span>Region</span>
        <select required name="region_name" class="{{ $fieldClass }}" data-postal-region data-old-value="{{ old('region_name') }}" data-old-code="{{ $regionCode }}"><option value="">Loading regions...</option></select>
    </label>
    <input type="hidden" name="province_code" value="{{ $provinceCode }}" data-postal-province-code>
    <label class="{{ $labelClass }}"><span>Province</span>
        <select required name="province_name" disabled class="{{ $fieldClass }}" data-postal-province data-old-value="{{ old('province_name', $address?->province_name) }}" data-old-code="{{ $provinceCode }}"><option value="">Select province</option></select>
    </label>
    <input type="hidden" name="municipality_code" value="{{ old('municipality_code', $address?->municipality_code) }}" data-postal-municipality-code>
    <label class="{{ $labelClass }}"><span>City / Municipality</span>
        <select required name="municipality_name" disabled class="{{ $fieldClass }}" data-postal-municipality data-old-value="{{ old('municipality_name', $address?->municipality_name) }}" data-old-code="{{ old('municipality_code', $address?->municipality_code) }}"><option value="">Select municipality / city</option></select>
    </label>
    <input type="hidden" name="barangay_code" value="{{ old('barangay_code', $address?->barangay_code) }}" data-postal-barangay-code>
    <label class="{{ $labelClass }}"><span>Barangay</span>
        <select required name="barangay_name" disabled class="{{ $fieldClass }}" data-postal-barangay data-old-value="{{ old('barangay_name', $address?->barangay_name) }}" data-old-code="{{ old('barangay_code', $address?->barangay_code) }}"><option value="">Select barangay</option></select>
    </label>
    @if ($includeDetails)
        <label class="{{ $labelClass }}"><span>Postal code</span>
            <input readonly name="postal_code" value="{{ old('postal_code', $address?->postal_code) }}" placeholder="Auto-filled when available" class="{{ $fieldClass }}" data-postal-code>
        </label>
        <label class="{{ $labelClass }}"><span>Street / Purok</span>
            <input {{ $streetRequired ? 'required' : '' }} name="street_address" value="{{ old('street_address', $address?->street_address) }}" class="{{ $fieldClass }}">
        </label>
        <label class="{{ $labelClass }}"><span>House / Unit Number</span>
            <input name="house_number" value="{{ old('house_number', $address?->house_number) }}" class="{{ $fieldClass }}">
        </label>
        <label class="{{ $labelClass }}"><span>Building / Subdivision, Landmark / Additional Details</span>
            <input name="landmark" value="{{ old('landmark', $address?->landmark) }}" class="{{ $fieldClass }}">
        </label>
        <p class="text-xs text-stone-500" data-postal-status aria-live="polite">Postal code will fill after you select a barangay.</p>
    @endif
</div>
