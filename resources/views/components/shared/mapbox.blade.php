@props([
    'markers' => [],
    'center' => null,
    'zoom' => null,
    'title' => 'Location map',
    'height' => '220px',
    'allowUserLocation' => true,
    'riderGps' => false,
    'userLocationTarget' => null,
    'navigation' => false,
    'liveTracking' => true,
    'pinFormId' => null,
    'class' => '',
])

<section
    class="lk-map-shell {{ $class }}"
    data-likhae-map
    data-map-token="{{ config('mapbox.public_token') }}"
    data-map-style="{{ config('mapbox.style') }}"
    data-map-markers="{{ json_encode(array_values($markers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) }}"
    data-map-center="{{ json_encode($center ?: config('mapbox.default_center')) }}"
    data-map-zoom="{{ $zoom ?: config('mapbox.default_zoom') }}"
    data-map-user-target="{{ $userLocationTarget }}"
    data-map-rider-gps="{{ $riderGps ? 'true' : 'false' }}"
    data-map-live="{{ auth()->check() && $liveTracking ? 'true' : 'false' }}"
    data-map-navigation="{{ $navigation ? 'true' : 'false' }}"
    data-map-pin-form="{{ $pinFormId }}"
    style="--lk-map-height: {{ $height }}"
>
    <div class="lk-map-toolbar">
        <div>
            <span class="lk-map-kicker" data-map-location-state>LOCATION MAP</span>
            <h2>{{ $title }}</h2>
        </div>
        <div class="lk-map-actions">
            @if($allowUserLocation)
                <button type="button" class="lk-map-button lk-map-location" data-map-location>{{ $riderGps ? 'Refresh rider GPS current device' : 'Use my location' }}</button>
            @endif
            <button type="button" class="lk-map-button" data-map-rider-sharing hidden>Start rider GPS sharing</button>
            <button type="button" class="lk-map-button lk-map-fullscreen" data-map-fullscreen aria-pressed="false">Open full map</button>
        </div>
    </div>
    <div class="lk-map-canvas" data-map-canvas role="application" aria-label="{{ $title }}"></div>
    @if($navigation)
        <div class="lk-map-navigation" data-map-navigation-panel hidden>
            <div class="lk-map-navigation__head">
                <div>
                    <span class="lk-map-kicker">ACTIVE NAVIGATION</span>
                    <strong data-map-navigation-assignment>Waiting for assignment</strong>
                </div>
                <span class="lk-map-navigation__status" data-map-navigation-status>Waiting for live GPS</span>
            </div>
            <div class="lk-map-navigation__metrics">
                <div><strong data-map-navigation-eta>Unavailable</strong><span>estimated time</span></div>
                <div><strong data-map-navigation-distance>Unavailable</strong><span>road distance</span></div>
                <div><strong data-map-navigation-arrival>Unavailable</strong><span>estimated arrival</span></div>
            </div>
            <div class="lk-map-navigation__destination">
                <strong data-map-navigation-destination>Active destination</strong>
                <span data-map-navigation-address>Waiting for saved destination coordinates.</span>
            </div>
        </div>
    @endif
    <p class="lk-map-status" data-map-status aria-live="polite">Shipment and rider coordinates are shown when available.</p>
</section>
