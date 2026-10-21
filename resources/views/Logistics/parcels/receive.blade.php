@extends('layouts.logistics.app')
@section('title', 'Receive Parcels - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">Parcel intake</p><h1 class="mt-2 text-2xl font-bold text-ink">Receive at Sorting Center</h1><p class="mt-2 text-sm text-muted">Scan each waybill as the rider arrives. A matching scan records that parcel as received immediately.</p></header>
    <x-logistics.parcel-flow current="receive" />
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <x-shared.parcel-scanner :action="route('logistics.parcels.receive.scan')" :tracking="old('tracking', request('tracking', ''))" :method="old('scan_method', request('scan_method', 'MANUAL'))" title="Scan Incoming Parcel" description="Scan a seller pickup or an arriving hub-transfer parcel. A valid hub-transfer scan records destination receipt and sends the parcel to sorting for local delivery assignment." button="Receive Parcel" form-method="POST" input-name="tracking" input-label="Parcel tracking number" input-placeholder="Scan or enter tracking number" />
    <section class="space-y-4">
        <div><h2 class="text-lg font-bold text-ink">Incoming Hub Transfer Parcels</h2><p class="mt-1 text-sm text-muted">Scan these parcels with the incoming scanner above. The first valid scan also records the manifest arrival.</p></div>
        @forelse($incomingTransferItems as $item)
            @php($transferShipment = $item->transferLeg?->shipment)
            <article class="flex flex-col gap-2 border border-line bg-surface p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <strong class="text-sm text-primary">{{ $transferShipment?->tracking_number }}</strong>
                    <p class="mt-1 text-sm text-muted">{{ $item->manifest?->manifest_number }} from {{ $item->manifest?->originLogisticsCenter?->business_name ?? 'Origin hub' }}</p>
                    <p class="mt-1 text-xs text-muted">Manifest: {{ str($item->manifest?->status)->headline() }} · Awaiting destination scan</p>
                </div>
                @if($item->manifest)
                    <a href="{{ route('logistics.transfers.show', $item->manifest) }}" class="text-sm font-semibold text-primary underline">Open manifest</a>
                @endif
            </article>
        @empty
            <div class="border border-dashed border-line bg-surface p-6 text-sm text-muted">No hub-transfer parcels are waiting for destination receipt.</div>
        @endforelse
    </section>
    <section class="space-y-4">
        <div><h2 class="text-lg font-bold text-ink">Incoming Seller Pickup Batches</h2><p class="mt-1 text-sm text-muted">Expected and received parcel counts for riders arriving from seller pickups.</p></div>
        @php($firstBatchScan = true)
        @forelse($receivedBatches as $batch)
            @php($collectedItems = $batch->items->filter(fn ($item) => $item->riderAssignment?->status === 'COMPLETED'))
            @php($receivedItems = $collectedItems->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER'))
            @php($pendingItems = $collectedItems->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'PICKED_UP'))
            <article class="border border-line bg-surface p-5" data-receive-batch="{{ $batch->id }}">
                <header class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-wide text-primary">Pickup Batch #P-{{ $batch->id }}</p><h3 class="mt-1 font-bold text-ink">{{ $batch->seller?->business_name }}</h3><p class="mt-1 text-sm text-muted">Rider: {{ $batch->rider?->user?->name }} · {{ $batch->seller?->businessAddress?->formatted() }}</p></div><strong class="text-sm text-ink" data-receive-count data-expected="{{ $collectedItems->count() }}">Expected: {{ $collectedItems->count() }} · Received: {{ $receivedItems->count() }}/{{ $collectedItems->count() }}</strong></header>
                <div class="mt-4 grid gap-3">
                    @foreach($collectedItems as $item)
                        @php($shipment = $item->riderAssignment?->shipment)
                        <div class="flex flex-col gap-3 border border-line p-3 sm:flex-row sm:items-center sm:justify-between" data-receive-row data-tracking="{{ $shipment?->tracking_number }}" data-receive-state="{{ $shipment?->current_status === 'AT_SORTING_CENTER' ? 'received' : 'pending' }}">
                            <div><strong class="text-sm text-ink">{{ $shipment?->tracking_number }}</strong><p class="mt-1 text-xs text-muted" data-receive-status>{{ $shipment?->current_status === 'AT_SORTING_CENTER' ? 'Received' : 'Pending intake scan' }}</p></div>
                            @if($shipment?->current_status === 'PICKED_UP')
                                <form method="POST" action="{{ route('logistics.parcels.receive.confirm', $shipment) }}" class="flex flex-wrap gap-2" data-scanner-async data-receive-form data-confirm-title="Confirm parcel intake?" data-confirm-message="Accept this parcel into the sorting center's custody after checking the waybill." data-confirm-label="Receive parcel">@csrf<input type="hidden" name="scan_method" value="BARCODE"><input name="scanned_code" required autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go" data-batch-scan-input data-expected-tracking="{{ $shipment?->tracking_number }}" @if($firstBatchScan) autofocus @php($firstBatchScan = false) @endif class="min-h-11 min-w-0 border border-line px-3 py-2 text-sm" placeholder="Scan this waybill" aria-label="Scan waybill {{ $shipment?->tracking_number }}"><button class="min-h-11 bg-primary px-4 py-2 text-sm font-semibold text-white">Receive Parcel</button></form>
                            @else
                                <span class="text-sm font-semibold text-green-700">✓ Received at Sorting Center</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <div class="border border-dashed border-line bg-surface p-6 text-sm text-muted">No seller pickup batches are arriving at this center.</div>
        @endforelse
    </section>

    <section class="grid gap-4">
        <h2 class="text-lg font-bold text-ink">Other Picked-Up Parcels</h2>
    @forelse($shipments as $shipment)
        <article class="border border-line bg-surface p-5" data-other-receive data-tracking="{{ $shipment->tracking_number }}"><div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div><a class="text-lg font-bold text-primary" href="{{ route('logistics.parcels.show',$shipment) }}">{{ $shipment->tracking_number }}</a><p class="mt-1 text-sm text-muted">{{ $shipment->sellerOrder?->sellerProfile?->business_name }} → {{ $shipment->sellerOrder?->order?->address?->formatted() }}</p><span class="text-xs font-semibold">{{ str($shipment->current_status)->headline() }}</span></div>
            <form method="POST" action="{{ route('logistics.parcels.receive.confirm',$shipment) }}" class="flex flex-wrap gap-2" data-scanner-async data-confirm-title="Confirm parcel intake?" data-confirm-message="Accept this parcel into the sorting center's custody after checking the waybill." data-confirm-label="Receive parcel">@csrf<input type="hidden" name="scan_method" value="{{ in_array(strtoupper((string) request('scan_method')), ['QR', 'BARCODE'], true) ? strtoupper((string) request('scan_method')) : 'MANUAL' }}"><input name="scanned_code" required value="{{ request('tracking') }}" placeholder="Scan or enter tracking number" data-batch-scan-input data-expected-tracking="{{ $shipment->tracking_number }}" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go" class="border border-line px-3 py-2"><button class="bg-primary px-4 py-2 font-semibold text-white">Receive Parcel</button></form>
        </div></article>
    @empty<section class="border border-dashed border-line bg-surface p-8 text-center text-muted">{{ request('tracking') ? 'No parcel matches that waybill in the receive queue.' : 'No other picked-up parcels are waiting to be received.' }}</section>@endforelse
    {{ $shipments->links() }}
    </section>
</div>
@push('scripts')
<script>
document.querySelectorAll('[data-batch-scan-input]').forEach((input) => {
    const form = input.form;
    let timer;
    const submitScan = () => {
        if (form?.dataset.scannerBusy === 'true' || input.value.trim() !== input.dataset.expectedTracking) return;
        form?.requestSubmit();
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        if (input.value.trim() === input.dataset.expectedTracking) timer = window.setTimeout(submitScan, 180);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        window.clearTimeout(timer);
        submitScan();
    });
});

document.addEventListener('parcel-scanner:success', (event) => {
    const { tracking } = event.detail || {};
    if (!tracking) return;

    const row = [...document.querySelectorAll('[data-receive-row]')]
        .find((candidate) => candidate.dataset.tracking === tracking);
    if (row) {
        row.dataset.receiveState = 'received';
        const status = row.querySelector('[data-receive-status]');
        if (status) {
            status.className = 'mt-1 text-xs font-semibold text-green-700';
            status.textContent = 'Received at Sorting Center';
        }
        row.querySelector('[data-receive-form]')?.remove();

        const batch = row.closest('[data-receive-batch]');
        const count = batch?.querySelector('[data-receive-count]');
        if (count) {
            const received = batch.querySelectorAll('[data-receive-row][data-receive-state="received"]').length;
            count.textContent = `Expected: ${count.dataset.expected} · Received: ${received}/${count.dataset.expected}`;
        }
    }

    document.querySelectorAll('[data-other-receive]').forEach((card) => {
        if (card.dataset.tracking === tracking) card.remove();
    });
});
</script>
@endpush
@endsection
