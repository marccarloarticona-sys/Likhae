@extends('layouts.logistics.app')

@section('title', 'Rider Profile - LIKHAE Logistics')

@php
    $name = $rider->user?->name ?: 'Rider Account';
    $activeAreas = $rider->areaAssignments
        ->where('is_active', true)
        ->pluck('serviceArea.name')
        ->filter()
        ->values();
    $activeAreaId = $rider->areaAssignments->firstWhere('is_active', true)?->service_area_id;
    $activeAssignments = (int) ($rider->active_assignments_count ?? 0);
    $ratingAverage = (float) ($rider->rating_average ?? 0);
    $ratingCount = (int) ($rider->rating_count ?? 0);
    $isLocalRider = \App\Support\RiderVehicleType::isLocalBarangay($rider->vehicle_type);
    $isInterLogisticsRider = \App\Support\RiderVehicleType::isInterLogistics($rider->vehicle_type);
    $manifestSchemaReady = (bool) ($manifestSchemaReady ?? false);
    $localTripsSchemaReady = (bool) ($localTripsSchemaReady ?? false);
    $riderWarningCount = (int) ($riderWarningCount ?? 0);
    $isPermanentlyBanned = $rider->status === 'BANNED' || (bool) $rider->user?->isPermanentlyBanned();
    $isRiderSuspended = ! $isPermanentlyBanned && ($rider->status === 'SUSPENDED' || $rider->user?->status === \App\Models\User::STATUS_SUSPENDED);
    $initials = collect(explode(' ', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: 'R';

    $details = [
        ['Full Name', $name],
        ['Email', $rider->user?->email ?: 'Not recorded'],
        ['Contact', $rider->user?->contact_number ?: 'Not recorded'],
        ['Role', $isLocalRider ? 'Local Rider' : ($isInterLogisticsRider ? 'Inter-Hub Driver' : 'Unclassified')],
        ['Vehicle', $rider->vehicle_type ? str($rider->vehicle_type)->headline() : 'Not recorded'],
        ['Plate Number', $rider->plate_number ?: 'Not recorded'],
        ["Driver's License Number", $rider->drivers_license_number ?: 'Not recorded'],
        ['Account Status', $rider->user?->status ?: 'Not recorded'],
        ['Approval Status', $rider->approvedRegistrationApplication?->status ?: 'Not recorded'],
        ['Assigned Logistics Center', $center->business_name.' ('.$center->code.')'],
        ['Delivery Area', $isLocalRider ? ($activeAreas->join(', ') ?: 'Unassigned') : ($isInterLogisticsRider ? 'Not used for hub-to-hub transfers' : 'Not applicable')],
        ['Status', $rider->status ? str($rider->status)->headline() : 'Not recorded'],
        ['Availability', $isLocalRider ? ($rider->is_online ? 'Online' : 'Offline') : ($isInterLogisticsRider ? 'Transfer assignment' : 'Not applicable')],
        ['Active parcel assignments', $isLocalRider ? (int) ($rider->active_assignments_count ?? 0) : ($isInterLogisticsRider ? 'Handled through transfer manifests' : 'Not applicable')],
        ['Deliveries Completed Today', $isLocalRider ? (int) $rider->assignments->where('assignment_type', 'DELIVERY')->where('status', 'COMPLETED')->filter(fn ($assignment) => $assignment->completed_at?->isToday())->count() : ($isInterLogisticsRider ? 'Handled through transfer manifests' : 'Not applicable')],
    ];
@endphp

@section('content')
<div class="lk-logistics-dashboard lk-rider-management">
    <nav class="flex flex-wrap items-center gap-2 text-[10px] text-muted">
        <a href="{{ route('logistics.dashboard') }}" class="transition hover:text-primary">Dashboard</a>
        <span>/</span>
        <a href="{{ route('logistics.riders') }}" class="transition hover:text-primary">Riders</a>
        <span>/</span>
        <span class="font-semibold text-ink">{{ $name }}</span>
    </nav>

    @if(session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>
    @endif

    <header class="lk-dashboard-heading lk-rider-management__hero">
        <div class="lk-dashboard-heading__copy">
            <span class="lk-dashboard-overline">Rider management</span>
            <h1>{{ $name }}</h1>
            <p>Rider ID: RID-{{ str_pad((string) data_get($rider, 'id', 0), 4, '0', STR_PAD_LEFT) }} · Account, delivery operations, and review history.</p>
        </div>
        <div class="lk-dashboard-heading__actions">
            <span class="lk-rider-management__identity">{{ $initials }}</span>
            <span class="lk-rider-management__status" data-status="{{ strtolower($rider->status) }}">{{ str($rider->status)->replace('_', ' ')->headline() }}</span>
        </div>
    </header>

    @if($isLocalRider)
    <section class="rounded-xl border border-line bg-surface p-5">
        <div>
            <h2 class="text-[13px] font-semibold text-ink">Approved Local-Trip Parcel Capacity</h2>
            <p class="mt-1 text-[10px] leading-5 text-muted">Set how many parcels this rider may carry on a local barangay trip. Motorcycle and tricycle trips are limited to 10 parcels.</p>
        </div>
        @if($localTripsSchemaReady)
            <form method="POST" action="{{ route('logistics.riders.capacity.update', $rider) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                @csrf
                @method('PATCH')
                <label class="text-[10px] font-semibold text-ink">Approved parcels (max 10)<input type="number" min="1" max="10" name="approved_parcel_capacity" value="{{ old('approved_parcel_capacity', $rider->approved_parcel_capacity) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal sm:w-48"></label>
                <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Save Local-Trip Capacity</button>
            </form>
        @else
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[10px] text-amber-800">Local-trip schema is not installed. Local-trip capacity approval is disabled until the required migrations are applied.</p>
        @endif
    </section>
    @endif

    @if($isInterLogisticsRider)
    <section class="rounded-xl border border-line bg-surface p-5">
        <div>
            <h2 class="text-[13px] font-semibold text-ink">Approved Transfer Capacity</h2>
            <p class="mt-1 text-[10px] leading-5 text-muted">Set approved parcel and total weight limits in kilograms. Four-wheel car capacity is capped at 10 parcels; Van 50, Closed Van 75, Truck 100. Missing values block sealing.</p>
        </div>
        @if($manifestSchemaReady)
            <form method="POST" action="{{ route('logistics.riders.capacity.update', $rider) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                @csrf
                @method('PATCH')
                <label class="text-[10px] font-semibold text-ink">Parcels (max {{ \App\Support\RiderVehicleType::interLogisticsParcelLimit($rider->vehicle_type) }})<input type="number" min="1" max="{{ \App\Support\RiderVehicleType::interLogisticsParcelLimit($rider->vehicle_type) }}" name="approved_parcel_capacity" value="{{ old('approved_parcel_capacity', $rider->approved_parcel_capacity) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal"></label>
                <label class="text-[10px] font-semibold text-ink">Weight kg<input type="number" step="0.001" min="0.001" name="approved_weight_capacity_kg" value="{{ old('approved_weight_capacity_kg', $rider->approved_weight_capacity_kg) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal"></label>
                <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white sm:col-span-2 sm:justify-self-start">Save Capacity</button>
            </form>
        @else
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[10px] text-amber-800">Transfer schema is not installed. Cross-hub manifest operations remain disabled.</p>
        @endif
    </section>
    @endif
    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Rider Information</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($details as $item)
                <div class="rounded-lg bg-page-secondary p-4">
                    <span class="text-[8px] uppercase text-muted">{{ $item[0] }}</span>
                    <strong class="mt-2 block break-words text-[10px] font-semibold text-ink">{{ $item[1] }}</strong>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <div>
            <h2 class="text-[13px] font-semibold text-ink">Vehicle and Document Re-verification</h2>
            <p class="mt-1 text-[10px] leading-5 text-muted">Review the rider’s submitted vehicle details and private supporting documents. Approving applies the new verified details; rejecting leaves the current profile in place.</p>
        </div>
        @if(! ($profileUpdateSchemaReady ?? false))
            <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[10px] text-amber-800">The profile update workflow is unavailable until its migration is installed.</p>
        @elseif(($profileUpdateRequests ?? collect())->isEmpty())
            <p class="mt-4 text-[10px] text-muted">No vehicle or document update requests have been submitted.</p>
        @else
            <div class="mt-4 space-y-4">
                @foreach($profileUpdateRequests as $updateRequest)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <strong class="text-[11px] text-ink">{{ str($updateRequest->status)->replace('_', ' ')->headline() }} · {{ $updateRequest->submitted_at?->format('M j, Y g:i A') }}</strong>
                                <p class="mt-1 text-[9px] text-muted">Submitted by {{ $updateRequest->submitter?->name ?? 'Rider' }}</p>
                            </div>
                            @if($updateRequest->reviewed_at)
                                <span class="text-[9px] text-muted">Reviewed {{ $updateRequest->reviewed_at->format('M j, Y g:i A') }}{{ $updateRequest->reviewer?->name ? ' by '.$updateRequest->reviewer->name : '' }}</span>
                            @endif
                        </div>
                        <dl class="mt-4 grid gap-3 text-[10px] sm:grid-cols-3">
                            <div><dt class="text-muted">Vehicle type</dt><dd class="mt-1 font-semibold text-ink">{{ str($rider->vehicle_type)->replace('_', ' ')->headline() }} → {{ str($updateRequest->vehicle_type)->replace('_', ' ')->headline() }}</dd></div>
                            <div><dt class="text-muted">Plate number</dt><dd class="mt-1 font-semibold text-ink">{{ $rider->plate_number }} → {{ $updateRequest->plate_number }}</dd></div>
                            <div><dt class="text-muted">Driver's license number</dt><dd class="mt-1 font-semibold text-ink">•••• {{ substr((string) $rider->drivers_license_number, -4) }} → •••• {{ substr((string) $updateRequest->drivers_license_number, -4) }}</dd></div>
                        </dl>
                        <div class="mt-4">
                            <h3 class="text-[10px] font-semibold text-ink">Submitted documents</h3>
                            @forelse($updateRequest->documents as $document)
                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-2 text-[9px]">
                                    <span>{{ str($document->document_type)->replace('_', ' ')->headline() }} · {{ $document->original_name ?: 'Uploaded document' }} · {{ str($document->verification_status)->headline() }}</span>
                                    <a class="font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('logistics.riders.profile-updates.documents.show', [$rider, $updateRequest, $document]) }}">View private document</a>
                                </div>
                            @empty
                                <p class="mt-1 text-[9px] text-muted">No replacement documents were attached.</p>
                            @endforelse
                        </div>
                        @if($updateRequest->review_notes)
                            <p class="mt-3 rounded-lg bg-page-secondary px-3 py-2 text-[9px] text-muted">Review note: {{ $updateRequest->review_notes }}</p>
                        @endif
                        @if($updateRequest->status === 'PENDING')
                            <div class="mt-4 grid gap-3 border-t border-line pt-4 lg:grid-cols-2">
                                <form method="POST" action="{{ route('logistics.riders.profile-updates.approve', [$rider, $updateRequest]) }}" class="grid gap-2">
                                    @csrf
                                    <label class="grid gap-1 text-[9px] font-semibold text-ink">Approval note (optional)<input name="review_notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2 font-normal"></label>
                                    <button type="submit" class="justify-self-start rounded-lg bg-primary px-4 py-2 text-[9px] font-semibold text-white">Approve and verify profile</button>
                                    @if((string) $rider->vehicle_type !== (string) $updateRequest->vehicle_type)
                                        <span class="text-[9px] text-muted">A vehicle type change clears approved capacity and marks the rider offline until new limits are set.</span>
                                    @endif
                                </form>
                                <form method="POST" action="{{ route('logistics.riders.profile-updates.reject', [$rider, $updateRequest]) }}" class="grid gap-2">
                                    @csrf
                                    <label class="grid gap-1 text-[9px] font-semibold text-ink">Rejection reason<textarea name="review_notes" rows="2" minlength="3" maxlength="1000" required class="rounded-lg border border-line px-3 py-2 font-normal"></textarea></label>
                                    <button type="submit" class="justify-self-start rounded-lg border border-red-200 px-4 py-2 text-[9px] font-semibold text-red-700">Reject request</button>
                                </form>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="lk-dashboard-card lk-rider-management__discipline" id="rider-discipline">
        <header class="lk-dashboard-card-head">
            <div class="lk-dashboard-card-head__copy">
                <span class="lk-dashboard-overline">Trust and safety</span>
                <h2>Rider account controls</h2>
                <p>Issue documented warnings, suspend access for a set period, or permanently ban this rider.</p>
            </div>
            <span class="lk-rider-management__warning-count">{{ $riderWarningCount }} / {{ \App\Models\User::MAX_ACCOUNT_WARNINGS }} warnings</span>
        </header>

        <div class="lk-rider-management__control-grid">
            <section class="lk-rider-management__warning-panel">
                <div class="lk-rider-management__panel-heading">
                    <span class="lk-rider-management__eyebrow">Corrective action</span>
                    <h3>Issue a warning</h3>
                    <p>Warnings keep an active rider able to work. The three-warning limit includes automatic COD warnings.</p>
                </div>

                @if(in_array($rider->status, ['ACTIVE', 'WARNED'], true) && $rider->user?->status === 'ACTIVE' && ! $isPermanentlyBanned)
                    @if($riderWarningCount < \App\Models\User::MAX_ACCOUNT_WARNINGS)
                        <form method="POST" action="{{ route('logistics.riders.warnings.store', $rider) }}" class="lk-rider-management__form" data-confirm-title="Issue rider warning?" data-confirm-message="Send this documented warning and corrective action to {{ $name }}? Warnings do not suspend the rider’s access." data-confirm-label="Issue warning">
                            @csrf
                            <label class="lk-rider-management__field">Warning category
                                <select name="category" required class="lk-rider-management__input">
                                    <option value="">Select a review category</option>
                                    @foreach($riderWarningCategories as $category => $label)
                                        <option value="{{ $category }}" @selected(old('category') === $category)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="lk-rider-management__field">Detailed reason
                                <textarea name="reason" rows="3" required minlength="10" maxlength="5000" class="lk-rider-management__input" placeholder="Describe the substantiated concern.">{{ old('reason') }}</textarea>
                            </label>
                            <label class="lk-rider-management__field">Corrective action requested
                                <textarea name="corrective_action" rows="3" required minlength="5" maxlength="5000" class="lk-rider-management__input" placeholder="Explain what the rider must correct or review.">{{ old('corrective_action') }}</textarea>
                            </label>
                            <label class="lk-rider-management__field">Evidence or investigation notes <span>Optional</span>
                                <textarea name="evidence" rows="2" maxlength="5000" class="lk-rider-management__input" placeholder="Add relevant supporting context.">{{ old('evidence') }}</textarea>
                            </label>
                            <p class="lk-rider-management__reference-note">A case reference is generated automatically.</p>
                            <button type="submit" class="lk-dashboard-button lk-rider-management__warning-button">Issue warning ({{ \App\Models\User::MAX_ACCOUNT_WARNINGS - $riderWarningCount }} remaining)</button>
                        </form>
                    @else
                        <p class="lk-rider-management__notice lk-rider-management__notice--warning" role="status">This rider has reached the three-warning limit. No further warnings can be issued.</p>
                    @endif
                @else
                    <p class="lk-rider-management__notice">Warnings can be issued while the rider account is active and able to work.</p>
                @endif

                <div class="lk-rider-management__history">
                    <h4>Warning history</h4>
                    @forelse($riderWarningLogs as $warning)
                        @php($warningValues = $warning->new_values ?? [])
                        <article class="lk-rider-management__history-item">
                            <div class="lk-rider-management__history-top">
                                <strong>{{ data_get($warningValues, 'category_label', data_get($warningValues, 'automatic') ? 'Automatic COD remittance warning' : 'Rider warning') }}</strong>
                                <span class="lk-rider-management__tag">{{ str(data_get($warningValues, 'warning_status', 'OPEN'))->replace('_', ' ')->headline() }}</span>
                            </div>
                            <p>{{ data_get($warningValues, 'reason', 'No reason recorded.') }}</p>
                            @if(data_get($warningValues, 'corrective_action'))<p><strong>Corrective action:</strong> {{ data_get($warningValues, 'corrective_action') }}</p>@endif
                            <small>{{ $warning->created_at?->format('M j, Y g:i A') }} · {{ $warning->actor?->name ?? data_get($warningValues, 'actor_label', 'Former staff member') }}
                                @if(data_get($warningValues, 'reference'))
                                    · Ref {{ data_get($warningValues, 'reference') }}
                                @endif
                            </small>
                        </article>
                    @empty
                        <p class="lk-rider-management__empty">No warnings have been recorded for this rider.</p>
                    @endforelse
                </div>
            </section>

            <div class="lk-rider-management__access-panels">
                <section class="lk-rider-management__access-panel">
                    <div class="lk-rider-management__panel-heading">
                        <span class="lk-rider-management__eyebrow">Temporary restriction</span>
                        <h3>{{ $isRiderSuspended ? 'Suspension in effect' : 'Suspend rider access' }}</h3>
                    </div>
                    @if($isPermanentlyBanned)
                        <p class="lk-rider-management__notice lk-rider-management__notice--danger">This rider is permanently banned. Suspension controls are closed.</p>
                    @elseif($isRiderSuspended)
                        <p class="lk-rider-management__current-state">This rider is suspended{{ $rider->user?->suspended_until ? ' until '.$rider->user->suspended_until->format('M d, Y h:i A') : ' with no expiry recorded' }}. Access returns automatically when a temporary suspension ends.</p>
                        <form method="POST" action="{{ route('logistics.riders.reactivate', $rider) }}" class="lk-rider-management__form" data-confirm-title="Reactivate rider?" data-confirm-message="Restore {{ $name }}’s account access? The rider will remain offline until they choose to go online." data-confirm-label="Reactivate rider">
                            @csrf
                            <label class="lk-rider-management__field">Reason for reactivation
                                <textarea name="reason" rows="2" required minlength="10" maxlength="2000" class="lk-rider-management__input" placeholder="Document why the suspension is ending early.">{{ old('reason') }}</textarea>
                            </label>
                            <button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Reactivate rider</button>
                        </form>
                    @elseif($accountEnforcementReady)
                        <p class="lk-rider-management__copy">Workspace access returns automatically when the selected period ends.</p>
                        <form method="POST" action="{{ route('logistics.riders.suspend', $rider) }}" class="lk-rider-management__form" data-confirm-title="Suspend rider?" data-confirm-message="{{ $name }} will lose account access and cannot receive new assignments for the selected period." data-confirm-label="Suspend rider" data-confirm-tone="danger">
                            @csrf
                            <label class="lk-rider-management__field">Suspension period
                                <select name="duration" required class="lk-rider-management__input">
                                    @foreach($riderSuspensionDurations as $duration => $label)
                                        <option value="{{ $duration }}" @selected(old('duration', 'week') === $duration)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="lk-rider-management__field">Reason for suspension
                                <textarea name="reason" rows="3" required minlength="10" maxlength="2000" class="lk-rider-management__input" placeholder="Explain why account access is being restricted.">{{ old('reason') }}</textarea>
                            </label>
                            <label class="lk-rider-management__field">Evidence or investigation notes <span>Optional</span>
                                <textarea name="evidence" rows="2" maxlength="5000" class="lk-rider-management__input" placeholder="Add supporting context.">{{ old('evidence') }}</textarea>
                            </label>
                            <p class="lk-rider-management__reference-note">A case reference is generated automatically.</p>
                            <button type="submit" class="lk-dashboard-button lk-rider-management__danger-button">Suspend rider</button>
                        </form>
                    @else
                        <p class="lk-rider-management__notice lk-rider-management__notice--warning">Temporary suspension controls are unavailable until the account enforcement migration is applied.</p>
                    @endif
                </section>

                <section class="lk-rider-management__access-panel lk-rider-management__ban-panel">
                    <div class="lk-rider-management__panel-heading">
                        <span class="lk-rider-management__eyebrow">Permanent restriction</span>
                        <h3>{{ $isPermanentlyBanned ? 'Rider permanently banned' : 'Permanently ban rider' }}</h3>
                    </div>
                    @if($isPermanentlyBanned)
                        <p class="lk-rider-management__notice lk-rider-management__notice--danger">Sign-in and workspace access are blocked permanently. This action cannot be reversed from this page.</p>
                    @elseif($accountEnforcementReady)
                        <p class="lk-rider-management__copy">A permanent ban does not expire and cannot be undone from this page.</p>
                        <form method="POST" action="{{ route('logistics.riders.ban', $rider) }}" class="lk-rider-management__form" data-confirm-title="Permanently ban this rider?" data-confirm-message="{{ $name }} will lose LIKHAE access permanently. This cannot be undone from this page." data-confirm-label="Permanently ban" data-confirm-tone="danger">
                            @csrf
                            <label class="lk-rider-management__field">Reason for permanent ban
                                <textarea name="reason" rows="3" required minlength="10" maxlength="2000" class="lk-rider-management__input" placeholder="Document the reason for permanently removing this rider from the platform.">{{ old('reason') }}</textarea>
                            </label>
                            <label class="lk-rider-management__field">Evidence or investigation notes <span>Optional</span>
                                <textarea name="evidence" rows="2" maxlength="5000" class="lk-rider-management__input" placeholder="Add supporting evidence.">{{ old('evidence') }}</textarea>
                            </label>
                            <p class="lk-rider-management__reference-note">A case reference is generated automatically.</p>
                            <button type="submit" class="lk-dashboard-button lk-rider-management__danger-button">Permanently ban rider</button>
                        </form>
                    @else
                        <p class="lk-rider-management__notice lk-rider-management__notice--warning">Permanent ban controls are unavailable until the account enforcement migration is applied.</p>
                    @endif
                </section>
            </div>
        </div>

        <div class="lk-rider-management__history lk-rider-management__access-history">
            <h3>Access and discipline history</h3>
            @forelse($rider->moderationLogs->reject(fn ($log) => $log->event === 'rider.warned') as $log)
                @php($logValues = $log->new_values ?? [])
                <article class="lk-rider-management__history-item">
                    <div class="lk-rider-management__history-top">
                        <strong>{{ str(str_replace('rider.', '', $log->event))->headline() }} · {{ $log->created_at?->format('M j, Y g:i A') }}</strong>
                        @if(data_get($logValues, 'duration_label'))<span class="lk-rider-management__tag">{{ data_get($logValues, 'duration_label') }}</span>@endif
                    </div>
                    <p>{{ data_get($logValues, 'reason', 'No reason recorded.') }}</p>
                    <small>By {{ $log->actor?->name ?? data_get($logValues, 'actor_label', 'Former staff member') }}
                        @if(data_get($logValues, 'reference'))
                            · Ref {{ data_get($logValues, 'reference') }}
                        @endif
                        @if(data_get($logValues, 'suspended_until'))
                            · Until {{ \Illuminate\Support\Carbon::parse(data_get($logValues, 'suspended_until'))->format('M d, Y h:i A') }}
                        @endif
                    </small>
                </article>
            @empty
                <p class="lk-rider-management__empty">No suspensions, bans, or reactivations have been recorded.</p>
            @endforelse
        </div>
    </section>

    @if($isLocalRider)
    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-[13px] font-semibold text-ink">Delivery Area Assignment</h2>
                <p class="mt-1 text-[10px] leading-5 text-muted">Choose the active destination coverage for this rider. Reassigning ends the previous active area assignment.</p>
            </div>
            <span class="rounded-full bg-primary-soft px-3 py-1 text-[8px] font-semibold text-primary">{{ $activeAreas->join(', ') ?: 'Unassigned' }}</span>
        </div>

        <form method="POST" action="{{ route('logistics.riders.area.update', $rider) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            @method('PATCH')
            <label class="flex-1 text-[10px] font-semibold text-ink">
                Delivery area
                <select name="service_area_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-3 text-[10px] font-normal" aria-label="Delivery area">
                    <option value="">Unassigned</option>
                    @foreach($serviceAreas as $area)
                        @php($areaLocation = $area->locations->map(fn ($location) => collect([$location->barangay_name, $location->municipality_name, $location->province_name])->filter()->implode(', '))->join('; '))
                        <option value="{{ $area->id }}" @selected((int) $activeAreaId === (int) $area->id)>{{ $area->name }}{{ $areaLocation ? ' — '.$areaLocation : '' }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Save Assignment</button>
        </form>
    </section>
    @elseif($isInterLogisticsRider)
    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Hub-to-Hub Driver</h2>
        <p class="mt-1 text-[10px] leading-5 text-muted">Four-wheel drivers are assigned to inter-hub transfer manifests. They are not assigned a local delivery area or local trip.</p>
    </section>
    @else
    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Vehicle Assignment</h2>
        <p class="mt-1 text-[10px] leading-5 text-muted">This vehicle type is not eligible for local trips or hub-to-hub transfers.</p>
    </section>
    @endif

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Buyer Ratings</h2>
        <div class="mt-3 flex items-baseline gap-2">
            <strong class="text-2xl font-semibold text-ink">{{ number_format($ratingAverage, 1) }}</strong>
            <span class="text-xs text-muted">/ 5</span>
            <span class="text-sm text-yellow-500" aria-label="{{ number_format($ratingAverage, 1) }} out of 5 stars">{{ str_repeat('★', (int) round($ratingAverage)) }}<span class="text-stone-300">{{ str_repeat('★', 5 - (int) round($ratingAverage)) }}</span></span>
            <span class="text-xs text-muted">({{ $ratingCount }} {{ \Illuminate\Support\Str::plural('rating', $ratingCount) }})</span>
        </div>
    </section>

</div>
@endsection
