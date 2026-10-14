@props(['active' => 'dashboard'])

@php
    $seller = auth()->user();
    $sellerName = $seller?->name ?? 'Seller';
    $shopName = $seller?->sellerProfile?->business_name ?? 'LIKHAE Studio';
    $initial = mb_strtoupper(mb_substr($sellerName, 0, 1));

    $icon = fn (string $name) => match ($name) {
        'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'products' => '<svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/></svg>',
        'add' => '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
        'inventory' => '<svg viewBox="0 0 24 24"><path d="M3 6h18v14H3zM3 10h18M8 6V3h8v3"/><path d="M9 14h6"/></svg>',
        'orders' => '<svg viewBox="0 0 24 24"><path d="M6 3h12l2 4v14H4V7zM4 7h16M9 11h6M9 15h6"/></svg>',
        'logistics' => '<svg viewBox="0 0 24 24"><path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>',
        'messages' => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>',
        'reviews' => '<svg viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/></svg>',
        'marketing' => '<svg viewBox="0 0 24 24"><path d="M4 13V7l12-4v14L4 13zM8 14v6H5l-1-7"/><path d="M16 8a4 4 0 0 1 0 4"/></svg>',
        'finance' => '<svg viewBox="0 0 24 24"><path d="M3 7h18v13H3zM3 10h18M7 16h3"/><path d="M7 4h10"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        'store' => '<svg viewBox="0 0 24 24"><path d="M3 9h18l-2-5H5zM5 9v11h14V9M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg>',
        'account' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        default => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>',
    };

    $ordersActive = in_array($active, ['orders', 'logistics'], true);
    $marketingActive = $active === 'marketing';
@endphp

