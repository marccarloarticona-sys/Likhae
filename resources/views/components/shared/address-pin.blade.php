@props([
    'height' => '220px',
    'class' => '',
    'address' => null,
    'required' => true,
])

@php
    $latitude = old('latitude', $address?->latitude);
    $longitude = old('longitude', $address?->longitude);
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
    style="--lk-map-height: {{ $height }}"
>
    <div class="lk-map-toolbar">
        <div>
            <span class="lk-map-kicker">{{ $required ? 'CONFIRM LOCATION' : 'OPTIONAL LOCATION PIN' }}</span>
            <h2>Pin the exact address</h2>
        </div>
        <div class="lk-map-actions">
            <button type="button" class="lk-map-button" data-address-search-submit>Find address</button>
            <button type="button" class="lk-map-button" data-address-use-location>Use device location</button>
        </div>
    </div>
    <div class="lk-address-pin-search">
        <input type="search" data-address-search placeholder="Optional: search a street, landmark, or address" aria-label="Search for an address">
    </div>
    <div class="lk-map-canvas" data-address-pin-canvas role="application" aria-label="Confirm address location"></div>
    <div class="lk-address-pin-footer">
        <p class="lk-map-status" data-address-pin-status aria-live="polite">{{ $required ? 'Search or use device location, then drag the marker and confirm it.' : 'Adding an exact pin can help with delivery, but you can skip this during registration.' }}</p>
        <button type="button" class="lk-map-button" data-address-confirm hidden>Confirm this pin</button>
    </div>
    <input type="hidden" name="latitude" value="{{ $latitude }}" data-address-latitude>
    <input type="hidden" name="longitude" value="{{ $longitude }}" data-address-longitude>
</section>
