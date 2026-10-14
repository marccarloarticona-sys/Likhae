@extends('Rider.app')
@section('title', 'Assigned Parcels')

@section('content')
<div class="space-y-6">
    <header><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Operations</p><h1 class="mt-2 text-2xl font-bold text-ink">Assigned Parcels</h1><p class="mt-1 text-sm text-muted">Accept assignments, start work, and record the final pickup or delivery result.</p></header>
    @if(session('status'))<div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    @forelse($assignments as $assignment)
        @php($shipment = $assignment->shipment)
        <article class="rounded-2xl border border-line bg-surface p-5">
            @if($shipment?->sellerOrder?->items?->contains(fn ($item): bool => $item->hasAdditionalCare()))<div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold uppercase tracking-wide text-amber-900">Fragile / Additional Care — handling flag</div>@endif
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div><span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">{{ str($assignment->assignment_type)->headline() }}</span><strong class="mt-2 block text-xl font-bold text-ink">{{ $shipment->tracking_number }}</strong><p class="mt-1 text-sm text-muted">{{ str($assignment->status)->headline() }} · Parcel {{ str($shipment->current_status)->headline() }}</p>@if($assignment->assignment_type === 'DELIVERY')<p class="mt-2 max-w-xl text-sm text-ink"><strong>Deliver to:</strong> {{ $shipment->sellerOrder?->order?->address?->formatted() }}</p>@else<p class="mt-2 max-w-xl text-sm text-ink"><strong>Collect from:</strong> {{ $shipment->sellerOrder?->sellerProfile?->businessAddress?->formatted() }}</p>@endif</div>
                <div class="flex flex-wrap gap-2">
                    @if($assignment->status === 'ASSIGNED')
                        <form method="POST" action="{{ route('rider.shipments.transition', $assignment) }}" data-confirm-title="Accept this assignment?" data-confirm-message="Accept responsibility for this shipment assignment?" data-confirm-label="Accept"><input type="hidden" name="action" value="accept">@csrf @method('PATCH')<button class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white">Accept</button></form>
                        <form method="POST" action="{{ route('rider.shipments.transition', $assignment) }}" data-confirm-title="Reject this assignment?" data-confirm-message="Reject this shipment assignment using the reason entered above?" data-confirm-label="Reject" data-confirm-tone="danger">@csrf @method('PATCH')<input type="hidden" name="action" value="reject"><input name="reason" required placeholder="Reason" class="rounded-lg border border-line px-3 text-sm"><button class="rounded-lg border border-line px-3 py-2 text-sm font-semibold">Reject</button></form>
                    @elseif($assignment->status === 'ACCEPTED')
                        <form method="POST" action="{{ route('rider.shipments.transition', $assignment) }}" data-confirm-title="Start this shipment?" data-confirm-message="Start the {{ str($assignment->assignment_type)->lower() }} process for this parcel?" data-confirm-label="Start"><input type="hidden" name="action" value="start">@csrf @method('PATCH')<button class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white">Start {{ str($assignment->assignment_type)->lower() }}</button></form>
                    @elseif($assignment->status === 'IN_PROGRESS' && $assignment->assignment_type === 'PICKUP')
                        <a href="{{ route('rider.pickups.show', $assignment) }}" class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white">Scan and Confirm Pickup</a>
                    @elseif($assignment->status === 'IN_PROGRESS' && $assignment->assignment_type === 'DELIVERY')
                        <a href="{{ route('rider.deliveries.show', $assignment) }}" class="rounded-lg bg-green-700 px-3 py-2 text-sm font-semibold text-white">Add Proof &amp; Deliver</a>
                        <form method="POST" action="{{ route('rider.shipments.transition', $assignment) }}" class="flex flex-wrap gap-2" data-confirm-title="Record delivery outcome?" data-confirm-message="Submit the delivery failure, reschedule, or return details entered above?" data-confirm-label="Submit outcome" data-confirm-tone="danger">@csrf @method('PATCH')<input type="hidden" name="action" value="delivery_failed"><input name="failure_reason" required placeholder="Failure reason" class="rounded-lg border border-line px-3 text-sm"><select name="attempt_status" class="rounded-lg border border-line px-3 text-sm"><option value="FAILED">Failed attempt</option><option value="RESCHEDULED">Reschedule</option><option value="RETURNED">Return to seller</option></select><input type="datetime-local" name="next_attempt_at" class="rounded-lg border border-line px-3 text-sm" aria-label="Next delivery attempt"><button class="rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-700">Submit Failure</button></form>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <section class="rounded-2xl border border-dashed border-line bg-surface p-8 text-center"><h2 class="text-xl font-bold text-ink">No assigned parcels</h2><p class="mt-2 text-sm text-muted">New assignments will appear here.</p></section>
    @endforelse

    <div>{{ $assignments->links() }}</div>
</div>
@endsection
