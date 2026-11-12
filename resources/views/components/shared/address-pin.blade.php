@props([
    'height' => '220px',
    'class' => '',
    'address' => null,
    'required' => true,
    'preserveAddressFields' => false,
    'allowApproximate' => true,
])

@php
    $latitude = old('latitude', $address?->latitude);
    $longitude = old('longitude', $address?->longitude);
    $precisionColumnReady = $allowApproximate && \Illuminate\Support\Facades\Schema::hasColumn('addresses', 'coordinate_precision');
    $coordinatePrecision = old('coordinate_precision', $address?->coordinate_precision);
    if (filled($latitude) && filled($longitude) && ! filled($coordinatePrecision)) {
        $coordinatePrecision = 'EXACT';
    }
    $addressSignature = $address
        ? hash('sha256', json_encode([
            $address->province_code,
            $address->municipality_code,
            $address->barangay_code,
            $address->house_number,
            $address->street_address,
            $address->landmark,
        ]))
        : '';
@endphp

<section
    class="lk-map-shell lk-address-pin-shell {{ $class }}"
    data-address-pin
    data-geoapify-key="{{ config('services.geoapify.key') }}"
    data-map-token="{{ config('mapbox.public_token') }}"
    data-map-style="{{ config('maps.style') }}"
    data-map-center="{{ json_encode(config('maps.default_center')) }}"
    data-address-signature="{{ $addressSignature }}"
    data-address-pin-required="{{ $required ? 'true' : 'false' }}"
    data-address-pin-preserve-existing="{{ $preserveAddressFields ? 'true' : 'false' }}"
    data-address-pin-allow-approximate="{{ $precisionColumnReady ? 'true' : 'false' }}"
    aria-busy="true"
    style="--lk-map-height: {{ $height }}"
>
    <div class="lk-map-toolbar">
        <div>
            <span class="lk-map-kicker">{{ $required ? 'CONFIRM LOCATION' : 'OPTIONAL LOCATION PIN' }}</span>
            <h2>Set the address pin</h2>
        </div>
        <div class="lk-map-actions">
            <button type="button" class="lk-map-button" data-address-search-submit>Find address</button>
            @if($allowApproximate)
                <button type="button" class="lk-map-button" data-address-approximate-pin @disabled(!$precisionColumnReady)>Use approximate area pin</button>
            @endif
            <button type="button" class="lk-map-button" data-address-use-location>Use device location</button>
        </div>
    </div>
    <div class="lk-address-pin-search">
        <input type="search" data-address-search placeholder="Optional: search a street, landmark, or address" aria-label="Search for an address">
    </div>
    <div class="lk-map-canvas" data-address-pin-canvas role="application" aria-label="Confirm address location"></div>
    <div class="lk-address-pin-footer">
        <p class="lk-map-status" data-address-pin-status aria-live="polite">{{ $required ? ($precisionColumnReady ? 'Search or use device location, then confirm the pin. Choose an approximate area pin if the exact address is not listed.' : 'Search or use device location, then confirm the pin.') : 'Adding an exact pin can help with delivery, but you can skip this during registration.' }}</p>
        @if($allowApproximate && !$precisionColumnReady)
            <p class="lk-map-status">Approximate pins will be available after the coordinate precision database update.</p>
        @endif
        <button type="button" class="lk-map-button" data-address-confirm hidden>Confirm this pin</button>
    </div>
    <input type="hidden" name="latitude" value="{{ $latitude }}" data-address-latitude>
    <input type="hidden" name="longitude" value="{{ $longitude }}" data-address-longitude>
    @if($precisionColumnReady)
        <input type="hidden" name="coordinate_precision" value="{{ $coordinatePrecision }}" data-address-coordinate-precision>
    @endif
</section>
