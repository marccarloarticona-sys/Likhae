@props(['active' => null, 'workspaceName' => 'Logistics Center', 'sidebarCounts' => [], 'notificationCount' => null])
@php
    $notificationCount = (int) ($notificationCount ?? data_get($sidebarCounts, 'notifications', 0));
    $routeActive = [
        'dashboard' => ['logistics.dashboard'],
        'rider-applications' => ['logistics.riders.applications', 'logistics.riders.application.*'],
        'riders' => ['logistics.riders', 'logistics.riders.show', 'logistics.riders.activate', 'logistics.riders.deactivate'],
        'delivery-areas' => ['logistics.delivery-areas', 'logistics.delivery-areas.*'],
        'pickup-requests' => ['logistics.pickups', 'logistics.pickups.*'],
        'incoming' => ['logistics.parcels.receive'],
        'parcels' => ['logistics.parcels', 'logistics.parcels.show'],
        'sorting' => ['logistics.sorting', 'logistics.sorting.*'],
        'transfers' => ['logistics.transfers', 'logistics.transfers.*'],
        'local-trips' => ['logistics.local-trips', 'logistics.local-trips.*'],
        'assignments' => ['logistics.assignments', 'logistics.assignments.*'],
        'returns' => ['logistics.returns', 'logistics.returns.*'],
        'reports' => ['logistics.reports', 'logistics.reports.*'],
        'remittances' => ['logistics.remittances'],
        'wallet' => ['logistics.wallet'],
        'shipping-rate' => ['logistics.shipping-rate', 'logistics.shipping-rate-requests.*'],
        'messages' => ['logistics.messages', 'logistics.messages.*'],
        'notifications' => ['notifications.index'],
        'account' => ['logistics.profile', 'logistics.account'],
        'settings' => ['logistics.settings'],
    ];
    foreach ($routeActive as $key => $patterns) {
        if (collect($patterns)->contains(fn (string $pattern): bool => request()->routeIs($pattern))) {
            $active = $key;
            break;
        }
    }

    $icon = fn (string $key) => match ($key) {
        'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'parcels'   => '<svg viewBox="0 0 24 24"><path d="M6 3h12l2 4v14H4V7zM4 7h16M9 11h6"/></svg>',
        'shipping'  => '<svg viewBox="0 0 24 24"><path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>',
        'rider'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'apply'     => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>',
        'reports'   => '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        'wallet'    => '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4M8 3v2m8-2v2"/></svg>',
        'messages'  => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/></svg>',
        'notifications' => '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        'account'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'logout'    => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>',
    };
@endphp

