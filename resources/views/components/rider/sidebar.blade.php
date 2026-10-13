@props(['active' => 'dashboard', 'workspaceName' => 'Rider Panel'])
@php
    $demoUser = session('demo_user');
    $name     = $demoUser['email'] ?? 'Rider';
    $initial  = mb_strtoupper(mb_substr($name, 0, 1));

    $icon = fn (string $key) => match ($key) {
        'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'pickup'    => '<svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
        'delivery'  => '<svg viewBox="0 0 24 24"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="21" r="1"/><circle cx="20" cy="21" r="1"/></svg>',
        'history'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
        'earnings'  => '<svg viewBox="0 0 24 24"><path d="M6 3h8a4 4 0 0 1 0 8H6z"/><path d="M6 11h8a4 4 0 0 1 0 8H6z"/><path d="M4 7h4"/><path d="M4 15h4"/><path d="M6 3v18"/></svg>',
        'messages'  => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/></svg>',
        'account'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'logout'    => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>',
    };
@endphp

<aside class="sl-sidebar" data-sl-sidebar aria-label="Rider Panel navigation">

    <div class="sl-sidebar-head">
        <button class="sl-icon-btn sl-sidebar-toggle" type="button" data-sl-sidebar-toggle aria-label="Collapse sidebar">
            <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="{{ route('rider.dashboard') }}" class="sl-brand">
            <span class="sl-brand-mark">L</span>
            <span class="sl-brand-copy"><strong>LIKHAE</strong><small>{{ $workspaceName }}</small></span>
        </a>
    </div>

    <div class="sl-seller-card">
        <span class="sl-avatar">{{ $initial }}</span>
        <span class="sl-seller-copy"><strong>{{ $name }}</strong><small>Rider</small></span>
        <span class="sl-verified">✓</span>
    </div>

    <nav class="sl-sidebar-scroll">

        <a href="{{ route('rider.dashboard') }}"
           class="sl-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}"
           data-title="Dashboard">
            <span class="sl-nav-icon">{!! $icon('dashboard') !!}</span>
            <span class="sl-nav-text">Dashboard</span>
        </a>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Pickups</span>

            <a href="{{ route('rider.pickups') }}"
               class="sl-nav-link {{ $active === 'pickups' ? 'is-active' : '' }}"
               data-title="Pickup Assignments">
                <span class="sl-nav-icon">{!! $icon('pickup') !!}</span>
                <span class="sl-nav-text">Pickup Assignments</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Deliveries</span>

            <a href="{{ route('rider.deliveries') }}"
               class="sl-nav-link {{ $active === 'deliveries' ? 'is-active' : '' }}"
               data-title="Delivery Assignments">
                <span class="sl-nav-icon">{!! $icon('delivery') !!}</span>
                <span class="sl-nav-text">Delivery Assignments</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">History</span>

            <a href="{{ route('rider.history') }}"
               class="sl-nav-link {{ $active === 'history' ? 'is-active' : '' }}"
               data-title="Delivery History">
                <span class="sl-nav-icon">{!! $icon('history') !!}</span>
                <span class="sl-nav-text">Delivery History</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Earnings</span>

            <a href="{{ route('rider.earnings') }}"
               class="sl-nav-link {{ $active === 'earnings' ? 'is-active' : '' }}"
               data-title="Earnings">
                <span class="sl-nav-icon">{!! $icon('earnings') !!}</span>
                <span class="sl-nav-text">Earnings</span>
            </a>
            <a href="{{ route('rider.remittances') }}"
               class="sl-nav-link {{ $active === 'remittances' ? 'is-active' : '' }}"
               data-title="COD Remittances">
                <span class="sl-nav-icon">{!! $icon('earnings') !!}</span>
                <span class="sl-nav-text">COD Remittances</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Account</span>

            <a href="{{ route('rider.messages') }}"
               class="sl-nav-link {{ $active === 'messages' ? 'is-active' : '' }}"
               data-title="Messages">
                <span class="sl-nav-icon">{!! $icon('messages') !!}</span>
                <span class="sl-nav-text">Messages</span>
            </a>

            <a href="{{ route('rider.account') }}"
               class="sl-nav-link {{ $active === 'account' ? 'is-active' : '' }}"
               data-title="Profile">
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
