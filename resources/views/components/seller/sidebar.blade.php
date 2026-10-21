@props(['active' => 'dashboard'])

@php
    $icon = fn (string $name) => match ($name) {
        'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'products' => '<svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/></svg>',
        'inventory' => '<svg viewBox="0 0 24 24"><path d="M3 6h18v14H3zM3 10h18M8 6V3h8v3"/><path d="M9 14h6"/></svg>',
        'orders' => '<svg viewBox="0 0 24 24"><path d="M6 3h12l2 4v14H4V7zM4 7h16M9 11h6M9 15h6"/></svg>',
        'messages' => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>',
        'notifications' => '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        'reviews' => '<svg viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/></svg>',
        'marketing' => '<svg viewBox="0 0 24 24"><path d="M4 13V7l12-4v14L4 13zM8 14v6H5l-1-7"/><path d="M16 8a4 4 0 0 1 0 4"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        'payouts' => '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4M8 3v2m8-2v2"/></svg>',
        'store' => '<svg viewBox="0 0 24 24"><path d="M3 9h18l-2-5H5zM5 9v11h14V9M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg>',
        'account' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.6-1.4-2.4L7.3 15a8 8 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.6 1.4 2.4-1.4 1.1a8 8 0 0 1-.1 2Z"/></svg>',
        'appearance' => '<svg viewBox="0 0 24 24"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>',
    };

    $productActive = $active === 'products' && request('mode') !== 'inventory';
    $inventoryActive = $active === 'products' && request('mode') === 'inventory';
    $ordersActive = in_array($active, ['orders', 'logistics'], true);
    $notificationActive = $active === 'notifications' || request()->routeIs('notifications.index', 'seller.notifications');
@endphp

<aside id="seller-sidebar" class="sl-sidebar" data-sl-sidebar aria-label="Seller Center navigation">
    <div class="sl-sidebar-head">
        <button class="sl-icon-btn sl-sidebar-toggle" type="button" data-sl-sidebar-toggle aria-label="Collapse sidebar" aria-expanded="true">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="{{ route('seller.dashboard') }}" class="sl-brand" aria-label="LIKHAE Seller Center">
            <x-shared.likhae-logo context="Seller Center" class="likhae-logo--sidebar" />
        </a>
    </div>

    <nav class="sl-sidebar-scroll" aria-label="Seller Center pages">
        <div class="sl-nav-section">
            <span class="sl-nav-label">Navigation</span>
            <a href="{{ route('seller.dashboard') }}" class="sl-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}" data-title="Dashboard" @if($active === 'dashboard') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('dashboard') !!}</span>
                <span class="sl-nav-text">Dashboard</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Store Operations</span>
            <a href="{{ route('seller.products') }}" class="sl-nav-link {{ $productActive ? 'is-active' : '' }}" data-title="Products" @if($productActive) aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('products') !!}</span>
                <span class="sl-nav-text">Products</span>
            </a>
            <a href="{{ route('seller.products', ['mode' => 'inventory']) }}" class="sl-nav-link {{ $inventoryActive ? 'is-active' : '' }}" data-title="Inventory" @if($inventoryActive) aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('inventory') !!}</span>
                <span class="sl-nav-text">Inventory</span>
            </a>
            <a href="{{ route('seller.orders') }}" class="sl-nav-link {{ $ordersActive ? 'is-active' : '' }}" data-title="Orders" @if($ordersActive) aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('orders') !!}</span>
                <span class="sl-nav-text">Orders</span>
                @if(($sellerSidebarCounts['to_process'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sellerSidebarCounts['to_process'] }}</span>@endif
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Customer Service</span>
            <a href="{{ route('seller.messages') }}" class="sl-nav-link {{ $active === 'messages' ? 'is-active' : '' }}" data-title="Messages" @if($active === 'messages') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('messages') !!}</span>
                <span class="sl-nav-text">Messages</span>
                @if(($sellerSidebarCounts['messages'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sellerSidebarCounts['messages'] }}</span>@endif
            </a>
            <a href="{{ route('notifications.index') }}" class="sl-nav-link {{ $notificationActive ? 'is-active' : '' }}" data-title="Notifications" @if($notificationActive) aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('notifications') !!}</span>
                <span class="sl-nav-text">Notifications</span>
                <span class="sl-nav-badge" data-seller-notification-count style="{{ ($sellerUiCounts['notifications'] ?? 0) > 0 ? '' : 'display:none' }}">{{ $sellerUiCounts['notifications'] ?? 0 }}</span>
            </a>
            <a href="{{ route('seller.reviews') }}" class="sl-nav-link {{ $active === 'reviews' ? 'is-active' : '' }}" data-title="Reviews" @if($active === 'reviews') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('reviews') !!}</span>
                <span class="sl-nav-text">Reviews</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Growth &amp; Finance</span>
            <a href="{{ route('seller.marketing') }}" class="sl-nav-link {{ $active === 'marketing' ? 'is-active' : '' }}" data-title="Marketing" @if($active === 'marketing') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('marketing') !!}</span>
                <span class="sl-nav-text">Marketing</span>
            </a>
            <a href="{{ route('seller.wallet') }}" class="sl-nav-link {{ $active === 'payouts' ? 'is-active' : '' }}" data-title="Wallet &amp; Payouts" @if($active === 'payouts') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('payouts') !!}</span>
                <span class="sl-nav-text">Wallet &amp; Payouts</span>
                @if(($sellerSidebarCounts['payout_requests'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sellerSidebarCounts['payout_requests'] }}</span>@endif
            </a>
            <a href="{{ route('seller.reports') }}" class="sl-nav-link {{ $active === 'reports' ? 'is-active' : '' }}" data-title="Reports" @if($active === 'reports') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span>
                <span class="sl-nav-text">Reports</span>
            </a>
            <a href="{{ route('seller.store', ['tab' => 'settings']) }}" class="sl-nav-link {{ $active === 'store' ? 'is-active' : '' }}" data-title="Store Settings" @if($active === 'store') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('store') !!}</span>
                <span class="sl-nav-text">Store Settings</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Account</span>
            <a href="{{ route('seller.account', ['tab' => 'profile']) }}" class="sl-nav-link {{ $active === 'account' ? 'is-active' : '' }}" data-title="My Account" @if($active === 'account') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('account') !!}</span>
                <span class="sl-nav-text">My Account</span>
            </a>
            <a href="{{ route('seller.settings') }}" class="sl-nav-link {{ $active === 'settings' ? 'is-active' : '' }}" data-title="System Settings" @if($active === 'settings') aria-current="page" @endif>
                <span class="sl-nav-icon">{!! $icon('settings') !!}</span>
                <span class="sl-nav-text">System Settings</span>
            </a>
        </div>
    </nav>

    <div class="sl-sidebar-foot">
        <button id="themeToggle" type="button" class="sl-nav-link sl-appearance" data-title="Appearance" aria-label="Toggle light or dark mode">
            <span class="sl-nav-icon">{!! $icon('appearance') !!}</span>
            <span class="sl-nav-text"><strong>Appearance</strong><small>Light / Dark Mode</small></span>
            <span id="themeToggleBadge" class="sl-theme-badge">ON</span>
        </button>
        <form method="POST" action="{{ Route::has('logout') ? route('logout') : url('/logout') }}" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
            @csrf
            <button type="submit" class="sl-nav-link sl-logout" data-title="Logout">
                <span class="sl-nav-icon">{!! $icon('logout') !!}</span>
                <span class="sl-nav-text">Logout</span>
            </button>
        </form>
    </div>
</aside>
