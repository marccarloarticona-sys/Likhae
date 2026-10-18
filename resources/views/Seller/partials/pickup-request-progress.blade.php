@php
    $progress = $pickupRequest->progress_payload;
    $active = in_array($pickupRequest->status, ['REQUESTED', 'ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true);
@endphp
<article class="sl-card" data-seller-pickup-request-progress
    data-progress-url="{{ route('seller.logistics.pickup-requests.progress', $pickupRequest) }}"
    data-progress-active="{{ $active ? 'true' : 'false' }}"
    data-progress-shipment-ids='@json($pickupRequest->memberships->pluck("shipment_id")->values())'>
    <header class="sl-card-head">
        <div>
            <span class="sl-eyebrow">Pickup Request #{{ $pickupRequest->id }}</span>
            <h3>{{ str($pickupRequest->status)->replace('_', ' ')->title() }}</h3>
            <p>{{ $pickupRequest->pickupAddress?->formatted() ?? 'Pickup address unavailable' }}</p>
        </div>
        <strong class="text-sm text-ink" data-progress-count aria-live="polite">{{ $progress['collected'] }} / {{ $progress['total'] }} collected</strong>
    </header>
    <p class="mt-2 text-sm text-muted">Rider: <strong data-progress-rider>{{ $pickupRequest->riderLocalTrip?->rider?->user?->name ?? 'Waiting for assignment' }}</strong></p>
    <ol class="mt-4 grid gap-2 sm:grid-cols-2" data-progress-parcels>
        @foreach($progress['parcels'] as $parcel)
            <li class="flex items-center justify-between gap-3 border border-line px-3 py-2 text-sm">
                <strong class="truncate text-ink">{{ $parcel['tracking_number'] }}</strong>
                <span class="shrink-0 text-xs font-semibold {{ $parcel['collected'] ? 'text-green-700' : 'text-muted' }}" data-parcel-status>{{ $parcel['status'] }}</span>
            </li>
        @endforeach
    </ol>
    @if($pickupRequest->status === 'REQUESTED')
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
                const terminal = new Set(['COMPLETED', 'PARTIALLY_COLLECTED', 'NOT_COLLECTED', 'CANCELLED', 'REJECTED']);
                const labels = {
                    REQUESTED: 'Waiting for Logistics', ASSIGNED: 'Rider assigned', ACCEPTED: 'Rider accepted',
                    IN_PROGRESS: 'Pickup in progress', COMPLETED: 'Collected', PARTIALLY_COLLECTED: 'Partially collected',
                    NOT_COLLECTED: 'No parcels collected', CANCELLED: 'Cancelled', REJECTED: 'Rejected',
                };

                document.querySelectorAll('[data-seller-pickup-request-progress]').forEach((panel) => {
                    if (panel.dataset.progressActive !== 'true') return;
                    const url = panel.dataset.progressUrl;
                    const shipmentIds = JSON.parse(panel.dataset.progressShipmentIds || '[]');
                    const count = panel.querySelector('[data-progress-count]');
                    const rider = panel.querySelector('[data-progress-rider]');
                    const list = panel.querySelector('[data-progress-parcels]');
                    let timer = null;
                    let inFlight = false;
                    let stopped = false;

                    const stop = () => { if (timer !== null) window.clearInterval(timer); timer = null; };
                    const poll = () => { if (!stopped && timer === null) timer = window.setInterval(refresh, 15000); };
                    const render = (payload) => {
                        count.textContent = `${payload.collected} / ${payload.total} collected`;
                        rider.textContent = payload.rider || 'Waiting for assignment';
                        panel.querySelector('h3').textContent = labels[payload.status] || payload.status;
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
