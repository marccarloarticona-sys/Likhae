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
            @if($automaticPickupMatchingReady)
                <p class="sl-pickup-guidance">Nearby approved Logistics centers are listed nearest first. Keep the nearest suggestion or choose another in the dropdown. LIKHAE checks pickup coverage, Rider capacity, and the complete Buyer route; if your preferred center cannot take the request, it will try the next eligible center. With no complete route or capacity, the request stays pending and is not marked assigned.</p>
            @else
                <p class="sl-pickup-guidance">Only an approved Logistics center with coverage for the selected pickup address can receive this request.</p>
            @endif
            @if($pickupAddresses->isEmpty())
                <p class="sl-pickup-state-note is-warning">Add a pickup address before requesting a Rider. <a class="font-semibold underline" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">Manage pickup addresses</a>.</p>
            @elseif($readyPickupParcels->isEmpty())
                <p class="sl-pickup-state-note">Mark packed parcels Ready for Pickup on the Orders page, then select them here and choose a nearby Logistics center. No other ready parcels are waiting for a request.</p>
            @elseif($pickupCenterOptions->isEmpty() && !$automaticPickupMatchingReady)
                <p class="sl-pickup-state-note is-warning">Walang pickup request na naipadala dahil walang active at approved Logistics center na sumasakop sa mga naka-pin na pickup address. I-check ang map pin o makipag-ugnayan sa Logistics support; lalabas dito ang mga center kapag may sumakop na sa lugar.</p>
            @else
                <form method="POST" action="{{ route('seller.logistics.pickup-requests.store') }}" data-pickup-address-settings-url="{{ route('seller.account', ['tab' => 'pickup-addresses']) }}" class="sl-pickup-form">
                    @csrf
                    <div class="sl-pickup-fields">
                    <label class="sl-pickup-field"><span class="sl-pickup-label">Pickup address</span>
                        <select name="pickup_address_id" data-pickup-address required>
                            <option value="">Select a saved pickup address</option>
                            @foreach($pickupAddresses as $address)
                                <option value="{{ $address->id }}" data-lat="{{ $address->latitude }}" data-lng="{{ $address->longitude }}" @disabled(!is_numeric($address->latitude) || !is_numeric($address->longitude)) @selected((string)old('pickup_address_id', $pickupAddress?->id) === (string)$address->id)>
                                    {{ $address->label ?: 'Pickup address' }}{{ $address->is_default ? ' · Default' : '' }} — {{ $address->formatted() }}{{ !is_numeric($address->latitude) || !is_numeric($address->longitude) ? ' · Confirm map pin first' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <small class="sl-pickup-field-help"><a class="font-semibold underline" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">Manage pickup addresses</a>.</small>
                        <small data-pickup-pin-missing hidden class="sl-pickup-pin-notice">This saved pickup address has no confirmed map pin. <a data-pickup-pin-link class="font-semibold underline" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">Open this pickup address</a> to confirm its pin or add another pinned address.</small>
                        @if(!$automaticPickupMatchingReady)
                            <small class="sl-pickup-field-help">Only centers with approved coverage for this exact pickup barangay are listed.</small>
                            <small data-pickup-no-center hidden class="sl-pickup-pin-notice">Walang eligible Logistics center para sa address na ito. Pumili ng ibang pickup address o ipa-check ang map pin at coverage sa Logistics support.</small>
                        @endif
                    </label>
                    <label class="sl-pickup-field"><span class="sl-pickup-label">Preferred Logistics center <small>(nearest first)</small></span>
                        <select name="preferred_logistics_center_id" data-pickup-center @required(!$automaticPickupMatchingReady || $pickupCenterOptions->isNotEmpty())>
                            <option value="">{{ $pickupCenterOptions->isEmpty() ? 'No approved centers available right now' : 'Choose a nearby Logistics center' }}</option>
                            @foreach($pickupCenterOptions as $centerOption)
                                <option value="{{ $centerOption->id }}" data-lat="{{ $centerOption->address?->latitude }}" data-lng="{{ $centerOption->address?->longitude }}" @if(!$automaticPickupMatchingReady) data-pickup-address-ids='@json($centerOption->authorized_pickup_address_ids)' @endif @selected((string)old('preferred_logistics_center_id') === (string)$centerOption->id)>
                                    {{ $centerOption->business_name }} — {{ $centerOption->address?->formatted() ?: 'Location unavailable' }}
                                </option>
                            @endforeach
                        </select>
                        @if($automaticPickupMatchingReady && $pickupCenterOptions->isEmpty())
                            <small class="sl-pickup-pin-notice">No active approved center with a confirmed map pin is available right now. You can still send the request without choosing; it will stay pending until LIKHAE finds an eligible center and complete route.</small>
                        @endif
                    </label>
                    </div>
                    <div class="sl-table-wrap sl-pickup-table-wrap"><table class="sl-table sl-pickup-table"><thead><tr><th>Select</th><th>Parcel scan code / product</th><th>SellerOrder</th><th>Order waybill</th></tr></thead><tbody>
                        @foreach($readyPickupParcels as $parcel)
                            <tr>
                                <td><input type="checkbox" name="shipment_ids[]" value="{{ $parcel->id }}" data-pickup-request-parcel data-current-logistics-center="{{ $parcel->logistics_center_id ?: $parcel->serviceArea?->logistics_center_id }}" aria-label="Select {{ $parcel->tracking_number }}"><input type="hidden" name="seller_order_ids[{{ $parcel->id }}]" value="{{ $parcel->seller_order_id }}"></td>
                                <td><strong>{{ $parcel->tracking_number }}</strong><small class="block">{{ $parcel->orderItem?->product_name ?? 'Parcel' }}</small></td>
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
                const address = form.querySelector('[data-pickup-address]');
                const center = form.querySelector('[data-pickup-center]');
                const missingPinNotice = form.querySelector('[data-pickup-pin-missing]');
                const pickupPinLink = form.querySelector('[data-pickup-pin-link]');
                const automaticMatchingReady = @json($automaticPickupMatchingReady);
                const count = form.querySelector('[data-pickup-request-count]');
                const submit = form.querySelector('[data-pickup-request-submit]');
                const update = () => {
                    let checked = checks.filter((checkbox) => checkbox.checked);
                    if (checked.length > 10) {
                        checked[checked.length - 1].checked = false;
                        checked = checked.slice(0, 10);
                    }
                    if (address && center && !automaticMatchingReady) {
                        const addressId = address.value;
                        const selectedParcelCenters = checked
                            .map((checkbox) => checkbox.dataset.currentLogisticsCenter)
                            .filter(Boolean);
                        [...center.options].forEach((option, index) => {
                            if (index === 0) return;
                            const allowedAddressIds = JSON.parse(option.dataset.pickupAddressIds || '[]').map(String);
                            const matchesExistingRoute = automaticMatchingReady
                                || selectedParcelCenters.length === 0
                                || selectedParcelCenters.every((centerId) => centerId === option.value);
                            option.hidden = !allowedAddressIds.includes(addressId) || !matchesExistingRoute;
                            option.disabled = option.hidden;
                        });
                        const selectedOption = center.selectedOptions[0];
                        if (selectedOption?.disabled) center.value = '';
                        const noCenterMessage = form.querySelector('[data-pickup-no-center]');
                        if (noCenterMessage) {
                            noCenterMessage.hidden = [...center.options].slice(1).some((option) => !option.hidden) || !addressId;
                        }
                    }
                    const total = checked.length;
                    count.textContent = String(total);
                    if (automaticMatchingReady && address && center) {
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
                        if (!center.value && options.length > 0) center.value = options[0].value;
                    }
                    const selectedAddress = address?.selectedOptions[0];
                    const addressHasPin = Boolean(selectedAddress?.dataset.lat && selectedAddress?.dataset.lng);
                    if (missingPinNotice) missingPinNotice.hidden = !selectedAddress?.value || addressHasPin;
                    if (pickupPinLink && selectedAddress?.value) {
                        pickupPinLink.href = `${form.dataset.pickupAddressSettingsUrl}#pickup-address-${encodeURIComponent(selectedAddress.value)}`;
                    }
                    submit.disabled = total < 1 || total > 10 || !address?.value || (!automaticMatchingReady && !center?.value);
                };
                form.addEventListener('change', update);
                update();
            })();
        </script>
    @endif

</div>
@endsection
