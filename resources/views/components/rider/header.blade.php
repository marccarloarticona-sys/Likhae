@props(['title' => 'Dashboard', 'subtitle' => 'Rider operations workspace.', 'notificationCount' => 0, 'messageCount' => 0])
@php
    $notificationCount = (int) $notificationCount;
    $messageCount = (int) $messageCount;
    $workspaceRider = auth()->user()?->riderProfile;
    $isLocalRider = $workspaceRider && \App\Support\RiderVehicleType::isLocalBarangay($workspaceRider->vehicle_type);
    $availabilitySchemaReady = app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->riderAvailabilityReady();
    $canToggleAvailability = $isLocalRider
        && $availabilitySchemaReady
        && in_array($workspaceRider->status, ['ACTIVE', 'WARNED'], true)
        && auth()->user()?->status === \App\Models\User::STATUS_ACTIVE;
    $availabilityOnline = $canToggleAvailability && (bool) $workspaceRider->is_online;
    $availabilityLocationFresh = $availabilityOnline
        && $workspaceRider->availability_latitude !== null
        && $workspaceRider->availability_longitude !== null
        && ($workspaceRider->availability_location_updated_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false);
    $availabilityUnavailableMessage = ! $availabilitySchemaReady
        ? 'Availability controls are unavailable until Rider status setup is complete.'
        : 'Contact Logistics to restore Rider access.';
@endphp

<div class="rider-mobile-bar">
    <div class="rider-mobile-brand">
        <x-shared.logistics-logo label="LIKHAE Logistics for Riders" class="sl-logistics-logo--compact" />
    </div>
    <button id="riderMobileSidebarToggle" type="button" class="rider-mobile-menu-btn" data-sl-mobile-menu data-open-label="Open rider navigation" data-close-label="Close rider navigation" aria-controls="riderSidebar" aria-expanded="false" aria-label="Open rider navigation">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
    </button>
    <a href="{{ route('rider.messages') }}" class="rider-mobile-menu-btn rider-header-action" aria-label="Messages" title="Messages">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"></path><path d="M8 9h8M8 13h5"></path></svg>
        @if($messageCount > 0)<span class="rider-notification-badge">{{ $messageCount }}</span>@endif
    </a>
    <button type="button" class="rider-mobile-menu-btn rider-notification-trigger rider-header-notification" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
        @if($notificationCount > 0)<span class="rider-notification-badge">{{ $notificationCount }}</span>@endif
    </button>
    <x-shared.workspace.account-menu :label="__('settings.workspaces.rider')" profile-route="rider.profile" compact />
    @if($isLocalRider)
        <div class="rider-mobile-status-row">
            <div class="rider-mobile-status-copy" role="status" aria-live="polite">
                <small>RIDER STATUS</small>
                <strong>{{ $canToggleAvailability ? ($availabilityOnline ? 'ONLINE' : 'OFFLINE') : 'UNAVAILABLE' }}</strong>
                <span data-rider-availability-summary>{{ $canToggleAvailability ? ($availabilityOnline ? ($availabilityLocationFresh ? 'Available for new jobs' : 'Share your location for nearby assignments') : 'Not accepting new jobs') : $availabilityUnavailableMessage }}</span>
                @if($availabilityOnline)<span data-rider-location-status aria-live="polite"></span>@endif
            </div>
            @if($canToggleAvailability)
                <form method="POST" action="{{ route('rider.availability.update') }}" data-rider-availability-form>
                    @csrf @method('PATCH')
                    <input type="hidden" name="is_online" value="{{ $availabilityOnline ? 0 : 1 }}">
                    <button type="submit" class="rider-topbar-status rider-mobile-status-toggle">{{ $availabilityOnline ? 'Go Offline' : 'Go Online' }}</button>
                </form>
            @endif
        </div>
    @endif
</div>

<header class="rider-topbar">
    <div class="rider-topbar-heading">
        <strong>{{ $title }}</strong>
        <span>{{ $subtitle }}</span>
    </div>
    <div class="rider-search-wrap sl-search-wrap" data-workspace-search data-search-workspace="rider" data-search-label="Rider">
        <form class="sl-search" action="{{ route('rider.dashboard') }}" method="GET" role="search" data-workspace-search-form>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input name="q" type="search" placeholder="Search all Rider pages..." aria-label="Search all Rider pages" aria-autocomplete="list" aria-expanded="false" aria-controls="riderSearchSuggestions" autocomplete="off" data-workspace-search-input>
            <kbd>&#8984; K</kbd>
        </form>
        <div class="sl-search-dropdown" id="riderSearchSuggestions" role="listbox" aria-label="Rider search suggestions" hidden data-workspace-search-results>
            <p class="sl-search-dropdown__heading" data-workspace-search-heading></p>
            <div class="sl-search-dropdown__options" data-workspace-search-options></div>
            <p class="sl-search-dropdown__empty" hidden data-workspace-search-empty></p>
            <button type="button" class="sl-search-dropdown__clear" hidden data-workspace-search-clear>Clear search history</button>
        </div>
    </div>
    <div class="rider-topbar-actions">
        <a href="{{ route('rider.messages') }}" aria-label="Messages" title="Messages" class="rider-header-action">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"></path><path d="M8 9h8M8 13h5"></path></svg>
            @if($messageCount > 0)<span class="rider-notification-badge">{{ $messageCount }}</span>@endif
        </a>
        <button type="button" class="rider-mobile-menu-btn rider-notification-trigger rider-header-notification" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
            @if($notificationCount > 0)<span class="rider-notification-badge">{{ $notificationCount }}</span>@endif
        </button>
        @if($isLocalRider && $canToggleAvailability)
            <form method="POST" action="{{ route('rider.availability.update') }}" class="rider-topbar-availability" data-rider-availability-form>
                @csrf @method('PATCH')
                <input type="hidden" name="is_online" value="{{ $availabilityOnline ? 0 : 1 }}">
                <span class="rider-topbar-availability-copy" role="status"><strong>RIDER STATUS · {{ $availabilityOnline ? 'ONLINE' : 'OFFLINE' }}</strong><span data-rider-availability-summary>{{ $availabilityOnline ? ($availabilityLocationFresh ? 'Available for new jobs' : 'Share your location for nearby assignments') : 'Not accepting new jobs' }}</span>@if($availabilityOnline)<span data-rider-location-status aria-live="polite"></span>@endif</span>
                <button type="submit" class="rider-topbar-status" style="cursor:pointer;border-color:{{ $availabilityOnline ? '#CFE8DA' : '#E4DCCB' }};background:{{ $availabilityOnline ? 'var(--ra-success-soft)' : '#F5F1E8' }};color:{{ $availabilityOnline ? 'var(--ra-success)' : '#76684F' }}">{{ $availabilityOnline ? 'Go Offline' : 'Go Online' }}</button>
            </form>
        @elseif($isLocalRider)
            <span class="rider-topbar-status" role="status">{{ $availabilitySchemaReady ? 'Unavailable · Contact Logistics' : 'Availability setup unavailable' }}</span>
        @endif
        <x-shared.workspace.account-menu :label="__('settings.workspaces.rider')" profile-route="rider.profile" />
    </div>
</header>
@if($errors->has('is_online') || $errors->has('latitude') || $errors->has('longitude'))
    <div role="alert" class="rider-availability-error">{{ $errors->first('is_online') ?: ($errors->first('latitude') ?: $errors->first('longitude')) }}</div>
@endif
