@props(['active' => 'logistics', 'workspaceName' => 'Logistics Center'])
@php
    $demoUser = session('demo_user');
    $name     = $demoUser['email'] ?? 'Logistics Operator';
    $initial  = mb_strtoupper(mb_substr($name, 0, 1));

    $icon = fn (string $key) => match ($key) {
        'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'parcels'   => '<svg viewBox="0 0 24 24"><path d="M6 3h12l2 4v14H4V7zM4 7h16M9 11h6"/></svg>',
        'shipping'  => '<svg viewBox="0 0 24 24"><path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>',
        'monitor'   => '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
        'rider'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'apply'     => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>',
        'reports'   => '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        'messages'  => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/></svg>',
        'account'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'logout'    => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>',
    };
@endphp

<aside class="sl-sidebar" data-sl-sidebar aria-label="Logistics Center navigation">

    <div class="sl-sidebar-head">
        <button class="sl-icon-btn sl-sidebar-toggle" type="button" data-sl-sidebar-toggle aria-label="Collapse sidebar">
            <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="{{ route('logistics.dashboard') }}" class="sl-brand">
            <span class="sl-brand-mark">L</span>
            <span class="sl-brand-copy"><strong>LIKHAE</strong><small>{{ $workspaceName }}</small></span>
        </a>
    </div>

    <div class="sl-seller-card">
        <span class="sl-avatar">{{ $initial }}</span>
        <span class="sl-seller-copy"><strong>{{ $name }}</strong><small>Sorting Center</small></span>
        <span class="sl-verified">✓</span>
    </div>

    <nav class="sl-sidebar-scroll">

        <a href="{{ route('logistics.dashboard') }}"
           class="sl-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}"
           data-title="Dashboard">
            <span class="sl-nav-icon">{!! $icon('dashboard') !!}</span>
            <span class="sl-nav-text">Dashboard</span>
        </a>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Rider Management</span>

            <a href="{{ route('logistics.riders.applications') }}"
               class="sl-nav-link {{ $active === 'rider-applications' ? 'is-active' : '' }}"
               data-title="Rider Applications">
                <span class="sl-nav-icon">{!! $icon('apply') !!}</span>
                <span class="sl-nav-text">Rider Applications</span>
            </a>

            <a href="{{ route('logistics.riders') }}"
               class="sl-nav-link {{ $active === 'riders' ? 'is-active' : '' }}"
               data-title="All Riders">
                <span class="sl-nav-icon">{!! $icon('rider') !!}</span>
                <span class="sl-nav-text">All Riders</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Parcel Operations</span>

            <a href="{{ route('logistics.pickups') }}"
               class="sl-nav-link {{ $active === 'pickup-requests' ? 'is-active' : '' }}"
               data-title="Pickup Requests">
                <span class="sl-nav-icon">{!! $icon('shipping') !!}</span>
                <span class="sl-nav-text">Pickup Requests</span>
            </a>

            <a href="{{ route('logistics.parcels') }}"
               class="sl-nav-link {{ $active === 'parcels' ? 'is-active' : '' }}"
               data-title="Incoming Parcels">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">Incoming Parcels</span>
            </a>

            <a href="{{ route('logistics.sorting') }}"
               class="sl-nav-link {{ $active === 'sorting' ? 'is-active' : '' }}"
               data-title="Parcel Sorting">
                <span class="sl-nav-icon">{!! $icon('parcels') !!}</span>
                <span class="sl-nav-text">Parcel Sorting</span>
            </a>

            <a href="{{ route('logistics.dispatch') }}"
               class="sl-nav-link {{ $active === 'assignments' ? 'is-active' : '' }}"
               data-title="Delivery Assignment">
                <span class="sl-nav-icon">{!! $icon('shipping') !!}</span>
                <span class="sl-nav-text">Delivery Assignment</span>
            </a>

            <a href="{{ route('logistics.parcels.tracking') }}"
               class="sl-nav-link {{ $active === 'monitoring' ? 'is-active' : '' }}"
               data-title="Delivery Monitoring">
                <span class="sl-nav-icon">{!! $icon('monitor') !!}</span>
                <span class="sl-nav-text">Delivery Monitoring</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Reports</span>

            <a href="{{ route('logistics.reports') }}"
               class="sl-nav-link {{ $active === 'reports' ? 'is-active' : '' }}"
               data-title="Reports">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">Reports</span>
            </a>
            <a href="{{ route('logistics.remittances') }}"
               class="sl-nav-link {{ $active === 'remittances' ? 'is-active' : '' }}"
               data-title="COD Remittances">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">COD Remittances</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Account</span>

            <a href="{{ route('logistics.messages') }}"
               class="sl-nav-link {{ $active === 'messages' ? 'is-active' : '' }}"
               data-title="Messages">
                <span class="sl-nav-icon">{!! $icon('messages') !!}</span>
                <span class="sl-nav-text">Messages</span>
            </a>

            <a href="{{ route('logistics.profile') }}"
               class="sl-nav-link {{ $active === 'account' ? 'is-active' : '' }}"
               data-title="Account">
                <span class="sl-nav-icon">{!! $icon('account') !!}</span>
                <span class="sl-nav-text">Profile</span>
            </a>
        </div>

    </nav>

    <div class="sl-sidebar-foot">
        {{-- DARK MODE TOGGLE --}}
        <button
            id="themeToggle"
            type="button"
            class="sl-nav-link"
            data-title="Appearance"
            style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;background:none;border:none;"
        >
            <span style="display:flex;align-items:center;gap:8px;">
                <span class="sl-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.6;"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></svg>
                </span>
                <span style="display:grid;text-align:left;">
                    <strong style="color:inherit;font-size:10.5px;font-weight:500;line-height:1.1;">Appearance</strong>
                    <span style="margin-top:2px;color:currentColor;opacity:0.5;font-size:8px;line-height:1.1;">Light / Dark Mode</span>
                </span>
            </span>
            <span id="themeToggleBadge" style="display:inline-flex;min-height:21px;align-items:center;padding:0 9px;border-radius:9999px;background:#E3E3E2;color:#494644;font-size:7px;font-weight:900;white-space:nowrap;">OFF</span>
        </button>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sl-nav-link sl-logout" data-title="Logout">
                <span class="sl-nav-icon">{!! $icon('logout') !!}</span>
                <span class="sl-nav-text">Logout</span>
            </button>
        </form>
    </div>

</aside>