<aside id="logisticsSidebar" class="sl-sidebar" data-sl-sidebar data-sidebar-workspace="logistics" aria-label="{{ __('navigation.items.logistics_center') }}">

    <div class="sl-sidebar-head">
        <button class="sl-icon-btn sl-sidebar-toggle" type="button" data-sl-sidebar-toggle data-collapse-label="{{ __('navigation.items.collapse_sidebar') }}" data-open-label="{{ __('navigation.items.open_sidebar') }}" aria-expanded="true" aria-label="{{ __('navigation.items.collapse_sidebar') }}">
            <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="{{ route('logistics.dashboard') }}" class="sl-brand" aria-label="LIKHAE {{ __('settings.workspaces.logistics') }}">
            <x-shared.logistics-logo :label="'LIKHAE '.__('settings.workspaces.logistics')" />
        </a>
    </div>

    <nav class="sl-sidebar-scroll">

        <a href="{{ route('logistics.dashboard') }}"
           class="sl-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}"
           data-title="{{ __('navigation.items.dashboard') }}">
            <span class="sl-nav-icon">{!! $icon('dashboard') !!}</span>
            <span class="sl-nav-text">{{ __('navigation.items.dashboard') }}</span>
        </a>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.rider_management') }}</span>

            <a href="{{ route('logistics.riders.applications') }}"
               class="sl-nav-link {{ $active === 'rider-applications' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.rider_applications') }}">
                <span class="sl-nav-icon">{!! $icon('apply') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.rider_applications') }}</span>
                @if(($sidebarCounts['rider_applications'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['rider_applications'] }}</span>@endif
            </a>

            <a href="{{ route('logistics.riders') }}"
               class="sl-nav-link {{ $active === 'riders' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.all_riders') }}">
                <span class="sl-nav-icon">{!! $icon('rider') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.all_riders') }}</span>
            </a>
            <a href="{{ route('logistics.delivery-areas') }}"
               class="sl-nav-link {{ $active === 'delivery-areas' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.delivery_areas') }}">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.delivery_areas') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.monitoring') }}</span>
            <a href="{{ route('logistics.parcels') }}"
               class="sl-nav-link {{ $active === 'parcels' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.all_parcels') }}">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.all_parcels') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.pickups_intake') }}</span>
            <a href="{{ route('logistics.pickups') }}"
               class="sl-nav-link {{ $active === 'pickup-requests' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.pickup_requests') }}">
                <span class="sl-nav-icon">{!! $icon('shipping') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.pickup_requests') }}</span>
                @if(($sidebarCounts['pickup_requests'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['pickup_requests'] }}</span>@endif
            </a>

            <a href="{{ route('logistics.parcels.receive') }}"
               class="sl-nav-link {{ $active === 'incoming' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.incoming_parcels') }}">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.incoming_parcels') }}</span>
                @if(($sidebarCounts['incoming'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['incoming'] }}</span>@endif
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.sorting') }}</span>
            <a href="{{ route('logistics.sorting') }}"
               class="sl-nav-link {{ $active === 'sorting' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.parcel_sorting') }}">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.parcel_sorting') }}</span>
                @if(($sidebarCounts['sorting'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['sorting'] }}</span>@endif
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.hub_transfers') }}</span>
            <a href="{{ route('logistics.transfers') }}"
               class="sl-nav-link {{ $active === 'transfers' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.hub_transfers') }}">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.hub_transfers') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.delivery') }}</span>
            <a href="{{ route('logistics.assignments') }}"
               class="sl-nav-link {{ $active === 'assignments' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.rider_assignment') }}">
                <span class="sl-nav-icon">{!! $icon('rider') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.rider_assignment') }}</span>
                @if(($sidebarCounts['assignments'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['assignments'] }}</span>@endif
            </a>
            <a href="{{ route('logistics.local-trips') }}"
               class="sl-nav-link {{ $active === 'local-trips' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.delivery_trip_overview') }}">
                <span class="sl-nav-icon">{!! $icon('shipping') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.delivery_trip_overview') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.returns') }}</span>
            <a href="{{ route('logistics.returns') }}"
               class="sl-nav-link {{ $active === 'returns' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.return_to_seller') }}">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.return_to_seller') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.reports') }}</span>

            <a href="{{ route('logistics.reports') }}"
               class="sl-nav-link {{ $active === 'reports' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.analytics_reports') }}">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.analytics_reports') }}</span>
            </a>
            <a href="{{ route('logistics.wallet') }}"
               class="sl-nav-link {{ $active === 'wallet' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.wallet_payouts') }}">
                <span class="sl-nav-icon">{!! $icon('wallet') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.wallet_payouts') }}</span>
                @if(($sidebarCounts['payout_requests'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['payout_requests'] }}</span>@endif
            </a>
            <a href="{{ route('logistics.remittances') }}"
               class="sl-nav-link {{ $active === 'remittances' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.finance') }}">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.finance') }}</span>
            </a>
            <a href="{{ route('logistics.shipping-rate') }}"
               class="sl-nav-link {{ $active === 'shipping-rate' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.shipping_rate') }}">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.shipping_rate') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.account') }}</span>

            <a href="{{ route('logistics.messages') }}"
               class="sl-nav-link {{ $active === 'messages' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.messages') }}">
                <span class="sl-nav-icon">{!! $icon('messages') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.messages') }}</span>
                @if(($sidebarCounts['messages'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['messages'] }}</span>@endif
            </a>

            <a href="{{ route('notifications.index') }}"
               class="sl-nav-link {{ $active === 'notifications' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.notifications') }}">
                <span class="sl-nav-icon">{!! $icon('notifications') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.notifications') }}</span>
                @if($notificationCount > 0)<span class="sl-nav-badge">{{ $notificationCount }}</span>@endif
            </a>

            <a href="{{ route('logistics.profile') }}"
               class="sl-nav-link {{ $active === 'account' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.sections.account') }}">
                <span class="sl-nav-icon">{!! $icon('account') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.profile') }}</span>
            </a>

            <a href="{{ route('logistics.settings') }}"
               class="sl-nav-link {{ $active === 'settings' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.system_settings') }}">
                <span class="sl-nav-icon">{!! $icon('account') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.system_settings') }}</span>
            </a>
        </div>

    </nav>

    <div class="sl-sidebar-foot">
        {{-- DARK MODE TOGGLE --}}
        <button
            id="themeToggle"
            type="button"
            class="sl-nav-link"
            data-theme-preference-url="{{ route('logistics.settings.theme.update') }}"
            data-title="{{ __('navigation.items.appearance') }}"
            style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;background:none;border:none;"
        >
            <span style="display:flex;align-items:center;gap:8px;">
                <span class="sl-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.6;"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></svg>
                </span>
                <span class="sl-theme-copy" style="display:grid;text-align:left;">
                    <strong style="color:inherit;font-size:10.5px;font-weight:500;line-height:1.1;">{{ __('navigation.items.appearance') }}</strong>
                    <span style="margin-top:2px;color:currentColor;opacity:0.5;font-size:8px;line-height:1.1;">{{ __('navigation.items.light_dark_mode') }}</span>
                </span>
            </span>
            <span id="themeToggleBadge" class="sl-theme-state" data-label-on="{{ __('common.labels.on') }}" data-label-off="{{ __('common.labels.off') }}" style="display:inline-flex;min-height:21px;align-items:center;padding:0 9px;border-radius:9999px;background:#E3E3E2;color:#494644;font-size:7px;font-weight:900;white-space:nowrap;">{{ __('common.labels.off') }}</span>
        </button>
        <form method="POST" action="{{ route('logout') }}" data-confirm-title="{{ __('common.confirmation.logout_title') }}" data-confirm-message="{{ __('common.confirmation.logout_message') }}" data-confirm-label="{{ __('common.confirmation.logout_label') }}">
            @csrf
            <button type="submit" class="sl-nav-link sl-logout" data-title="{{ __('navigation.items.logout') }}">
                <span class="sl-nav-icon">{!! $icon('logout') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.logout') }}</span>
            </button>
        </form>
    </div>

</aside>
