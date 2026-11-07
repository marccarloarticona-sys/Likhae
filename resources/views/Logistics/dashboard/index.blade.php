@extends('layouts.logistics.app')

@section('title', 'Logistics Operations Dashboard - LIKHAE')

@php
    $cards = [
        'pending' => ['label' => 'Pending', 'detail' => 'Ready for pickup', 'icon' => 'M4 7h16v13H4z M8 7V4h8v3 M8 12h8'],
        'in_transit' => ['label' => 'In Transit', 'detail' => 'Moving through logistics', 'icon' => 'M3 6h11v11H3z M14 10h4l3 3v4h-7z M7 19h.01 M18 19h.01'],
        'delivered' => ['label' => 'Delivered', 'detail' => 'Delivered or buyer confirmed', 'icon' => 'M4 12l5 5L20 6'],
        'failed' => ['label' => 'Failed', 'detail' => 'Failed delivery or returned', 'icon' => 'M5 4v17 M5 4h11l-2 4 2 4H5'],
    ];
    $totalCategorized = array_sum($counts);
    $statusColors = [
        'pending' => '#C88418',
        'in_transit' => '#8B4D43',
        'delivered' => '#3B8A5D',
        'failed' => '#C5483E',
    ];
    $statusChartStops = [];
    $statusChartPosition = 0;
    foreach ($cards as $key => $card) {
        $statusCount = (int) ($counts[$key] ?? 0);
        $statusPercent = $totalCategorized > 0 ? ($statusCount / $totalCategorized) * 100 : 0;
        if ($statusPercent > 0) {
            $statusChartStops[] = $statusColors[$key].' '.$statusChartPosition.'% '.($statusChartPosition + $statusPercent).'%';
            $statusChartPosition += $statusPercent;
        }
    }
    $statusChartGradient = $statusChartStops
        ? 'conic-gradient('.implode(', ', $statusChartStops).')'
        : 'conic-gradient(#EADCCC 0 100%)';
    $operations = [
        'incoming' => ['Incoming parcels', 'Local pickups or incoming transfer in motion'],
        'outgoing' => ['Outgoing parcels', 'Ready, released, or moving on a transfer leg'],
        'sorting' => ['Awaiting sorting', 'At sorting center'],
        'rider_assignment' => ['Awaiting rider assignment', 'Sorted without an active delivery assignment'],
        'pickup' => ['Active pickups', 'Pickup rider assignment in progress'],
        'last_mile' => ['Active last-mile', 'Assigned or out for delivery'],
        'active_riders' => ['Active Riders', 'Approved Riders with active accounts'],
        'suspended_riders' => ['Suspended Riders', 'Suspended or inactive Rider profiles'],
        'manifests' => ['Active inter-hub manifests', 'Separate manifest records'],
    ];
    $financeMetrics = [
        ['COD collected', 'cod_collected'],
        ['COD awaiting Rider remittance', 'cod_awaiting_remittance'],
        ['COD reconciled', 'cod_reconciled'],
        ['Expected Rider COD', 'rider_cod_obligations'],
        ['Submitted cash', 'remittance_submitted'],
        ['Verified cash', 'verified_cash'],
        ['Discrepancies', 'discrepancies'],
        ['Total Logistics share allocated', 'logistics_pool'],
        ['Unpaid pending settlement', 'pending_settlement'],
    ];
@endphp