<aside class="sl-sidebar" data-sl-sidebar aria-label="Seller Center navigation">
    <div class="sl-sidebar-head">
        <button class="sl-icon-btn sl-sidebar-toggle" type="button" data-sl-sidebar-toggle aria-label="Collapse sidebar" aria-expanded="true">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <a href="{{ route('seller.dashboard') }}" class="sl-brand" aria-label="LIKHAE Seller Center">
            <x-likhae-logo context="Seller Center" class="likhae-logo--sidebar" />
        </a>
    </div>

    <div class="sl-seller-card">
        <span class="sl-avatar">{{ $initial }}</span>
        <span class="sl-seller-copy">
            <strong>{{ $sellerName }}</strong>
            <small>{{ $shopName }}</small>
        </span>
        <span class="sl-verified" title="Verified seller" aria-label="Verified seller">✓</span>
    </div>

    <nav class="sl-sidebar-scroll">
        <a href="{{ route('seller.dashboard') }}" class="sl-nav-link {{ $active === 'dashboard' ? 'is-active' : '' }}" data-title="Dashboard">
            <span class="sl-nav-icon">{!! $icon('dashboard') !!}</span>
            <span class="sl-nav-text">Dashboard</span>
        </a>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Product Management</span>
            <a href="{{ route('seller.products') }}" class="sl-nav-link {{ $active === 'products' && request('mode', 'list') === 'list' ? 'is-active' : '' }}" data-title="Products">
                <span class="sl-nav-icon">{!! $icon('products') !!}</span><span class="sl-nav-text">Products</span>
            </a>
            <a href="{{ route('seller.products', ['mode' => 'add']) }}" class="sl-nav-link {{ $active === 'products' && request('mode') === 'add' ? 'is-active' : '' }}" data-title="Add Product">
                <span class="sl-nav-icon">{!! $icon('add') !!}</span><span class="sl-nav-text">Add Product</span>
            </a>
            <a href="{{ route('seller.products', ['mode' => 'inventory']) }}" class="sl-nav-link {{ $active === 'products' && request('mode') === 'inventory' ? 'is-active' : '' }}" data-title="Inventory">
                <span class="sl-nav-icon">{!! $icon('inventory') !!}</span><span class="sl-nav-text">Inventory</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Order Management</span>
            <button type="button" class="sl-nav-link sl-nav-toggle {{ $ordersActive ? 'is-active is-open' : '' }}" data-nav-toggle aria-expanded="{{ $ordersActive ? 'true' : 'false' }}" data-title="Orders">
                <span class="sl-nav-icon">{!! $icon('orders') !!}</span><span class="sl-nav-text">Orders</span><span class="sl-nav-arrow">⌄</span>
            </button>
            <div class="sl-nav-submenu {{ $ordersActive ? 'is-open' : '' }}" data-nav-submenu @if (!$ordersActive) hidden @endif>
                <a href="{{ route('seller.orders', ['status' => 'to-process']) }}">New Orders @if(($sellerSidebarCounts['to_process'] ?? 0) > 0)<span>{{ $sellerSidebarCounts['to_process'] }}</span>@endif</a>
                <a href="{{ route('seller.orders', ['status' => 'to-prepare']) }}">To Prepare @if(($sellerSidebarCounts['to_prepare'] ?? 0) > 0)<span>{{ $sellerSidebarCounts['to_prepare'] }}</span>@endif</a>
                <a href="{{ route('seller.orders', ['status' => 'ready-pickup']) }}">Ready for Pickup</a>
                <a href="{{ route('seller.orders', ['status' => 'shipping']) }}">Shipping</a>
                <a href="{{ route('seller.orders', ['status' => 'completed']) }}">Completed</a>
                <a href="{{ route('seller.orders', ['status' => 'returns']) }}">Returns / Refunds</a>
            </div>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Logistics</span>
            <button type="button" class="sl-nav-link sl-nav-toggle {{ $active === 'logistics' ? 'is-active is-open' : '' }}" data-nav-toggle aria-expanded="{{ $active === 'logistics' ? 'true' : 'false' }}" data-title="Logistics">
                <span class="sl-nav-icon">{!! $icon('logistics') !!}</span><span class="sl-nav-text">Logistics</span><span class="sl-nav-arrow">⌄</span>
            </button>
            <div class="sl-nav-submenu {{ $active === 'logistics' ? 'is-open' : '' }}" data-nav-submenu @if ($active !== 'logistics') hidden @endif>
                <a href="{{ route('seller.logistics', ['view' => 'couriers']) }}">Assign Courier</a>
                <a href="{{ route('seller.logistics', ['view' => 'pickups']) }}">Pickup Requests</a>
                <a href="{{ route('seller.logistics', ['view' => 'tracking']) }}">Shipment Tracking</a>
            </div>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Customer Service</span>
            <a href="{{ route('seller.messages') }}" class="sl-nav-link {{ $active === 'messages' ? 'is-active' : '' }}" data-title="Messages">
                <span class="sl-nav-icon">{!! $icon('messages') !!}</span><span class="sl-nav-text">Messages</span>@if(($sellerSidebarCounts['messages'] ?? 0) > 0)<span class="sl-nav-badge">{{ $sellerSidebarCounts['messages'] }}</span>@endif
            </a>
            <a href="{{ route('seller.reviews') }}" class="sl-nav-link {{ $active === 'reviews' ? 'is-active' : '' }}" data-title="Reviews">
                <span class="sl-nav-icon">{!! $icon('reviews') !!}</span><span class="sl-nav-text">Reviews</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Marketing</span>
            <button type="button" class="sl-nav-link sl-nav-toggle {{ $marketingActive ? 'is-active is-open' : '' }}" data-nav-toggle aria-expanded="{{ $marketingActive ? 'true' : 'false' }}" data-title="Marketing">
                <span class="sl-nav-icon">{!! $icon('marketing') !!}</span><span class="sl-nav-text">Marketing</span><span class="sl-nav-arrow">⌄</span>
            </button>
            <div class="sl-nav-submenu {{ $marketingActive ? 'is-open' : '' }}" data-nav-submenu @if (!$marketingActive) hidden @endif>
                <a href="{{ route('seller.marketing', ['tab' => 'discounts']) }}">Discounts</a>
                <a href="{{ route('seller.marketing', ['tab' => 'vouchers']) }}">Vouchers</a>
                <a href="{{ route('seller.marketing', ['tab' => 'promotions']) }}">Promotions</a>
            </div>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Growth &amp; Finance</span>
            <a href="{{ route('seller.reports') }}" class="sl-nav-link {{ $active === 'reports' ? 'is-active' : '' }}" data-title="Reports">
                <span class="sl-nav-icon">{!! $icon('reports') !!}</span><span class="sl-nav-text">Reports</span>
            </a>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Store</span>
            <button type="button" class="sl-nav-link sl-nav-toggle {{ $active === 'store' ? 'is-active is-open' : '' }}" data-nav-toggle aria-expanded="{{ $active === 'store' ? 'true' : 'false' }}" data-title="Store">
                <span class="sl-nav-icon">{!! $icon('store') !!}</span><span class="sl-nav-text">Store</span><span class="sl-nav-arrow">⌄</span>
            </button>
            <div class="sl-nav-submenu {{ $active === 'store' ? 'is-open' : '' }}" data-nav-submenu @if ($active !== 'store') hidden @endif>
                <a href="{{ route('seller.store', ['tab' => 'profile']) }}">Shop Profile</a>
                <a href="{{ route('seller.store', ['tab' => 'settings']) }}">Store Settings</a>
            </div>
        </div>

        <div class="sl-nav-section">
            <span class="sl-nav-label">Account</span>
            <button type="button" class="sl-nav-link sl-nav-toggle {{ $active === 'account' ? 'is-active is-open' : '' }}" data-nav-toggle aria-expanded="{{ $active === 'account' ? 'true' : 'false' }}" data-title="Account">
                <span class="sl-nav-icon">{!! $icon('account') !!}</span><span class="sl-nav-text">Account</span><span class="sl-nav-arrow">⌄</span>
            </button>
            <div class="sl-nav-submenu {{ $active === 'account' ? 'is-open' : '' }}" data-nav-submenu @if ($active !== 'account') hidden @endif>
                <a href="{{ route('seller.account', ['tab' => 'profile']) }}">Profile</a>
                <a href="{{ route('seller.account', ['tab' => 'business']) }}">Business Information</a>
                <a href="{{ route('seller.account', ['tab' => 'verification']) }}">Verification</a>
                <a href="{{ route('seller.account', ['tab' => 'security']) }}">Security</a>
                <a href="{{ route('seller.account', ['tab' => 'notifications']) }}">Notifications</a>
            </div>
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
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.6;"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></svg>
                </span>
                <span class="sl-nav-text" style="flex-direction:column;align-items:flex-start;">
                    <span style="display:block;font-weight:600;font-size:.8125rem;">Appearance</span>
                    <span style="display:block;font-size:.7rem;opacity:.6;">Light / Dark Mode</span>
                </span>
            </span>
            <span id="themeToggleBadge" style="border-radius:9999px;padding:2px 10px;font-size:.7rem;font-weight:700;background:#c92d2f;color:#fff;">ON</span>
        </button>
        <form method="POST" action="{{ Route::has('logout') ? route('logout') : url('/logout') }}" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
            @csrf
            <button type="submit" class="sl-nav-link sl-logout" data-title="Logout">
                <span class="sl-nav-icon">{!! $icon('logout') !!}</span><span class="sl-nav-text">Logout</span>
            </button>
        </form>
    </div>
</aside>
