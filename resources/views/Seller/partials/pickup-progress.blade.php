@php
    $progressStatus = match (data_get($order, 'pickup_batch_status')) {
        'LOADING' => 'Assigned',
        'READY' => 'Accepted',
        'IN_PROGRESS' => 'Pickup in progress',
        'EN_ROUTE_TO_HUB' => 'In transit to logistics',
        'COMPLETED' => 'Complete',
        'CANCELLED' => 'Cancelled',
        'EXCEPTION' => 'Needs Logistics review',
        default => 'Pickup status unavailable',
    };
@endphp

<section
    class="sl-card"
    data-pickup-progress
    data-progress-url="{{ data_get($order, 'pickup_progress_url') }}"
    data-progress-active="{{ data_get($order, 'pickup_progress_active') ? 'true' : 'false' }}"
    data-progress-shipment-ids='@json(data_get($order, "pickup_progress_shipment_ids", []))'
>
    <header class="sl-card-head">
        <div>
            <span class="sl-eyebrow">Pickup progress</span>
            <h2>Pickup Batch #{{ data_get($order, 'pickup_batch') }}</h2>
            <p data-progress-status>{{ $progressStatus }}</p>
        </div>
        <strong class="text-sm text-ink" data-progress-count aria-live="polite">{{ data_get($order, 'pickup_progress_collected', 0) }} / {{ data_get($order, 'pickup_progress_total', 0) }} parcels collected</strong>
    </header>

    <p class="mt-2 text-sm text-muted">Rider: <strong data-progress-rider>{{ data_get($order, 'pickup_rider') ?: 'Waiting for assignment' }}</strong></p>
    <ol class="mt-4 grid gap-2 sm:grid-cols-2" data-progress-parcels>
        @foreach(data_get($order, 'pickup_progress_parcels', collect()) as $parcel)
            <li class="flex items-center justify-between gap-3 border border-line px-3 py-2 text-sm" data-pickup-parcel>
                <strong class="truncate text-ink">{{ $parcel['tracking_number'] }}</strong>
                <span class="shrink-0 text-xs font-semibold {{ $parcel['status'] === 'Collected' ? 'text-green-700' : (in_array($parcel['status'], ['Cancelled', 'Not collected'], true) ? 'text-amber-800' : 'text-muted') }}" data-parcel-status>{{ $parcel['status'] }}</span>
            </li>
        @endforeach
    </ol>
</section>

@once
    @push('scripts')
        <script>
            (() => {
                const panel = document.querySelector('[data-pickup-progress]');
                if (!panel || panel.dataset.progressActive !== 'true') return;

                const url = panel.dataset.progressUrl;
                const shipmentIds = JSON.parse(panel.dataset.progressShipmentIds || '[]');
                const count = panel.querySelector('[data-progress-count]');
                const status = panel.querySelector('[data-progress-status]');
                const rider = panel.querySelector('[data-progress-rider]');
                const list = panel.querySelector('[data-progress-parcels]');
                const terminalStatuses = new Set(['EN_ROUTE_TO_HUB', 'COMPLETED', 'CANCELLED', 'EXCEPTION']);
                let timer = null;
                let inFlight = false;
                let stopped = false;

                const stopPolling = () => {
                    if (timer !== null) window.clearInterval(timer);
                    timer = null;
                };

                const startPolling = () => {
                    if (stopped || timer !== null) return;
                    timer = window.setInterval(refresh, 15000);
                };

                const render = (payload) => {
                    count.textContent = `${payload.collected} / ${payload.total} parcels collected`;
                    rider.textContent = payload.rider || 'Waiting for assignment';
                    status.textContent = ({
                        LOADING: 'Assigned',
                        READY: 'Accepted',
                        IN_PROGRESS: 'Pickup in progress',
                        EN_ROUTE_TO_HUB: 'In transit to logistics',
                        COMPLETED: 'Complete',
                        CANCELLED: 'Cancelled',
                        EXCEPTION: 'Needs Logistics review',
                    })[payload.status] || 'Pickup status unavailable';

                    list.replaceChildren(...payload.parcels.map((parcel) => {
                        const item = document.createElement('li');
                        item.className = 'flex items-center justify-between gap-3 border border-line px-3 py-2 text-sm';
                        item.dataset.pickupParcel = '';

                        const tracking = document.createElement('strong');
                        tracking.className = 'truncate text-ink';
                        tracking.textContent = parcel.tracking_number;

                        const parcelStatus = document.createElement('span');
                        parcelStatus.className = `shrink-0 text-xs font-semibold ${parcel.status === 'Collected' ? 'text-green-700' : (['Cancelled', 'Not collected'].includes(parcel.status) ? 'text-amber-800' : 'text-muted')}`;
                        parcelStatus.dataset.parcelStatus = '';
                        parcelStatus.textContent = parcel.status;

                        item.append(tracking, parcelStatus);
                        return item;
                    }));

                    if (terminalStatuses.has(payload.status)) {
                        stopped = true;
                        stopPolling();
                    }
                };

                async function refresh() {
                    if (stopped || inFlight || document.hidden) return;
                    inFlight = true;
                    try {
                        const response = await fetch(url, {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if ([401, 403, 404, 503].includes(response.status)) {
                            stopped = true;
                            stopPolling();
                            return;
                        }
                        if (!response.ok) return;
                        render(await response.json());
                    } catch {
                        // Keep the bounded polling fallback available after transient network errors.
                    } finally {
                        inFlight = false;
                    }
                }

                if (window.Echo?.private) {
                    shipmentIds.forEach((shipmentId) => {
                        window.Echo.private(`shipments.${shipmentId}`).listen('.shipment.tracking.updated', refresh);
                    });
                }

                const connection = window.Echo?.connector?.pusher?.connection;
                const syncPolling = () => {
                    if (!connection || connection.state !== 'connected') startPolling();
                    else stopPolling();
                };
                if (connection) {
                    ['connected', 'connecting', 'disconnected', 'unavailable', 'failed'].forEach((eventName) => {
                        connection.bind(eventName, syncPolling);
                    });
                }
                syncPolling();
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) refresh();
                });
            })();
        </script>
    @endpush
@endonce