@section('content')
<div class="lk-logistics-dashboard" data-dashboard-snapshot="{{ route('logistics.dashboard') }}">
    <header class="lk-dashboard-heading lk-dashboard-heading--no-actions">
        <div class="lk-dashboard-heading__copy">
            <span class="lk-dashboard-overline">Control center</span>
            <h1>Logistics Operations Dashboard</h1>
            <p>Monitor current parcels, delivery progress, and logistics performance.</p>
        </div>
    </header>

    <div class="lk-dashboard-split">
        <section class="lk-dashboard-card" aria-labelledby="status-overview-heading">
            <div class="lk-dashboard-card-head">
                <div class="lk-dashboard-card-head__copy">
                    <span class="lk-dashboard-overline">Performance</span>
                    <h2 id="status-overview-heading">Shipment Status Overview</h2>
                    <p>Live distribution across the current parcel statuses.</p>
                </div>
            </div>
            <div class="lk-dashboard-status-content">
                <div class="lk-dashboard-donut-wrap">
                    <div class="lk-dashboard-donut" data-shipment-donut style="--chart-gradient: {{ $statusChartGradient }}" role="img" aria-label="Shipment status pie chart: {{ number_format($totalCategorized) }} current parcels">
                        <div class="lk-dashboard-donut__center"><strong>{{ number_format($totalCategorized) }}</strong><span>Parcels</span></div>
                    </div>
                    <span class="lk-dashboard-donut-caption">Current status distribution</span>
                </div>
                <div class="lk-dashboard-status-legend" aria-label="Shipment status chart legend">
                    @foreach($cards as $key => $card)
                        <div class="lk-dashboard-status-legend__row" data-status="{{ $key }}">
                            <span class="lk-dashboard-status-dot" aria-hidden="true"></span>
                            <span class="lk-dashboard-status-name">{{ $card['label'] }}</span>
                            <strong data-shipment-count="{{ $key }}">{{ number_format($counts[$key]) }}</strong>
                            <small data-shipment-share="{{ $key }}">{{ $totalCategorized ? round($counts[$key] / $totalCategorized * 100, 1) : 0 }}%</small>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="lk-dashboard-bars" role="group" aria-label="Shipment status bar chart">
                @foreach($cards as $key => $card)
                    <div class="lk-dashboard-bar" data-status="{{ $key }}" data-chart-bar="{{ $key }}" role="img" aria-label="{{ $card['label'] }}: {{ number_format($counts[$key]) }} parcels">
                        <div class="lk-dashboard-bar__label"><span>{{ $card['label'] }}</span><strong data-shipment-count="{{ $key }}">{{ number_format($counts[$key]) }}</strong></div>
                        <div class="lk-dashboard-bar__track"><div class="lk-dashboard-bar__fill" data-chart-fill="{{ $key }}" style="width: {{ $totalCategorized ? round($counts[$key] / $totalCategorized * 100, 2) : 0 }}%"></div></div>
                    </div>
                @endforeach
                <p class="lk-dashboard-section-head__empty" data-no-current-parcels @if($totalCategorized) hidden @endif>No current parcels in these statuses.</p>
            </div>
        </section>

        <section class="lk-dashboard-card" aria-labelledby="operations-heading">
            <div class="lk-dashboard-card-head">
                <div class="lk-dashboard-card-head__copy">
                    <span class="lk-dashboard-overline">Operations</span>
                    <h2 id="operations-heading">Logistics Operational Overview</h2>
                    <p>Work queues can overlap; manifest totals are separate from parcel counts.</p>
                </div>
            </div>
            <div class="lk-dashboard-ops-grid">
                @foreach($operations as $key => [$label, $detail])
                    <article class="lk-dashboard-op {{ in_array($key, ['active_riders'], true) ? 'lk-dashboard-op--people' : '' }} {{ in_array($key, ['suspended_riders'], true) ? 'lk-dashboard-op--alert' : '' }}">
                        <div class="lk-dashboard-op__top"><span class="lk-dashboard-op__label">{{ $label }}</span><strong data-operational-kpi="{{ $key }}">{{ number_format($operational[$key]) }}</strong></div>
                        <p>{{ $detail }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    </div>

    <section class="lk-dashboard-card lk-attendance-card" aria-labelledby="interhub-timekeeping-heading" data-interhub-qr-panel>
        <div class="lk-dashboard-card-head">
            <div class="lk-dashboard-card-head__copy lk-attendance-intro">
                <span class="lk-dashboard-overline">Interhub payroll</span>
                <h2 id="interhub-timekeeping-heading">Driver Time In / Time Out</h2>
                <p>Drivers are identified by QR. Time In or Time Out is automatic, even without a container assignment. Each code expires after 20 seconds.</p>
            </div>
            <a href="{{ route('logistics.remittances', ['tab' => 'interhub-payouts']) }}" class="lk-dashboard-button">Rates and payroll <span aria-hidden="true">→</span></a>
        </div>
        @if(! $timekeepingReady)
            <div class="lk-attendance-content"><p class="lk-dashboard-alert">Apply the new Interhub timekeeping migration to enable this panel.</p></div>
        @else
            <div class="lk-attendance-content">
                <div class="lk-attendance-tabs" role="group" aria-label="Interhub attendance view">
                    <button type="button" data-attendance-mode-button="qr" aria-pressed="true">Show QR</button>
                    <button type="button" data-attendance-mode-button="scanner" aria-pressed="false">Switch to scanner</button>
                </div>
                <div class="lk-attendance-workspace">
                    <div class="lk-attendance-mode">
                        <div data-attendance-mode-panel="qr" class="lk-attendance-qr" aria-label="Logistics attendance QR">
                            <img data-attendance-qr data-qr-base="{{ route('logistics.interhub-attendance.qr') }}" alt="Rotating Logistics attendance QR" class="hidden">
                            <div class="lk-attendance-qr__copy">
                                <span class="lk-dashboard-overline">Secure center QR</span>
                                <strong data-attendance-countdown class="mt-2 block">Logistics QR · refreshes every 20 seconds</strong>
                                <p data-attendance-qr-status aria-live="polite">Loading the Logistics attendance QR…</p>
                                <p>Each driver may scan the current center QR once. Their next scan automatically clocks them out. Or scan the driver’s personal QR here.</p>
                            </div>
                        </div>
                        <div data-attendance-mode-panel="scanner" aria-label="Scan the Interhub driver's QR" hidden>
                            <div data-attendance-qr-scanner data-scan-url="{{ route('logistics.interhub-attendance.scan') }}" class="lk-attendance-scanner">
                                <h3>Scan the driver’s QR</h3>
                                <p>The QR identifies the driver and this center. Assignment is optional.</p>
                                <div class="lk-attendance-scanner__viewport relative mt-3 overflow-hidden border border-line bg-black"><video data-attendance-video class="hidden aspect-video w-full object-cover" playsinline muted></video><div data-attendance-placeholder class="flex aspect-video items-center justify-center px-4 text-center text-xs text-white">Camera is off.</div></div>
                                <div class="mt-3 flex gap-2"><button type="button" data-attendance-start class="lk-dashboard-button lk-dashboard-button--primary">Start Camera</button><button type="button" data-attendance-stop class="hidden lk-dashboard-button">Stop</button></div>
                                <p data-attendance-message class="mt-2 min-h-5 text-xs font-semibold text-muted" aria-live="polite"></p>
                            </div>
                        </div>
                    </div>
                    <div data-attendance-active-list data-active-url="{{ route('logistics.interhub-attendance.active') }}" class="lk-attendance-roster">
                        <div class="lk-attendance-roster__head"><h3>Today’s attendance</h3><span>Regular daily target: 8 hours</span></div>
                        <div data-active-cards>
                            @forelse($interhubAttendanceRoster as $attendance)
                                <article class="lk-attendance-roster-card"><div class="flex items-center justify-between gap-2"><strong>{{ $attendance['driver_name'] }}</strong><span class="font-bold {{ $attendance['status'] === 'ABSENT' ? 'text-red-700' : ($attendance['status'] === 'ON SHIFT' ? 'text-primary' : 'text-emerald-700') }}">{{ $attendance['status'] }}</span></div><p class="mt-1 text-muted">{{ $attendance['hours'] }}h / 8h · {{ $attendance['clock_in_at'] ? 'Time In '.$attendance['clock_in_at'] : 'No Time In recorded today' }}</p><p class="mt-1 text-muted">{{ $attendance['manifest'] ?? 'No container assignment' }}</p><p class="mt-1 font-semibold text-ink">Estimated today: ₱{{ number_format($attendance['pay_estimate'], 2) }}{{ $attendance['worked_minutes'] >= 480 ? ' · daily target reached' : '' }}</p></article>
                            @empty
                                <p class="text-xs text-muted">No active Interhub driver profiles at this center.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </section>

    @if($timekeepingReady)
        <script>document.addEventListener('DOMContentLoaded',()=>{const buttons=[...document.querySelectorAll('[data-attendance-mode-button]')],panels=[...document.querySelectorAll('[data-attendance-mode-panel]')];const show=(mode)=>{for(const button of buttons){const selected=button.dataset.attendanceModeButton===mode;button.setAttribute('aria-pressed',selected?'true':'false');button.classList.toggle('bg-primary',selected);button.classList.toggle('text-white',selected);button.classList.toggle('text-ink',!selected);}for(const panel of panels){panel.hidden=panel.dataset.attendanceModePanel!==mode;}if(mode!=='scanner'){const stop=document.querySelector('[data-attendance-qr-scanner] [data-attendance-stop]');if(stop&&!stop.classList.contains('hidden'))stop.click();}};for(const button of buttons)button.addEventListener('click',()=>show(button.dataset.attendanceModeButton));show('qr');});</script>
        <script>document.addEventListener('DOMContentLoaded',()=>{const img=document.querySelector('[data-attendance-qr]'),status=document.querySelector('[data-attendance-qr-status]'),base=img?.dataset.qrBase;if(!img||!status||!base)return;const refresh=async()=>{status.textContent='Issuing a fresh attendance QR…';try{const response=await fetch(base+'?t='+Date.now(),{credentials:'same-origin',headers:{Accept:'application/json'}});const payload=await response.json();if(!response.ok||payload.success===false)throw new Error(payload.message||'Could not issue the Logistics QR.');img.src=payload.qr_data_uri;img.classList.remove('hidden');status.textContent='Ready to scan · refreshes automatically every 20 seconds.';}catch(error){img.classList.add('hidden');status.textContent=error.message||'Could not issue the Logistics QR.';}};void refresh();setInterval(()=>void refresh(),19500);});</script>
        <script>document.addEventListener('DOMContentLoaded',()=>{const panel=document.querySelector('[data-attendance-active-list]'),cards=panel?.querySelector('[data-active-cards]');if(!panel||!cards)return;const refresh=async()=>{try{const response=await fetch(panel.dataset.activeUrl,{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok)return;const payload=await response.json();if(payload.success!==true)return;cards.replaceChildren();for(const entry of payload.attendance){const card=document.createElement('article');card.className='rounded-lg border border-line bg-page-secondary p-3 text-xs';const header=document.createElement('div');header.className='flex items-center justify-between gap-2';const name=document.createElement('strong');name.textContent=entry.driver_name;const badge=document.createElement('span');badge.className='font-bold '+(entry.status==='ABSENT'?'text-red-700':entry.status==='ON SHIFT'?'text-primary':'text-emerald-700');badge.textContent=entry.status;header.append(name,badge);const hours=document.createElement('p');hours.className='mt-1 text-muted';hours.textContent=entry.hours+'h / 8h · '+(entry.clock_in_at?'Time In '+entry.clock_in_at:'No Time In recorded today');const manifest=document.createElement('p');manifest.className='mt-1 text-muted';manifest.textContent=entry.manifest||'No container assignment';const pay=document.createElement('p');pay.className='mt-1 font-semibold text-ink';pay.textContent='Estimated today: ₱'+Number(entry.pay_estimate).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})+(entry.worked_minutes>=480?' · daily target reached':'');card.append(header,hours,manifest,pay);cards.append(card);}if(!payload.attendance.length){const empty=document.createElement('p');empty.className='text-xs text-muted';empty.textContent='No active Interhub driver profiles at this center.';cards.append(empty);}}catch{}};void refresh();setInterval(refresh,20000);});</script>
        @push('scripts') @vite('resources/js/shared/interhub-attendance.js') @endpush
    @endif

    <div class="lk-dashboard-lower">
        <section class="lk-dashboard-card" aria-labelledby="activity-heading">
            <div class="lk-dashboard-card-head">
                <div class="lk-dashboard-card-head__copy">
                    <span class="lk-dashboard-overline">Parcel history</span>
                    <h2 id="activity-heading">Recent Shipment Activity</h2>
                    <p>Latest parcel updates at this center.</p>
                </div>
                <a href="{{ route('logistics.parcels') }}" class="lk-dashboard-button">View All <span aria-hidden="true">→</span></a>
            </div>
            <div class="lk-dashboard-table-wrap">
                <table class="lk-dashboard-table">
                    <thead><tr><th>Tracking</th><th>Status</th><th>Origin / Destination</th><th>Last event</th><th>Rider</th><th></th></tr></thead>
                    <tbody data-recent-shipments>
                        @forelse($recentShipments as $shipment)
                            <tr>
                                <td>{{ $shipment->tracking_number }}</td>
                                <td><span class="lk-dashboard-table__status">{{ str($shipment->current_status)->headline() }}</span></td>
                                <td>{{ $shipment->originLogisticsCenter?->business_name ?? '—' }} / {{ $shipment->destinationLogisticsCenter?->business_name ?? '—' }}</td>
                                <td>{{ $shipment->latestEvent?->occurred_at?->format('M d, Y g:i A') ?? 'No event yet' }}</td>
                                <td>{{ $shipment->latestRiderAssignment?->riderProfile?->user?->name ?? 'Unassigned' }}</td>
                                <td><a href="{{ route('logistics.parcels.show', $shipment) }}" class="lk-dashboard-table__action">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="lk-dashboard-empty">No recent parcel activity at this center.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="lk-dashboard-card" aria-labelledby="quick-actions-heading">
            <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Shortcuts</span><h2 id="quick-actions-heading">Quick Actions</h2><p>Jump directly to a work queue.</p></div></div>
            <div class="lk-dashboard-quicklinks">
                @foreach([
                    ['Incoming Parcels', 'logistics.parcels.receive'],
                    ['Parcel Sorting', 'logistics.sorting'],
                    ['Rider Management', 'logistics.riders'],
                    ['Pickup Management', 'logistics.pickups'],
                    ['Inter-hub Transfers', 'logistics.transfers'],
                    ['All Parcels', 'logistics.parcels'],
                    ['Reports', 'logistics.reports'],
                ] as [$label, $route])
                    <a href="{{ route($route) }}" class="lk-dashboard-quicklink"><span>{{ $label }}</span><span aria-hidden="true">→</span></a>
                @endforeach
            </div>
        </section>
    </div>

    <section class="lk-dashboard-card" aria-labelledby="finance-heading">
        <div class="lk-dashboard-card-head">
            <div class="lk-dashboard-card-head__copy lk-dashboard-finance-note">
                <span class="lk-dashboard-overline">Settlement overview</span>
                <h2 id="finance-heading">COD and earnings reconciliation</h2>
                <p>Current all-time totals. Allocated or pending shares are not wallet balance until Admin records a payout.</p>
            </div>
            <a href="{{ route('logistics.remittances') }}" class="lk-dashboard-button">Review remittances <span aria-hidden="true">→</span></a>
        </div>
        <div class="lk-dashboard-finance-grid">
            @foreach($financeMetrics as [$label, $key])
                @php($isAttentionMetric = in_array($key, ['cod_awaiting_remittance', 'discrepancies', 'pending_settlement'], true))
                <div class="lk-dashboard-finance-metric {{ $isAttentionMetric ? 'lk-dashboard-finance-metric--attention' : '' }}">
                    <span>{{ $label }}</span>
                    <strong data-finance-kpi="{{ $key }}" data-finance-type="{{ $key === 'discrepancies' ? 'count' : 'currency' }}">{{ $key === 'discrepancies' ? number_format((int) $financeSummary[$key]) : 'PHP '.number_format((float) $financeSummary[$key], 2) }}</strong>
                </div>
            @endforeach
        </div>
    </section>
