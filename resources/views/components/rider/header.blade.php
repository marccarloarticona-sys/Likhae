@props(['notificationCount' => 0])
@php
    $notificationCount = (int) $notificationCount;
    $workspaceRider = auth()->user()?->riderProfile;
@endphp

<div class="rider-mobile-bar">
    <div class="rider-mobile-brand">
        <span class="rider-brand-mark">L</span>
        <strong>LIKHAE Rider</strong>
    </div>
    <button id="riderMobileSidebarToggle" type="button" class="rider-mobile-menu-btn" data-sl-mobile-menu data-open-label="Open rider navigation" data-close-label="Close rider navigation" aria-controls="riderSidebar" aria-expanded="false" aria-label="Open rider navigation">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
    </button>
    <button type="button" class="rider-mobile-menu-btn rider-notification-trigger" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
        @if($notificationCount > 0)<span class="rider-notification-badge">{{ $notificationCount }}</span>@endif
    </button>
    <x-shared.workspace.account-menu label="Rider Account" profile-route="rider.profile" compact />
</div>

<header class="rider-topbar">
    <div class="rider-topbar-copy">
        <strong>LIKHAE Rider</strong>
        <span>Operations workspace</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
        <button type="button" class="rider-mobile-menu-btn rider-notification-trigger" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
            @if($notificationCount > 0)<span class="rider-notification-badge">{{ $notificationCount }}</span>@endif
        </button>
        @if($workspaceRider && \App\Support\RiderVehicleType::isLocalBarangay($workspaceRider->vehicle_type))
            <form method="POST" action="{{ route('rider.availability.update') }}" style="display:inline-flex;align-items:center;gap:6px" data-rider-availability-form>
                @csrf @method('PATCH')
                <input type="hidden" name="is_online" value="{{ $workspaceRider->is_online ? 0 : 1 }}">
                <input type="hidden" name="latitude" data-availability-latitude>
                <input type="hidden" name="longitude" data-availability-longitude>
                <button type="submit" class="rider-topbar-status" style="cursor:pointer;border-color:{{ $workspaceRider->is_online ? '#CFE8DA' : '#E4DCCB' }};background:{{ $workspaceRider->is_online ? 'var(--ra-success-soft)' : '#F5F1E8' }};color:{{ $workspaceRider->is_online ? 'var(--ra-success)' : '#76684F' }}">{{ $workspaceRider->is_online ? 'Go Offline' : 'Go Online' }}</button>
                <span class="sr-only" data-availability-message aria-live="polite"></span>
            </form>
        @endif
        <x-shared.workspace.account-menu label="Rider Account" profile-route="rider.profile" />
    </div>
</header>
