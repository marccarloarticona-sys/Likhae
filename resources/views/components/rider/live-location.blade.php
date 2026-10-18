@php
    use App\Models\Rider\RiderAssignment;
    use App\Models\User;

    $riderUser = auth()->user();
    $riderProfile = $riderUser?->riderProfile;
    $riderLiveAssignments = collect();

    if ($riderUser?->isAccountType(User::TYPE_RIDER)
        && $riderUser->isActive()
        && $riderProfile?->status === 'ACTIVE') {
        $riderLiveAssignments = RiderAssignment::query()
            ->where('rider_profile_id', $riderProfile->id)
            ->where(function ($query): void {
                $query->where(function ($active): void {
                    $active->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
                        ->where(function ($assignment): void {
                            $assignment->where(function ($pickup): void {
                                $pickup->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                                    ->whereHas('shipment', fn ($shipment) => $shipment->where('current_status', 'PICKUP_ASSIGNED'));
                            })->orWhere(function ($delivery): void {
                                $delivery->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                                    ->whereHas('shipment', fn ($shipment) => $shipment->whereIn('current_status', ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY']));
                            });
                        });
                })->orWhere(function ($handoff): void {
                    $handoff->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                        ->where('status', 'COMPLETED')
                        ->whereHas('shipment', fn ($shipment) => $shipment->where('current_status', 'PICKED_UP'));
                });
            })
            ->orderBy('id')
            ->get(['id', 'assignment_type', 'status']);
    }

    $riderLivePayload = $riderLiveAssignments->map(fn (RiderAssignment $assignment): array => [
        'id' => $assignment->id,
        'type' => $assignment->assignment_type,
        'status' => $assignment->status,
        'endpoint' => route('rider.assignments.location', ['assignment' => $assignment->id]),
    ])->values()->all();
@endphp

@if($riderLivePayload)
    <section
        class="mx-auto mt-6 flex w-full max-w-[1500px] items-center justify-between gap-4 rounded-2xl border border-line bg-surface px-5 py-4 shadow-sm"
        data-rider-live-location
        data-rider-location-assignments="{{ json_encode($riderLivePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) }}"
    >
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Rider live GPS</p>
            <p class="mt-1 text-sm font-semibold text-ink" data-rider-location-status aria-live="polite">Starting device GPS for the active assignment…</p>
            <p class="mt-1 text-xs text-muted">Foreground browser tracking only. Keep this Rider page open while traveling.</p>
        </div>
        <button type="button" class="shrink-0 rounded-xl border border-line px-3 py-2 text-xs font-semibold text-ink" data-rider-location-toggle>Pause rider GPS</button>
    </section>
@endif