</div>

@push('scripts')
<script>
(() => {
    const root = document.querySelector('[data-dashboard-snapshot]');
    if (!root) return;

    const numberFormat = new Intl.NumberFormat('en-PH');
    const currencyFormat = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const shipmentStatuses = ['pending', 'in_transit', 'delivered', 'failed'];
    const chartColors = { pending: '#C88418', in_transit: '#8B4D43', delivered: '#3B8A5D', failed: '#C5483E' };
    let refreshing = false;

    const updateDashboard = (payload) => {
        const counts = payload.counts || {};
        const total = shipmentStatuses.reduce((sum, key) => sum + (Number(counts[key]) || 0), 0);
        const percent = (key) => total ? Math.round((Number(counts[key] || 0) / total) * 1000) / 10 : 0;

        const totalNode = root.querySelector('[data-categorized-total]');
        if (totalNode) totalNode.textContent = `${numberFormat.format(total)} current parcels`;

        for (const key of shipmentStatuses) {
            const formattedCount = numberFormat.format(Number(counts[key]) || 0);
            root.querySelectorAll(`[data-shipment-count="${key}"]`).forEach((node) => { node.textContent = formattedCount; });
            root.querySelectorAll(`[data-shipment-share="${key}"]`).forEach((node) => { node.textContent = `${percent(key)}%`; });
            const fill = root.querySelector(`[data-chart-fill="${key}"]`);
            if (fill) fill.style.width = `${percent(key)}%`;
            const bar = root.querySelector(`[data-chart-bar="${key}"]`);
            if (bar) bar.setAttribute('aria-label', `${key.replace('_', ' ')}: ${formattedCount} parcels`);
        }

        const donut = root.querySelector('[data-shipment-donut]');
        if (donut) {
            let position = 0;
            const segments = shipmentStatuses.flatMap((key) => {
                const slice = total ? (Number(counts[key] || 0) / total) * 100 : 0;
                if (!slice) return [];
                const segment = `${chartColors[key]} ${position}% ${position + slice}%`;
                position += slice;
                return [segment];
            });
            donut.style.setProperty('--chart-gradient', segments.length ? `conic-gradient(${segments.join(', ')})` : 'conic-gradient(#EADCCC 0 100%)');
            donut.setAttribute('aria-label', `Shipment status pie chart: ${numberFormat.format(total)} current parcels`);
        }

        const emptyStatus = root.querySelector('[data-no-current-parcels]');
        if (emptyStatus) emptyStatus.hidden = total > 0;

        for (const [key, value] of Object.entries(payload.operational || {})) {
            const node = root.querySelector(`[data-operational-kpi="${key}"]`);
            if (node) node.textContent = numberFormat.format(Number(value) || 0);
        }

        for (const [key, value] of Object.entries(payload.financeSummary || {})) {
            const node = root.querySelector(`[data-finance-kpi="${key}"]`);
            if (!node) continue;
            node.textContent = node.dataset.financeType === 'count'
                ? numberFormat.format(Number(value) || 0)
                : `PHP ${currencyFormat.format(Number(value) || 0)}`;
        }

        const tbody = root.querySelector('[data-recent-shipments]');
        if (tbody) {
            const rows = (payload.recentShipments || []).map((shipment) => {
                const row = document.createElement('tr');
                const values = [shipment.tracking_number, shipment.status, `${shipment.origin} / ${shipment.destination}`, shipment.last_event, shipment.rider];
                values.forEach((value, index) => {
                    const cell = document.createElement('td');
                    if (index === 1) {
                        const status = document.createElement('span');
                        status.className = 'lk-dashboard-table__status';
                        status.textContent = value;
                        cell.append(status);
                    } else {
                        cell.textContent = value ?? '';
                    }
                    row.append(cell);
                });
                const actionCell = document.createElement('td');
                const action = document.createElement('a');
                action.className = 'lk-dashboard-table__action';
                action.href = shipment.url;
                action.textContent = 'View';
                actionCell.append(action);
                row.append(actionCell);
                return row;
            });

            if (!rows.length) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 6;
                cell.className = 'lk-dashboard-empty';
                cell.textContent = 'No recent parcel activity at this center.';
                row.append(cell);
                rows.push(row);
            }
            tbody.replaceChildren(...rows);
        }

    };

    const refresh = async () => {
        if (refreshing || document.visibilityState === 'hidden') return;
        refreshing = true;
        try {
            const response = await fetch(root.dataset.dashboardSnapshot, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('Dashboard update failed');
            updateDashboard(await response.json());
        } catch {
            // The next scheduled poll retries automatically.
        } finally {
            refreshing = false;
        }
    };

    void refresh();
    window.setInterval(refresh, 15000);
})();
</script>
@endpush
@endsection
