@extends('layouts.logistics.app')
@section('title', 'Return to Seller - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">Reverse custody</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">Return parcels to sellers</h1>
        <p class="mt-2 max-w-3xl text-sm text-muted">Confirm the original delivery rider’s center handoff, assign a return rider, and follow the parcel until the seller confirms receipt. Shipment status remains DELIVERY_FAILED until the last confirmation.</p>
    </header>

    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    <section class="grid gap-4 md:grid-cols-3">
        <div class="border border-line bg-surface p-5"><p class="text-sm text-muted">Awaiting center handoff</p><strong class="mt-2 block text-2xl text-ink">{{ $workflowCounts['AWAITING_CENTER_HANDOFF'] ?? 0 }}</strong></div>
        <div class="border border-line bg-surface p-5"><p class="text-sm text-muted">Awaiting center receipt</p><strong class="mt-2 block text-2xl text-ink">{{ $workflowCounts['AWAITING_CENTER_RECEIPT'] ?? 0 }}</strong></div>
        <div class="border border-line bg-surface p-5"><p class="text-sm text-muted">Awaiting return rider assignment</p><strong class="mt-2 block text-2xl text-ink">{{ $workflowCounts['READY_FOR_RETURN_ASSIGNMENT'] ?? 0 }}</strong></div>
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
                        <p class="mt-2 text-sm">Original delivery rider: <strong>{{ $workflow->originalDeliveryAssignment?->riderProfile?->user?->name ?? 'Unavailable' }}</strong></p>
                        @if($workflow->returnRider)<p class="mt-1 text-sm">Return rider: <strong>{{ $workflow->returnRider->user?->name ?? 'Unavailable' }}</strong></p>@endif
                        <p class="mt-1 text-xs text-muted">Started {{ $workflow->initiated_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'time unavailable' }} · Shipment status: {{ str($shipment?->current_status)->replace('_', ' ')->title() }}</p>
                        @php($custodySteps = collect([
                            ['label' => 'Return initiated', 'actor' => $workflow->initiatedBy?->name, 'at' => $workflow->initiated_at],
                            ['label' => 'Original rider handed parcel to center', 'actor' => $workflow->centerHandoffBy?->name, 'at' => $workflow->center_handoff_at],
                            ['label' => 'Center confirmed receipt', 'actor' => $workflow->centerReceivedBy?->name, 'at' => $workflow->center_received_at],
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
