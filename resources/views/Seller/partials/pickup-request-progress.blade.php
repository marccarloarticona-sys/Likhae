@php
    $progress = $pickupRequest->progress_payload;
    $active = in_array($pickupRequest->status, ['REQUESTED', 'MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING', 'ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true);
@endphp
<article class="sl-card" data-seller-pickup-request-progress
    data-progress-url="{{ route('seller.logistics.pickup-requests.progress', $pickupRequest) }}"
    data-progress-active="{{ $active ? 'true' : 'false' }}"
    data-progress-shipment-ids='@json($pickupRequest->memberships->pluck("shipment_id")->values())'>
    <header class="sl-card-head">
        <div>
            <span class="sl-eyebrow">Pickup Request #{{ $pickupRequest->id }}</span>
            <h3>{{ $progress['status_label'] ?? str($pickupRequest->status)->replace('_', ' ')->title() }}</h3>
            <p>{{ $pickupRequest->pickupAddress?->formatted() ?? 'Pickup address unavailable' }}</p>
            @if($pickupRequest->preferredLogisticsCenter)
                <p class="mt-1 text-sm text-muted">Preferred center (first choice): <strong data-progress-selected-center>{{ $pickupRequest->preferredLogisticsCenter->business_name }}</strong></p>
            @endif
            <p class="mt-1 text-sm text-muted" data-progress-assigned-center @if(empty($progress['assigned_center'])) hidden @endif>
                Assigned Logistics center:
                <strong data-progress-assigned-center-name>{{ $progress['assigned_center']['name'] ?? '' }}</strong>
                <span data-progress-assigned-center-address>{{ $progress['assigned_center']['address'] ?? 'Location unavailable' }}</span>
            </p>
            @foreach($progress['destination_hubs'] ?? [] as $destinationHub)
                <p class="mt-1 text-sm text-muted">Buyer destination Logistics center: <strong>{{ $destinationHub['name'] }}</strong>{{ filled($destinationHub['address']) ? ' — '.$destinationHub['address'] : '' }}</p>
            @endforeach
        </div>
        <strong class="text-sm text-ink" data-progress-count aria-live="polite">{{ $progress['collected'] }} / {{ $progress['total'] }} collected</strong>
    </header>
    <p class="mt-2 text-sm text-muted">Rider: <strong data-progress-rider>{{ $pickupRequest->riderLocalTrip?->rider?->user?->name ?? 'Waiting for assignment' }}</strong></p>
    <div class="mt-1 space-y-1 text-sm text-muted" data-progress-offer>
        @if(!empty($progress['route_offers']))
            @foreach($progress['route_offers'] as $routeOffer)
                <p>
                    <strong>{{ $routeOffer['leg_label'] }} hub:</strong>
                    @if($routeOffer['attempt'] > 0) Offer #{{ $routeOffer['attempt'] }} to @endif
                    {{ $routeOffer['center'] ?: 'Hub unavailable' }}
                    @if(filled($routeOffer['center_address'])) ({{ $routeOffer['center_address'] }})@endif
                    @if($routeOffer['leg_type'] === 'DELIVERY')
                        · for {{ $routeOffer['delivery_address'] }}
                    @endif
                    — {{ match($routeOffer['status']) {
                        'OFFERED' => 'awaiting acceptance', 'ACCEPTED' => 'accepted', 'DECLINED' => 'declined',
                        'EXPIRED' => 'timed out', 'NO_CAPACITY' => 'no available capacity', 'SUPERSEDED' => 'replaced by another offer',
                        'MATCHING' => 'checking for an online Rider and eligible delivery hub',
                        default => str($routeOffer['status'])->lower()->replace('_', ' '),
                    } }}
                    @if($routeOffer['active'] && $routeOffer['expires_at'])
                        · response due {{ \Illuminate\Support\Carbon::parse($routeOffer['expires_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}
                    @endif
                </p>
            @endforeach
        @elseif($progress['requires_center_selection'] ?? false)
            This older request has no Seller-selected Logistics center. Cancel it and create a new request to choose one.
        @elseif(!empty($progress['current_offer']))
            Offer {{ $progress['current_offer']['attempt'] }} sent to {{ $progress['current_offer']['center'] }}; awaiting acceptance (not assigned yet) · response due {{ \Illuminate\Support\Carbon::parse($progress['current_offer']['expires_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}@if($progress['automatic_matching'] ?? false) · If it declines or times out, LIKHAE tries the next eligible center.@endif
        @elseif(($progress['automatic_matching'] ?? false) && in_array($pickupRequest->status, ['MATCHING', 'WAITING_FOR_RESPONSE'], true))
            LIKHAE is matching eligible Logistics centers for this pickup address. No center is assigned until one accepts.
        @elseif($pickupRequest->status === 'PENDING')
            @if($progress['automatic_matching'] ?? false)
                @if($progress['seller_selected_fallback'] ?? false)
                    The selected destination hubs are saved. LIKHAE is checking for pickup Rider, inter-hub vehicle, and destination delivery capacity while it tries eligible origin centers.
                @else
                    No center currently has the full route and capacity. Your request stays pending while LIKHAE keeps checking eligible centers.
                @endif
            @elseif($pickupRequest->preferredLogisticsCenter)
                The selected Logistics center has not confirmed a route or Rider capacity yet. If it is still unresolved by {{ \Illuminate\Support\Carbon::parse($progress['response_due_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}, it will return to you automatically.
            @else
                No complete route or available center capacity is confirmed yet. Your request remains open for Logistics follow-up.
            @endif
        @elseif($pickupRequest->status === 'REQUESTED' && $pickupRequest->preferredLogisticsCenter)
            Sent to {{ $pickupRequest->preferredLogisticsCenter->business_name }}. If it is not accepted by {{ \Illuminate\Support\Carbon::parse($progress['response_due_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}, it will return to you automatically.
        @elseif($pickupRequest->status === 'RETURNED_TO_SELLER')
            {{ $progress['return_message'] ?? 'Your parcels are available again. Choose a covered Logistics center above to request pickup again.' }}
        @endif
    </div>
    <ol class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" data-progress-parcels>
        @foreach($progress['parcels'] as $parcel)
            <li class="flex items-center justify-between gap-3 border border-line px-3 py-2 text-sm">
                <strong class="truncate text-ink">{{ $parcel['tracking_number'] }}</strong>
                <span class="shrink-0 text-xs font-semibold {{ $parcel['collected'] ? 'text-green-700' : 'text-muted' }}" data-parcel-status>{{ $parcel['status'] }}</span>
            </li>
        @endforeach
    </ol>
    @if(in_array($pickupRequest->status, ['REQUESTED', 'MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING'], true))
        <form method="POST" action="{{ route('seller.logistics.pickup-requests.cancel', $pickupRequest) }}" class="mt-4" data-confirm-title="Cancel this pickup request?" data-confirm-message="The request will be cancelled and its parcel history will remain available." data-confirm-label="Cancel request">
            @csrf
            <button class="sl-btn sl-btn-ghost sl-btn-sm">Cancel request</button>
        </form>
    @endif
</article>

@once
    @push('scripts')
        <script>
            (() => {
                const terminal = new Set(['COMPLETED', 'PARTIALLY_COLLECTED', 'NOT_COLLECTED', 'CANCELLED', 'REJECTED', 'RETURNED_TO_SELLER']);
                const labels = {
                    REQUESTED: 'Waiting for Logistics', MATCHING: 'Matching nearby Logistics centers', WAITING_FOR_RESPONSE: 'Waiting for a Logistics center',
                    PENDING: 'Pending Logistics follow-up', ASSIGNED: 'Accepted — Rider assigned', ACCEPTED: 'Rider accepted',
                    IN_PROGRESS: 'Pickup in progress', COMPLETED: 'Collected', PARTIALLY_COLLECTED: 'Partially collected',
                    NOT_COLLECTED: 'No parcels collected', CANCELLED: 'Cancelled', REJECTED: 'Rejected',
                    RETURNED_TO_SELLER: 'Returned to Seller — choose another center',
                };

                document.querySelectorAll('[data-seller-pickup-request-progress]').forEach((panel) => {
                    if (panel.dataset.progressActive !== 'true') return;
                    const url = panel.dataset.progressUrl;
                    const shipmentIds = JSON.parse(panel.dataset.progressShipmentIds || '[]');
                    const count = panel.querySelector('[data-progress-count]');
                    const rider = panel.querySelector('[data-progress-rider]');
                    const offer = panel.querySelector('[data-progress-offer]');
                    const selectedCenter = panel.querySelector('[data-progress-selected-center]');
                    const assignedCenter = panel.querySelector('[data-progress-assigned-center]');
                    const assignedCenterName = panel.querySelector('[data-progress-assigned-center-name]');
                    const assignedCenterAddress = panel.querySelector('[data-progress-assigned-center-address]');
                    const list = panel.querySelector('[data-progress-parcels]');
                    let timer = null;
                    let inFlight = false;
                    let stopped = false;

                    const stop = () => { if (timer !== null) window.clearInterval(timer); timer = null; };
                    const poll = () => { if (!stopped && timer === null) timer = window.setInterval(refresh, 15000); };
                    const render = (payload) => {
                        count.textContent = `${payload.collected} / ${payload.total} collected`;
                        rider.textContent = payload.rider || 'Waiting for assignment';
                        if (selectedCenter && payload.selected_center) selectedCenter.textContent = payload.selected_center;
                        if (assignedCenter && assignedCenterName && assignedCenterAddress) {
                            assignedCenter.hidden = !payload.assigned_center;
                            assignedCenterName.textContent = payload.assigned_center?.name || '';
                            assignedCenterAddress.textContent = payload.assigned_center?.address || 'Location unavailable';
                        }
                        panel.querySelector('h3').textContent = payload.status_label || labels[payload.status] || payload.status;
                        const destinationHubs = (payload.destination_hubs || []).map((hub) => hub.name).filter(Boolean).join(', ');
                        const routeOffers = payload.route_offers || [];
                        if (routeOffers.length) {
                            offer.replaceChildren(...routeOffers.map((routeOffer) => {
                                const row = document.createElement('p');
                                const leg = routeOffer.leg_type === 'DELIVERY' ? 'Buyer delivery hub' : 'Seller pickup hub';
                                const status = ({ OFFERED: 'awaiting acceptance', ACCEPTED: 'accepted', DECLINED: 'declined', EXPIRED: 'timed out', NO_CAPACITY: 'no available capacity', SUPERSEDED: 'replaced by another offer', MATCHING: 'checking for an online Rider and eligible delivery hub' })[routeOffer.status] || routeOffer.status.toLowerCase().replaceAll('_', ' ');
                                const hubAddress = routeOffer.center_address ? ` (${routeOffer.center_address})` : '';
                                const buyerAddress = routeOffer.leg_type === 'DELIVERY' && routeOffer.delivery_address ? ` for ${routeOffer.delivery_address}` : '';
                                const due = routeOffer.active && routeOffer.expires_at ? ` · response due ${new Date(routeOffer.expires_at).toLocaleString()}` : '';
                                row.textContent = `${leg}: ${routeOffer.center || 'Hub unavailable'}${hubAddress}${buyerAddress} — ${status}${due}`;
                                return row;
                            }));
                        } else {
                        offer.textContent = payload.current_offer
                            ? `Offer ${payload.current_offer.attempt} sent to ${payload.current_offer.center}; awaiting acceptance (not assigned yet) · response due ${new Date(payload.current_offer.expires_at).toLocaleString()}${payload.automatic_matching ? ' · If it declines or times out, LIKHAE tries the next eligible center.' : ''}`
                            : (payload.status === 'RETURNED_TO_SELLER'
                                ? (payload.return_message || 'Your parcels are available again. Choose a covered Logistics center above to request pickup again.')
                                    : (payload.automatic_matching && payload.status === 'PENDING'
                                        ? (payload.seller_selected_fallback
                                            ? `The selected destination hub${destinationHubs ? ` (${destinationHubs})` : ''} is saved. LIKHAE is checking for pickup Rider, inter-hub vehicle, and destination delivery capacity while it tries eligible origin centers.`
                                            : 'No center currently has the full route and capacity. Your request stays pending while LIKHAE keeps checking eligible centers.')
                                        : (payload.response_due_at && ['REQUESTED', 'PENDING'].includes(payload.status)
                                    ? (payload.status === 'REQUESTED'
                                        ? `Sent to ${payload.selected_center}. If it is not accepted by ${new Date(payload.response_due_at).toLocaleString()}, it will return to you automatically.`
                                        : `The selected center has not confirmed a route or Rider capacity. If it is still unresolved by ${new Date(payload.response_due_at).toLocaleString()}, it will return to you automatically.`)
                                : (payload.automatic_matching && ['MATCHING', 'WAITING_FOR_RESPONSE'].includes(payload.status)
                                ? 'LIKHAE is matching eligible Logistics centers for this pickup address. No center is assigned until one accepts.'
                                : (payload.status === 'PENDING' || payload.requires_center_selection
                                ? (payload.requires_center_selection
                                    ? 'This older request has no Seller-selected Logistics center. Cancel it and create a new request to choose one.'
                                    : (payload.selected_center
                                    ? 'The selected Logistics center has no available route or Rider capacity yet. It will return to you if still unresolved after 15 minutes.'
                                    : 'No complete route or available center capacity is confirmed yet. Your request remains open for Logistics follow-up.')
                                )
                                : '')))));
                        }
                        list.replaceChildren(...payload.parcels.map((parcel) => {
                            const row = document.createElement('li');
                            row.className = 'flex items-center justify-between gap-3 border border-line px-3 py-2 text-sm';
                            const tracking = document.createElement('strong');
                            tracking.className = 'truncate text-ink';
                            tracking.textContent = parcel.tracking_number;
                            const parcelStatus = document.createElement('span');
                            parcelStatus.className = `shrink-0 text-xs font-semibold ${parcel.collected ? 'text-green-700' : 'text-muted'}`;
                            parcelStatus.dataset.parcelStatus = '';
                            parcelStatus.textContent = parcel.status;
                            row.append(tracking, parcelStatus);
                            return row;
                        }));
                        if (payload.status === 'RETURNED_TO_SELLER') { window.location.reload(); return; }
                        if (terminal.has(payload.status)) { stopped = true; stop(); }
                    };

                    async function refresh() {
                        if (stopped || inFlight || document.hidden) return;
                        inFlight = true;
                        try {
                            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                            if ([401, 403, 404, 503].includes(response.status)) { stopped = true; stop(); return; }
                            if (response.ok) render(await response.json());
                        } catch {
                            // Retry on the next throttled poll after a transient failure.
                        } finally { inFlight = false; }
                    }

                    if (window.Echo?.private) {
                        shipmentIds.forEach((id) => window.Echo.private(`shipments.${id}`).listen('.shipment.tracking.updated', refresh));
                    }
                    const connection = window.Echo?.connector?.pusher?.connection;
                    const syncPolling = () => {
                        if (!connection || connection.state !== 'connected') poll();
                        else stop();
                    };
                    if (connection) ['connected', 'connecting', 'disconnected', 'unavailable', 'failed'].forEach((name) => connection.bind(name, syncPolling));
                    syncPolling();
                    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
                });
            })();
        </script>
    @endpush
@endonce
