@props(['active' => null, 'workspaceName' => 'Rider Panel', 'sidebarCounts' => [], 'notificationCount' => null])
@php
    $riderProfile = auth()->user()?->riderProfile;
    $vehicleType = $riderProfile?->vehicle_type;
    $canUseLocalRoutes = \App\Support\RiderVehicleType::isLocalBarangay($vehicleType);
    $canUseHubTransfers = \App\Support\RiderVehicleType::isInterLogistics($vehicleType);
    $notificationCount = (int) ($notificationCount ?? data_get($sidebarCounts, 'notifications', 0));
    $routeActive = [
        'dashboard' => ['rider.dashboard'],
        'pickups' => ['rider.pickups', 'rider.pickups.*', 'rider.parcels.*'],
        'deliveries' => ['rider.deliveries', 'rider.deliveries.*', 'rider.trips', 'rider.trips.*'],
        'returns' => ['rider.returns', 'rider.returns.*'],
        'transfers' => ['rider.transfers', 'rider.transfers.*'],
        'history' => ['rider.history', 'rider.history.*'],
        'wallet' => ['rider.wallet', 'rider.earnings', 'rider.earnings.*'],
        'remittances' => ['rider.remittances'],
        'messages' => ['rider.messages', 'rider.messages.*'],
        'notifications' => ['notifications.index'],
        'account' => ['rider.account', 'rider.account.*', 'rider.profile'],
        'settings' => ['rider.settings'],
    ];
    foreach ($routeActive as $key => $patterns) {
        if (collect($patterns)->contains(fn (string $pattern): bool => request()->routeIs($pattern))) {
            $active = $key;
            break;
        }
    }

    $icon = fn (string $key) => match ($key) {
        'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'pickup'    => '<svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
        'delivery'  => '<svg viewBox="0 0 24 24"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="21" r="1"/><circle cx="20" cy="21" r="1"/></svg>',
        'history'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
        'earnings'  => '<svg viewBox="0 0 24 24"><path d="M6 3h8a4 4 0 0 1 0 8H6z"/><path d="M6 11h8a4 4 0 0 1 0 8H6z"/><path d="M4 7h4"/><path d="M4 15h4"/><path d="M6 3v18"/></svg>',
        'messages'  => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/></svg>',
        'notifications' => '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        'account'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'logout'    => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>',
    };
@endphp

<aside id="riderSidebar" class="sl-sidebar" data-sl-sidebar data-sidebar-workspace="rider" aria-label="{{ __('navigation.items.rider_panel') }}">

    <div class="sl-sidebar-head">
        <button class="sl-icon-btn sl-sidebar-toggle" type="button" data-sl-sidebar-toggle data-collapse-label="{{ __('navigation.items.collapse_sidebar') }}" data-open-label="{{ __('navigation.items.open_sidebar') }}" aria-expanded="true" aria-label="{{ __('navigation.items.collapse_sidebar') }}">
            <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="{{ route('rider.dashboard') }}" class="sl-brand" aria-label="LIKHAE {{ __('settings.workspaces.rider') }}">
            <x-shared.logistics-logo :label="'LIKHAE '.__('settings.workspaces.rider')" />
        </a>
    </div>

    <nav class="sl-sidebar-scroll">

        <a href="{{ route('rider.dashboard') }}"
           class="sl-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}"
           data-title="{{ __('navigation.items.dashboard') }}">
            <span class="sl-nav-icon">{!! $icon('dashboard') !!}</span>
            <span class="sl-nav-text">{{ __('navigation.items.dashboard') }}</span>
        </a>

        @if($canUseLocalRoutes)
        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.items.pickups') }}</span>

            <a href="{{ route('rider.pickups') }}"
               class="sl-nav-link {{ $active === 'pickups' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.pickup_assignments') }}">
                <span class="sl-nav-icon">{!! $icon('pickup') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.pickup_assignments') }}</span>
                @if(($sidebarCounts['pickups'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['pickups'] }}</span>@endif
            </a>
        </div>
        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.items.deliveries') }}</span>

            <a href="{{ route('rider.trips') }}"
               class="sl-nav-link {{ $active === 'deliveries' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.delivery_batches') }}">
                <span class="sl-nav-icon">{!! $icon('delivery') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.delivery_batches') }}</span>
                @if(($sidebarCounts['deliveries'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['deliveries'] }}</span>@endif
            </a>
            <a href="{{ route('rider.returns') }}"
               class="sl-nav-link {{ $active === 'returns' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.return_parcels') }}">
                <span class="sl-nav-icon">{!! $icon('shipping') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.return_parcels') }}</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.items.history') }}</span>

            <a href="{{ route('rider.history') }}"
               class="sl-nav-link {{ $active === 'history' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.delivery_history') }}">
                <span class="sl-nav-icon">{!! $icon('history') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.delivery_history') }}</span>
            </a>
        </div>
        @endif

        @if($canUseHubTransfers)
        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.items.transfers') }}</span>
            <a href="{{ route('rider.transfers', ['view' => 'history']) }}"
               class="sl-nav-link {{ $active === 'transfers' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.inter_hub_transfers') }}">
                <span class="sl-nav-icon">{!! $icon('pickup') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.inter_hub_transfers') }}</span>
            </a>
        </div>
        @endif

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.items.wallet') }}</span>

            <a href="{{ route('rider.wallet') }}"
               class="sl-nav-link {{ $active === 'wallet' ? 'is-active' : '' }}"
               data-title="{{ $canUseHubTransfers ? __('navigation.items.wallet_payroll') : __('navigation.items.wallet_payouts') }}">
                <span class="sl-nav-icon">{!! $icon('earnings') !!}</span>
                <span class="sl-nav-text">{{ $canUseHubTransfers ? __('navigation.items.wallet_payroll') : __('navigation.items.wallet_payouts') }}</span>
                @if(!$canUseHubTransfers && ($sidebarCounts['payout_requests'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sidebarCounts['payout_requests'] }}</span>@endif
            </a>
            @unless($canUseHubTransfers)
            <a href="{{ route('rider.remittances') }}"
               class="sl-nav-link {{ $active === 'remittances' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.cod_remittances') }}">
                <span class="sl-nav-icon">{!! $icon('earnings') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.cod_remittances') }}</span>
            </a>
            @endunless
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">{{ __('navigation.sections.account') }}</span>

            <a href="{{ route('rider.messages') }}"
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

            <a href="{{ route('rider.account') }}"
               class="sl-nav-link {{ $active === 'account' ? 'is-active' : '' }}"
               data-title="{{ __('navigation.items.profile') }}">
                <span class="sl-nav-icon">{!! $icon('account') !!}</span>
                <span class="sl-nav-text">{{ __('navigation.items.profile') }}</span>
            </a>
            <a href="{{ route('rider.settings') }}"
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
            data-theme-preference-url="{{ route('rider.settings.theme.update') }}"
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
