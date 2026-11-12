@extends('layouts.seller.app')
@section('title','Seller Pickup')
@section('active','logistics')
@section('subtitle','Request Seller pickups and follow center acceptance and Rider collection.')
@section('content')
<div class="sl-page sl-pickup-page">
    @if(session('status'))<div class="sl-card mb-4 border border-green-200 bg-green-50 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-card mb-4 border border-red-200 bg-red-50 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Seller Pickup</span><h2>Pickup requests</h2><p>Follow automatic Logistics matching, center acceptance, and Rider collection progress here.</p></div></div>
    @if(!$pickupRequestSchemaReady)
        <section class="sl-card border border-amber-200 bg-amber-50 text-sm text-amber-900">Seller pickup requests are unavailable until their database schema is installed.</section>
    @else
        @if(!$pickupCenterSelectionReady)
            <section class="sl-card border border-amber-200 bg-amber-50 text-sm text-amber-900">Seller-selected Logistics pickup will be available after the additive pickup-center migration is installed.</section>
        @else
        <section class="sl-card sl-pickup-card" data-seller-pickup-request-form>
            <header class="sl-card-head"><div><span class="sl-eyebrow">Seller Pickup</span><h2>Request a pickup</h2><p>Request one pickup for 1–10 ready parcels. Each SellerOrder keeps one waybill, with separate parcel scan codes.</p></div></header>
            <div class="sl-pickup-card-body">
            @if(!$automaticPickupMatchingReady)
                <p class="sl-pickup-guidance">Only an approved Logistics center with coverage for the selected pickup address can receive this request.</p>
            @endif
            @if($pickupAddresses->isEmpty())
                <p class="sl-pickup-state-note is-warning">Add a pickup address before requesting a Rider. <a class="font-semibold underline" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">Manage pickup addresses</a>.</p>
            @elseif($readyPickupParcels->isEmpty())
                <p class="sl-pickup-state-note">Mark packed parcels Ready for Pickup on the Orders page, then select them here. No other ready parcels are waiting for a request.</p>
            @elseif($pickupCenterOptions->isEmpty() && !$automaticPickupMatchingReady)
                <p class="sl-pickup-state-note is-warning">No pickup request can be sent because no active, approved Logistics center covers these pinned pickup addresses. Confirm the map pin or contact Logistics support.</p>
            @else
                <form method="POST" action="{{ route('seller.logistics.pickup-requests.store') }}" data-pickup-address-settings-url="{{ route('seller.account', ['tab' => 'pickup-addresses']) }}" class="sl-pickup-form">
                    @csrf
                    <input type="hidden" name="seller_selected_fallback" value="0" data-fallback-route-flag>
                    <div class="sl-pickup-fields">
                    <label class="sl-pickup-field"><span class="sl-pickup-label">Pickup address</span>
                        <select name="pickup_address_id" data-pickup-address required>
                            <option value="">Select a saved pickup address</option>
                            @foreach($pickupAddresses as $address)
                                <option value="{{ $address->id }}" data-lat="{{ $address->latitude }}" data-lng="{{ $address->longitude }}" data-local-center="{{ in_array((int) $address->id, $pickupAddressLocalCenterIds, true) ? '1' : '0' }}" @disabled(!is_numeric($address->latitude) || !is_numeric($address->longitude)) @selected((string)$pickupAddress?->id === (string)$address->id)>
                                    {{ $address->label ?: 'Pickup address' }}{{ $address->is_default ? ' · Default' : '' }} — {{ $address->formatted() }}{{ $address->coordinate_precision === 'APPROXIMATE' ? ' · Approximate area pin' : '' }}{{ !is_numeric($address->latitude) || !is_numeric($address->longitude) ? ' · Confirm map pin first' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <small class="sl-pickup-field-help"><a class="font-semibold underline" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">Manage pickup addresses</a>.</small>
                        <small data-pickup-pin-missing hidden class="sl-pickup-pin-notice">This saved pickup address has no confirmed map pin. <a data-pickup-pin-link class="font-semibold underline" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">Open this pickup address</a> to confirm its pin or add another pinned address.</small>
                        @if(!$automaticPickupMatchingReady)
                            <small class="sl-pickup-field-help">Only centers with approved coverage for this exact pickup barangay are listed.</small>
                            <small data-pickup-no-center hidden class="sl-pickup-pin-notice">No eligible Logistics center covers this pickup address. Choose another saved address or contact Logistics support.</small>
                        @endif
                    </label>
                    @if($automaticPickupMatchingReady && !$sellerSelectedFallbackHubsReady)
                        <p class="sl-pickup-state-note is-warning" data-fallback-schema-warning hidden>Seller-selected routing for uncovered locations will be available after its additive routing migration is installed. Covered locations keep automatic routing.</p>
                    @endif
                    @if($automaticPickupMatchingReady)
                        <p class="sl-pickup-state-note is-warning" data-fallback-centers-missing hidden>No active approved Logistics center with a confirmed map location is available for this fallback route.</p>
                    @endif
                    <label class="sl-pickup-field" data-pickup-center-field @if($automaticPickupMatchingReady && !$pickupCenterChoiceNeeded) hidden @endif>
                        <span class="sl-pickup-label">{{ $automaticPickupMatchingReady ? 'Preferred Logistics center (nearest first)' : 'Logistics center' }}</span>
                        <select name="preferred_logistics_center_id" data-pickup-center @required(!$automaticPickupMatchingReady || ($pickupCenterChoiceNeeded && $pickupCenterOptions->isNotEmpty())) @disabled($automaticPickupMatchingReady && !$pickupCenterChoiceNeeded)>
                            <option value="">{{ $pickupCenterOptions->isEmpty() ? 'No approved centers available right now' : 'Choose a nearby Logistics center' }}</option>
                            @foreach($pickupCenterOptions as $centerOption)
                                <option value="{{ $centerOption->id }}" data-lat="{{ $centerOption->address?->latitude }}" data-lng="{{ $centerOption->address?->longitude }}" data-pickup-address-ids='@json($centerOption->authorized_pickup_address_ids ?? [])' @selected((string)old('preferred_logistics_center_id') === (string)$centerOption->id)>
                                    {{ $centerOption->business_name }} — {{ $centerOption->address?->formatted() ?: 'Location unavailable' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    </div>
                    <div class="sl-table-wrap sl-pickup-table-wrap"><table class="sl-table sl-pickup-table"><thead><tr><th>Select</th><th>Parcel scan code / product</th><th>Buyer destination hub</th><th>SellerOrder</th><th>Order waybill</th></tr></thead><tbody>
                        @foreach($readyPickupParcels as $parcel)
                            <tr>
                                <td><input type="checkbox" name="shipment_ids[]" value="{{ $parcel->id }}" data-pickup-request-parcel data-buyer-local-center="{{ $parcel->buyer_has_local_logistics_center ? '1' : '0' }}" data-current-logistics-center="{{ $parcel->logistics_center_id ?: $parcel->serviceArea?->logistics_center_id }}" data-buyer-lat="{{ $parcel->sellerOrder?->order?->address?->latitude }}" data-buyer-lng="{{ $parcel->sellerOrder?->order?->address?->longitude }}" aria-label="Select {{ $parcel->tracking_number }}"><input type="hidden" name="seller_order_ids[{{ $parcel->id }}]" value="{{ $parcel->seller_order_id }}"></td>
                                <td><strong>{{ $parcel->tracking_number }}</strong><small class="block">{{ $parcel->orderItem?->product_name ?? 'Parcel' }}</small></td>
                                <td>
                                    @if($parcel->buyer_has_local_logistics_center)
                                        <div class="sl-pickup-route-preview" aria-label="Automatic Buyer destination hub">
                                            <span class="sl-pickup-route-preview-label">Automatic destination · view only</span>
                                            @foreach($parcel->buyer_local_logistics_centers ?? [] as $destinationCenter)
                                                <div class="sl-pickup-route-preview-hub">
                                                    <strong>{{ $destinationCenter['name'] }}</strong>
                                                    @if(filled($destinationCenter['address'] ?? null))
                                                        <span>{{ $destinationCenter['address'] }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                            <small>Buyer location is covered. LIKHAE routes automatically based on capacity.</small>
                                        </div>
                                    @else
                                        <small data-buyer-pin-missing hidden class="sl-pickup-pin-notice">The Buyer delivery address needs a confirmed map pin before a fallback destination hub can be selected.</small>
                                        <label data-destination-hub-field hidden>
                                            <span class="sr-only">Choose Buyer destination Logistics center for {{ $parcel->tracking_number }}</span>
                                            <select name="preferred_destination_logistics_center_ids[{{ $parcel->id }}]" data-destination-hub class="min-w-48" @disabled(!$automaticPickupMatchingReady || !$sellerSelectedFallbackHubsReady)>
                                                <option value="">Choose a destination hub</option>
                                                @foreach($pickupCenterOptions as $destinationOption)
                                                    <option value="{{ $destinationOption->id }}" data-lat="{{ $destinationOption->address?->latitude }}" data-lng="{{ $destinationOption->address?->longitude }}" @selected((string)old('preferred_destination_logistics_center_ids.'.$parcel->id) === (string)$destinationOption->id)>
                                                        {{ $destinationOption->business_name }} — {{ $destinationOption->address?->formatted() ?: 'Location unavailable' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                </td>
                                <td>{{ $parcel->sellerOrder?->seller_order_number }}</td>
                                <td>
                                    <a class="text-sm font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('seller.orders.waybill', $parcel->seller_order_id) }}">Open order waybill</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table></div>
                    <div class="sl-pickup-submit-row">
                        <p class="text-sm text-muted"><strong data-pickup-request-count>0</strong> selected · choose 1 to 10 parcels.</p>
                        <button type="submit" data-pickup-request-submit disabled class="sl-btn sl-btn-primary disabled:cursor-not-allowed disabled:opacity-50">{{ $automaticPickupMatchingReady ? 'Send pickup request' : 'Request pickup from selected center' }}</button>
                    </div>
                </form>
            @endif
            </div>
        </section>
        @endif

        <section class="space-y-4">
            <div class="sl-pickup-section-heading"><h2 class="text-xl font-semibold text-ink">Your pickup requests</h2><p class="mt-1 text-sm text-muted">Collected progress is counted from successful per-parcel Rider scans.</p></div>
            @forelse($pickupRequests as $pickupRequest)
                @include('seller.partials.pickup-request-progress', ['pickupRequest' => $pickupRequest])
            @empty
                <section class="sl-card sl-pickup-empty text-sm text-muted">No pickup requests yet.</section>
            @endforelse
        </section>
        <script>
            (() => {
                const form = document.querySelector('[data-seller-pickup-request-form] form');
                if (!form) return;
                const checks = [...form.querySelectorAll('[data-pickup-request-parcel]')];
                const destinationHubs = [...form.querySelectorAll('[data-destination-hub]')];
                const address = form.querySelector('[data-pickup-address]');
                const center = form.querySelector('[data-pickup-center]');
                const centerField = form.querySelector('[data-pickup-center-field]');
                const missingPinNotice = form.querySelector('[data-pickup-pin-missing]');
                const pickupPinLink = form.querySelector('[data-pickup-pin-link]');
                const automaticMatchingReady = @json($automaticPickupMatchingReady);
                const anyReadyBuyerNeedsCenterChoice = @json($anyReadyBuyerNeedsCenterChoice);
                const fallbackHubsReady = @json($sellerSelectedFallbackHubsReady);
                const fallbackRouteFlag = form.querySelector('[data-fallback-route-flag]');
                const fallbackSchemaWarning = form.querySelector('[data-fallback-schema-warning]');
                const fallbackCentersMissing = form.querySelector('[data-fallback-centers-missing]');
                const count = form.querySelector('[data-pickup-request-count]');
                const submit = form.querySelector('[data-pickup-request-submit]');
                const update = () => {
                    let checked = checks.filter((checkbox) => checkbox.checked);
                    if (checked.length > 10) {
                        checked[checked.length - 1].checked = false;
                        checked = checked.slice(0, 10);
                    }
                    const total = checked.length;
                    if (count) count.textContent = String(total);
                    const selectedAddress = address?.selectedOptions[0];
                    const addressHasPin = Boolean(selectedAddress?.dataset.lat && selectedAddress?.dataset.lng);
                    if (address && center) {
                        const addressId = address.value;
                        const selectedParcelCenters = checked
                            .map((checkbox) => checkbox.dataset.currentLogisticsCenter)
                            .filter(Boolean);
                        [...center.options].forEach((option, index) => {
                            if (index === 0) return;
                            let allowedAddressIds = [];
                            try {
                                allowedAddressIds = JSON.parse(option.dataset.pickupAddressIds || '[]').map(String);
                            } catch (error) {
                                allowedAddressIds = [];
                            }
                            const coversPickupAddress = automaticMatchingReady || allowedAddressIds.includes(addressId);
                            const matchesExistingRoute = automaticMatchingReady
                                || selectedParcelCenters.length === 0
                                || selectedParcelCenters.every((centerId) => centerId === option.value);
                            option.hidden = !coversPickupAddress || !matchesExistingRoute;
                            option.disabled = option.hidden;
                        });
                        if (automaticMatchingReady) {
                            const addressOption = address.selectedOptions[0];
                            const hasPickupPin = Boolean(addressOption?.dataset.lat && addressOption?.dataset.lng);
                            const fromLat = Number(addressOption?.dataset.lat);
                            const fromLng = Number(addressOption?.dataset.lng);
                            const options = [...center.options].slice(1).map((option) => {
                                const toLat = Number(option.dataset.lat);
                                const toLng = Number(option.dataset.lng);
                                const radians = (value) => value * Math.PI / 180;
                                const dLat = radians(toLat - fromLat);
                                const dLng = radians(toLng - fromLng);
                                const a = Math.sin(dLat / 2) ** 2
                                    + Math.cos(radians(fromLat)) * Math.cos(radians(toLat)) * Math.sin(dLng / 2) ** 2;
                                const distance = hasPickupPin && Number.isFinite(toLat) && Number.isFinite(toLng)
                                    ? 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)))
                                    : Number.NaN;
                                option.dataset.distance = Number.isFinite(distance) ? String(distance) : '';
                                option.dataset.centerName ||= option.textContent.trim();
                                option.textContent = Number.isFinite(distance)
                                    ? `${option.dataset.centerName} · ${distance.toFixed(1)} km approx.`
                                    : option.dataset.centerName;
                                return option;
                            }).sort((left, right) => Number(left.dataset.distance || Number.MAX_SAFE_INTEGER)
                                - Number(right.dataset.distance || Number.MAX_SAFE_INTEGER));
                            options.forEach((option) => center.append(option));
                        }
                        const selectedOption = center.selectedOptions[0];
                        if (selectedOption?.disabled) center.value = '';
                    }
                    const buyerNeedsCenterChoice = checked.length > 0
                        ? checked.some((checkbox) => checkbox.dataset.buyerLocalCenter !== '1')
                        : anyReadyBuyerNeedsCenterChoice;
                    const hasSelectedAddress = Boolean(address?.value);
                    const sellerNeedsCenterChoice = !automaticMatchingReady
                        || (hasSelectedAddress && selectedAddress?.dataset.localCenter !== '1');
                    const centerChoiceNeeded = sellerNeedsCenterChoice;
                    const fallbackNeeded = automaticMatchingReady && hasSelectedAddress
                        && (sellerNeedsCenterChoice || buyerNeedsCenterChoice);
                    const hasCoveredCenterOption = Boolean(center && [...center.options].slice(1)
                        .some((option) => !option.hidden && !option.disabled));
                    const showCenterChoice = !automaticMatchingReady || (centerChoiceNeeded && hasCoveredCenterOption);
                    let missingDestinationChoice = false;
                    destinationHubs.forEach((select) => {
                        const shipmentId = select.name.match(/\[(\d+)\]$/)?.[1];
                        const parcel = checks.find((checkbox) => checkbox.value === shipmentId);
                        const needsDestination = Boolean(parcel?.checked && parcel.dataset.buyerLocalCenter !== '1');
                        const field = select.closest('[data-destination-hub-field]');
                        if (field) field.hidden = !needsDestination;
                        const hasBuyerPin = Boolean(parcel?.dataset.buyerLat && parcel?.dataset.buyerLng);
                        const pinNotice = select.closest('tr')?.querySelector('[data-buyer-pin-missing]');
                        if (pinNotice) pinNotice.hidden = !needsDestination || hasBuyerPin;
                        select.disabled = !needsDestination || !fallbackHubsReady || !hasBuyerPin;
                        select.required = needsDestination && hasBuyerPin;
                        if (needsDestination && (!hasBuyerPin || !select.value)) missingDestinationChoice = true;

                        const buyerLat = Number(parcel?.dataset.buyerLat);
                        const buyerLng = Number(parcel?.dataset.buyerLng);
                        if (needsDestination && hasBuyerPin && Number.isFinite(buyerLat) && Number.isFinite(buyerLng)) {
                            [...select.options].slice(1).forEach((option) => {
                                const lat = Number(option.dataset.lat);
                                const lng = Number(option.dataset.lng);
                                const radians = (value) => value * Math.PI / 180;
                                const dLat = radians(lat - buyerLat);
                                const dLng = radians(lng - buyerLng);
                                const a = Math.sin(dLat / 2) ** 2
                                    + Math.cos(radians(buyerLat)) * Math.cos(radians(lat)) * Math.sin(dLng / 2) ** 2;
                                const km = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)));
                                option.dataset.distance = Number.isFinite(km) ? String(km) : '';
                                option.dataset.centerName ||= option.textContent.trim();
                                option.textContent = Number.isFinite(km)
                                    ? `${option.dataset.centerName} · ${km.toFixed(1)} km approx.`
                                    : option.dataset.centerName;
                            });
                            [...select.options].slice(1)
                                .sort((left, right) => Number(left.dataset.distance || Number.MAX_SAFE_INTEGER)
                                    - Number(right.dataset.distance || Number.MAX_SAFE_INTEGER))
                                .forEach((option) => select.append(option));
                        }
                    });
                    if (fallbackRouteFlag) fallbackRouteFlag.value = fallbackNeeded ? '1' : '0';
                    if (fallbackSchemaWarning) fallbackSchemaWarning.hidden = !fallbackNeeded || fallbackHubsReady;
                    if (fallbackCentersMissing) fallbackCentersMissing.hidden = !fallbackNeeded || (center?.options.length ?? 0) > 1;
                    if (centerField) centerField.hidden = !showCenterChoice;
                    if (center) {
                        center.disabled = !showCenterChoice;
                        center.required = showCenterChoice;
                    }
                    if (automaticMatchingReady && centerChoiceNeeded && hasCoveredCenterOption && center && !center.value) {
                        const nearest = [...center.options].slice(1).find((option) => !option.hidden && !option.disabled);
                        if (nearest) center.value = nearest.value;
                    }
                    if (missingPinNotice) missingPinNotice.hidden = !selectedAddress?.value || addressHasPin;
                    if (pickupPinLink && selectedAddress?.value) {
                        pickupPinLink.href = `${form.dataset.pickupAddressSettingsUrl}#pickup-address-${encodeURIComponent(selectedAddress.value)}`;
                    }
                    if (submit) {
                        submit.disabled = total < 1 || total > 10 || !address?.value || !addressHasPin
                            || (showCenterChoice && !center?.value)
                            || (fallbackNeeded && !fallbackHubsReady)
                            || (fallbackNeeded && missingDestinationChoice);
                    }
                };
                form.addEventListener('change', update);
                form.addEventListener('input', update);
                window.addEventListener('pageshow', update);
                update();
            })();
        </script>
    @endif

</div>
@endsection
