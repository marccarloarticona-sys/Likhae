@extends('layouts.logistics.app')
@section('title', 'Return to Seller - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">Reverse custody</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">Return parcels to sellers</h1>
        <p class="mt-2 max-w-3xl text-sm text-muted">Receive undelivered parcels after the rider completes the route, then route each parcel for redelivery or return to seller. Shipment status remains DELIVERY_FAILED until seller receipt is confirmed.</p>
    </header>

    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    <section class="grid gap-4 md:grid-cols-3">
        <div class="border border-line bg-surface p-5"><p class="text-sm text-muted">Awaiting center handoff</p><strong class="mt-2 block text-2xl text-ink">{{ $workflowCounts['AWAITING_CENTER_HANDOFF'] ?? 0 }}</strong></div>
        <div class="border border-line bg-surface p-5"><p class="text-sm text-muted">Awaiting center receipt</p><strong class="mt-2 block text-2xl text-ink">{{ $workflowCounts['AWAITING_CENTER_RECEIPT'] ?? 0 }}</strong></div>
        <div class="border border-line bg-surface p-5"><p class="text-sm text-muted">Awaiting return rider assignment</p><strong class="mt-2 block text-2xl text-ink">{{ $workflowCounts['READY_FOR_RETURN_ASSIGNMENT'] ?? 0 }}</strong></div>
    </section>

    <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.75fr)]">
        <form method="POST" action="{{ route('logistics.returns.receive-batch') }}" class="grid gap-3 border border-line bg-surface p-5">
            @csrf
            <div>
                <h2 class="text-base font-bold text-ink">Receive parcels from a completed rider route</h2>
                <p class="mt-1 text-sm text-muted">Scan or enter tracking numbers, one per line. Each parcel is validated and recorded separately; a failed scan does not confirm receipt.</p>
            </div>
            <label class="grid gap-1 text-sm font-semibold">Tracking numbers
                <textarea name="tracking_numbers" rows="4" maxlength="10000" required class="w-full border border-line p-3 font-mono text-sm" placeholder="LK...&#10;LK..."></textarea>
            </label>
            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm font-semibold">Scan method
                    <select name="scan_method" required class="border border-line bg-white px-3 py-2 text-sm">
                        <option value="MANUAL">Manual entry</option><option value="QR">QR scan</option><option value="BARCODE">Barcode scan</option>
                    </select>
                </label>
                <button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Verify and receive parcels</button>
            </div>
            @error('tracking_numbers')<p class="text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
        </form>
        @if(session('returnBatchResults'))
            <section class="border border-line bg-surface p-5" aria-live="polite">
                <h2 class="text-base font-bold text-ink">Batch scan results</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach(session('returnBatchResults') as $result)
                        <li class="flex flex-wrap justify-between gap-2 border-t border-line pt-2">
                            <strong class="font-mono">{{ $result['tracking_number'] }}</strong>
                            <span class="{{ $result['success'] ? 'text-green-800' : 'text-red-800' }}">{{ $result['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            <aside class="border border-line bg-surface p-5 text-sm text-muted">
                <strong class="text-ink">Custody rule</strong>
                <p class="mt-2">A rider remains responsible for a parcel until this center confirms a matching scan. Center receipt does not mark a parcel returned to the seller.</p>
            </aside>
        @endif
    </section>

    <section class="grid gap-4">
        @forelse($workflows as $workflow)
            @php($shipment = $workflow->shipment)
            <article class="border border-line bg-surface p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase text-primary">{{ str($workflow->status)->replace('_', ' ')->title() }}</p>
                        <h2 class="mt-1 text-lg font-bold text-ink">{{ $shipment?->tracking_number }} <span class="text-sm font-normal text-muted">· {{ $shipment?->sellerOrder?->seller_order_number }}</span></h2>
                        <p class="mt-1 text-sm text-muted">Reason: {{ $workflow->reason }}</p>
                        @if($returnEnhancementsReady)
                            <p class="mt-1 text-sm">Disposition: <strong>{{ $workflow->return_disposition === 'REDELIVERY' ? 'Return to center for redelivery' : 'Return to seller' }}</strong></p>
                        @endif
                        <p class="mt-2 text-sm">Original delivery rider: <strong>{{ $workflow->originalDeliveryAssignment?->riderProfile?->user?->name ?? 'Unavailable' }}</strong></p>
                        @if($workflow->returnRider)<p class="mt-1 text-sm">Return rider: <strong>{{ $workflow->returnRider->user?->name ?? 'Unavailable' }}</strong></p>@endif
                        @if($returnEnhancementsReady && $workflow->pendingReturnRider)<p class="mt-1 text-sm">Proposed receiving rider: <strong>{{ $workflow->pendingReturnRider->user?->name ?? 'Unavailable' }}</strong></p>@endif
                        <p class="mt-1 text-xs text-muted">Started {{ $workflow->initiated_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'time unavailable' }} · Shipment status: {{ str($shipment?->current_status)->replace('_', ' ')->title() }}</p>
                        @if($workflow->status === 'AWAITING_CENTER_HANDOFF')
                            <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">The original rider still holds this parcel. It is expected back after the delivery route is completed.</p>
                        @elseif($workflow->status === 'AWAITING_CENTER_RECEIPT')
                            <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">The rider presented the parcel. Confirm its matching scan below to transfer custody to this center.</p>
                        @elseif($workflow->status === 'CENTER_RECEIVED_FOR_REDELIVERY')
                            <p class="mt-2 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">Center custody is confirmed. This parcel remains eligible for a delivery retry; it has not been returned to the seller.</p>
                        @elseif($workflow->status === 'AWAITING_CROSS_CENTER_TRANSFER')
                            <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Center custody is confirmed. The parcel must travel to the seller-side center before a return rider can be assigned.</p>
                        @elseif($workflow->status === 'RETURN_RIDER_HANDOFF_PENDING')
                            <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Rider handoff is pending two tracking scans. Current custody stays with {{ $workflow->returnRider?->user?->name ?? 'the current rider' }} until both riders confirm.</p>
                            @if($returnEnhancementsReady)
                                <p class="mt-1 text-xs text-muted">Current rider scan: {{ $workflow->handoff_from_confirmed_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'pending' }} · Receiving rider scan: {{ $workflow->handoff_to_confirmed_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'pending' }}</p>
                            @if($workflow->handoff_reason)<p class="mt-1 text-xs text-muted">Handoff reason: {{ $workflow->handoff_reason }}</p>@endif
                            <form method="POST" action="{{ route('logistics.returns.handoff.cancel', $workflow) }}" class="mt-3 grid gap-2">
                                @csrf
                                <label class="text-xs font-semibold">Stop handoff and retain current custody
                                    <input name="reason" required minlength="5" maxlength="1000" class="mt-1 w-full border border-line p-2" placeholder="Explain why the handoff cannot be completed">
                                </label>
                                <button class="justify-self-start border border-red-300 px-3 py-2 text-xs font-semibold text-red-800">Move to exception review</button>
                            </form>
                            @endif
                        @elseif($workflow->status === 'RETURN_EXCEPTION')
                            <p class="mt-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"><strong>Logistics review required.</strong> {{ $workflow->return_exception_reason ?? 'A custody exception needs review before the parcel can continue.' }}</p>
                        @endif
                        @php($custodySteps = collect([
                            ['label' => 'Return initiated', 'actor' => $workflow->initiatedBy?->name, 'at' => $workflow->initiated_at],
                            ['label' => 'Original rider handed parcel to center', 'actor' => $workflow->centerHandoffBy?->name, 'at' => $workflow->center_handoff_at],
                            ['label' => 'Center confirmed receipt', 'actor' => $workflow->centerReceivedBy?->name, 'at' => $workflow->center_received_at],
                            ['label' => 'Return rider handoff authorized', 'actor' => $workflow->handoffAuthorizedBy?->name, 'at' => $workflow->handoff_authorized_at],
                            ['label' => 'Return rider assigned', 'actor' => $workflow->returnRiderAssignedBy?->name, 'at' => $workflow->return_rider_assigned_at],
                            ['label' => 'Return rider accepted', 'actor' => $workflow->returnRider?->user?->name, 'at' => $workflow->return_rider_accepted_at],
                            ['label' => 'Return rider collected parcel', 'actor' => $workflow->returnRiderPickedUpBy?->name, 'at' => $workflow->return_rider_picked_up_at],
                            ['label' => 'Rider handed parcel to seller', 'actor' => $workflow->sellerHandoffBy?->name, 'at' => $workflow->seller_handoff_at],
                            ['label' => 'Seller confirmed receipt', 'actor' => $workflow->sellerReceivedBy?->name, 'at' => $workflow->seller_received_at],
                        ])->filter(fn ($step) => $step['at'])->values())
                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer font-semibold text-primary">Custody history ({{ $custodySteps->count() }} steps)</summary>
                            <ol class="mt-2 space-y-1 border-l border-line pl-4">
                                @foreach($custodySteps as $step)
                                    <li><strong>{{ $step['label'] }}</strong><small class="ml-1 text-muted">{{ $step['actor'] ?: 'Account unavailable' }} · {{ $step['at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</small></li>
                                @endforeach
                            </ol>
                        </details>
                    </div>

                    @if($workflow->status === 'AWAITING_CENTER_RECEIPT')
                        <form method="POST" action="{{ route('logistics.returns.receive', $workflow) }}" class="grid w-full gap-2 lg:max-w-sm">
                            @csrf
                            <label class="text-sm font-semibold">Center receipt scan</label>
                            <x-shared.parcel-scan-input name="scanned_code" method-name="scan_method" :id="'return-center-scan-'.$workflow->id" :value="old('scanned_code', '')" />
                            <button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Confirm parcel received</button>
                        </form>
                    @elseif($workflow->status === 'AWAITING_CROSS_CENTER_TRANSFER')
                        <div class="grid w-full gap-2 lg:max-w-sm">
                            @if($transferReady)
                                <form method="POST" action="{{ route('logistics.returns.prepare-transfer', $workflow) }}">@csrf<button class="w-full border border-primary px-4 py-2 text-sm font-semibold text-primary">Prepare or recheck transfer leg</button></form>
                                <a href="{{ route('logistics.transfers') }}" class="text-sm font-semibold text-primary underline">Add this parcel to a center transfer manifest</a>
                            @else
                                <p class="text-sm text-amber-800">Transfer manifest schema is unavailable. Seller-side transfer is paused until the required schema is deployed.</p>
                            @endif
                        </div>
                    @elseif($workflow->status === 'IN_TRANSIT_TO_SELLER' && $returnEnhancementsReady)
                        <div class="grid w-full gap-3 lg:max-w-sm">
                            <p class="text-sm text-muted">After pickup, reassignment requires the current rider and receiving rider to scan the tracking number. The current rider remains responsible until both scans succeed.</p>
                            <form method="POST" action="{{ route('logistics.returns.handoff', $workflow) }}" class="grid gap-2">
                                @csrf
                                <label class="text-sm font-semibold">Authorize a different return rider
                                    <select class="mt-1 w-full border border-line p-2" name="rider_profile_id" required>
                                        <option value="">Choose an active rider</option>
                                        @foreach($riders as $rider)<option value="{{ $rider->id }}">{{ $rider->user?->name ?? 'Rider #'.$rider->id }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="text-sm font-semibold">Reason<input name="reason" maxlength="1000" required class="mt-1 w-full border border-line p-2" value="{{ old('reason') }}"></label>
                                <button class="border border-primary px-4 py-2 text-sm font-semibold text-primary">Start verified handoff</button>
                            </form>
                        </div>
                    @elseif($workflow->status === 'RETURN_EXCEPTION' && $returnEnhancementsReady)
                        <form method="POST" action="{{ route('logistics.returns.exceptions.resolve', $workflow) }}" class="grid w-full gap-2 lg:max-w-sm">
                            @csrf
                            <label class="text-sm font-semibold">Logistics resolution notes
                                <textarea name="resolution_notes" rows="3" minlength="5" maxlength="1000" required class="mt-1 w-full border border-line p-2" placeholder="Record what was reviewed and why it is safe to continue"></textarea>
                            </label>
                            <button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Resolve and resume return</button>
                        </form>
                    @elseif(in_array($workflow->status, ['READY_FOR_RETURN_ASSIGNMENT', 'ASSIGNED_TO_RETURN_RIDER', 'RETURN_RIDER_ACCEPTED'], true))
                        <form method="POST" action="{{ route('logistics.returns.assign', $workflow) }}" class="flex w-full flex-col gap-2 sm:flex-row lg:max-w-lg">
                            @csrf
                            <label class="flex-1 text-sm font-semibold">Assign return rider
                                <select class="mt-1 w-full border border-line p-2" name="rider_profile_id" required>
                                    <option value="">Choose an active rider</option>
                                    @foreach($riders as $rider)<option value="{{ $rider->id }}">{{ $rider->user?->name ?? 'Rider #'.$rider->id }} · {{ str($rider->status)->headline() }}</option>@endforeach
                                </select>
                            </label>
                            <button class="self-end bg-primary px-4 py-2 text-sm font-semibold text-white">{{ $workflow->return_rider_profile_id ? 'Reassign return rider' : 'Assign rider' }}</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <div class="border border-line bg-surface p-8 text-center text-sm text-muted">No failed-delivery returns are waiting at this center.</div>
        @endforelse
    </section>
    {{ $workflows->links() }}
</div>
@endsection
