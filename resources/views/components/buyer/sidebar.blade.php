@props(['active' => 'home', 'cartCount' => null, 'messageCount' => null])

@php
    $buyer = auth()->user();
    $demoUser = session('demo_user');

    $cartCount = $cartCount ?? (int) data_get($buyerUiCounts ?? [], 'cart', 0);
    $messageCount = $messageCount ?? (int) data_get($buyerUiCounts ?? [], 'messages', 0);

    $buyerName = data_get($buyer, 'name')
        ?? data_get($demoUser, 'name')
        ?? 'Buyer Account';

    $buyerInitials = collect(explode(' ', trim($buyerName)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('') ?: 'BA';

    $profilePhoto = data_get($buyer, 'profile_photo_path')
        ?? data_get($buyer, 'profile_photo_url')
        ?? data_get($buyer, 'avatar_url');


    $orderStatus = request('status', 'all');
    $rewardTab = request('tab', 'vouchers');
    $accountTab = request('tab', 'profile');

    /*
     * Seller-sidebar inspired icon language:
     * larger 18px outline icons, 1.6 stroke, rounded caps/joins.
     */
    $icons = [
        'home' => '
            <rect x="3" y="3" width="7" height="7"></rect>
            <rect x="14" y="3" width="7" height="7"></rect>
            <rect x="3" y="14" width="7" height="7"></rect>
            <rect x="14" y="14" width="7" height="7"></rect>
        ',

        'products' => '
            <path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"></path>
            <path d="m4 7.5 8 4.5 8-4.5"></path>
            <path d="M12 12v9"></path>
        ',

        'cart' => '
            <path d="M3 4h2l2.1 10h10.8l2-7H6"></path>
            <circle cx="9" cy="19" r="1.4"></circle>
            <circle cx="18" cy="19" r="1.4"></circle>
        ',

        'messages' => '
            <path d="M4 5h16v11H8l-4 4z"></path>
            <path d="M8 9h8"></path>
            <path d="M8 13h5"></path>
        ',

        'orders' => '
            <path d="M6 3h12l2 4v14H4V7z"></path>
            <path d="M4 7h16"></path>
            <path d="M9 11h6"></path>
            <path d="M9 15h6"></path>
        ',

        'rewards' => '
            <path d="M20 12v9H4v-9"></path>
            <path d="M2 7h20v5H2z"></path>
            <path d="M12 7v14"></path>
            <path d="M12 7H7.5A2.5 2.5 0 1 1 10 4.5L12 7Z"></path>
            <path d="M12 7h4.5A2.5 2.5 0 1 0 14 4.5L12 7Z"></path>
        ',

        'account' => '
            <circle cx="12" cy="8" r="4"></circle>
            <path d="M4 21a8 8 0 0 1 16 0"></path>
        ',

        'shop' => '
            <path d="M3 9h18l-2-5H5z"></path>
            <path d="M5 9v11h14V9"></path>
            <path d="M9 20v-6h6v6"></path>
        ',

        'partner' => '
            <path d="M8 12h8"></path>
            <path d="M10 7 7 4 4 7l3 3"></path>
            <path d="m14 17 3 3 3-3-3-3"></path>
            <path d="m7 10 3 3m7 1-3-3"></path>
        ',

        'logout' => '
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <path d="m16 17 5-5-5-5"></path>
            <path d="M21 12H9"></path>
        ',
    ];

    $topLinks = [
        [
            'key' => 'home',
            'label' => 'Home',
            'route' => 'buyer.home',
            'icon' => 'home',
        ],
        [
            'key' => 'products',
            'label' => 'Categories',
            'route' => 'buyer.products',
            'icon' => 'products',
        ],
        [
            'key' => 'cart',
            'label' => 'Cart',
            'route' => 'buyer.cart',
            'icon' => 'cart',
            'count' => $cartCount,
        ],
        [
            'key' => 'messages',
            'label' => 'Messages',
            'route' => 'buyer.messages',
            'icon' => 'messages',
            'count' => $messageCount,
        ],
    ];

    $orderLinks = [
        'all' => 'All',
        'to-pay' => 'To Pay',
        'to-ship' => 'To Ship',
        'to-receive' => 'To Receive',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'returns' => 'Return / Refund',
    ];

    $rewardLinks = [
        'vouchers' => 'My Vouchers',
        'points' => 'Reward Points',
        'cashback' => 'Cashback',
    ];

    $accountLinks = [
        'profile' => 'Profile',
        'addresses' => 'Addresses',
        'password' => 'Change Password',
    ];
@endphp

<style>
    :root {
        --buyer-side-width: 252px;
        --buyer-side-collapsed: 76px;

        --buyer-bg: #FFFDF9;
        --buyer-page: #FBF7F2;
        --buyer-soft: #FAF3EC;
        --buyer-active: #F3E4DE;

        --buyer-line: #E8D8C8;
        --buyer-line-strong: #DCC8B8;

        --buyer-primary: #641F19;
        --buyer-primary-dark: #4D1712;

        --buyer-text: #694737;
        --buyer-text-strong: #311E18;
        --buyer-muted: #A27F6C;
        --buyer-muted-light: #A88B7B;
    }

    .lk-sidebar,
    .lk-sidebar * {
        box-sizing: border-box;
    }

    .lk-sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 90;
        display: flex;
        width: var(--buyer-side-width);
        height: 100vh;
        flex-direction: column;
        overflow: hidden;
        border-right: 1px solid var(--buyer-line);
        background: var(--buyer-bg);
        color: var(--buyer-text);
        font-family: "DM Sans", system-ui, sans-serif;
        transition:
            width .22s ease,
            transform .22s ease;
    }

    /* HEADER ------------------------------------------------------ */

    .lk-sidebar-head {
        display: flex;
        min-height: 62px;
        align-items: center;
        gap: 11px;
        padding: 0 11px;
        border-bottom: 1px solid var(--buyer-line);
    }

    .lk-side-toggle {
        display: grid;
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        place-items: center;
        border: 1px solid var(--buyer-line);
        border-radius: 10px;
        background: #FAF3EC;
        color: #6C4936;
        cursor: pointer;
        transition: .15s ease;
    }

    .lk-side-toggle:hover {
        border-color: var(--buyer-line-strong);
        background: var(--buyer-active);
        color: var(--buyer-primary);
    }

    .lk-side-toggle svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.7;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .lk-brand {
        display: flex;
        min-width: 0;
        align-items: center;
        color: inherit;
        text-decoration: none;
    }

    .lk-brand .likhae-logo--sidebar {
        width: auto;
        max-width: 151px;
        max-height: 40px;
    }

    /* PROFILE ----------------------------------------------------- */

    .lk-sidebar-profile {
        display: grid;
        grid-template-columns: auto minmax(0,1fr) auto;
        align-items: center;
        gap: 10px;
        margin: 13px 10px 8px;
        padding: 10px;
        min-height: 61px;
        border: 1px solid var(--buyer-line);
        border-radius: 13px;
        background:
            radial-gradient(circle at 94% 9%, rgba(193,151,113,.12), transparent 34%),
            var(--buyer-soft);
    }

    .lk-sidebar-avatar {
        display: grid;
        width: 39px;
        height: 39px;
        place-items: center;
        overflow: hidden;
        border-radius: 10px;
        background: var(--buyer-primary);
        color: #FFF;
        font-size: 10px;
        font-weight: 900;
    }

    .lk-sidebar-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .lk-sidebar-profile-copy {
        min-width: 0;
    }

    .lk-sidebar-profile-copy strong {
        display: block;
        overflow: hidden;
        color: var(--buyer-text-strong);
        font-size: 10px;
        font-weight: 800;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-sidebar-profile-copy span {
        display: block;
        margin-top: 3px;
        color: var(--buyer-muted);
        font-size: 7px;
        line-height: 1.15;
    }

    .lk-sidebar-verified {
        display: grid;
        width: 19px;
        height: 19px;
        place-items: center;
        border-radius: 999px;
        background: var(--buyer-primary);
        color: #FFF;
        font-size: 9px;
        font-weight: 900;
    }

    /* SCROLL AREA ------------------------------------------------- */

    .lk-sidebar-scroll {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 4px 8px 14px;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #D8C2B1 transparent;
    }

    .lk-sidebar-scroll::-webkit-scrollbar {
        width: 5px;
    }

    .lk-sidebar-scroll::-webkit-scrollbar-thumb {
        border-radius: 999px;
        background: #D8C2B1;
    }

    .lk-nav-section {
        display: grid;
        gap: 2px;
    }

    .lk-nav-section + .lk-nav-section {
        margin-top: 5px;
    }

    .lk-nav-section-title {
        display: block;
        padding: 14px 11px 6px;
        color: var(--buyer-muted-light);
        font-size: 7px;
        font-weight: 900;
        letter-spacing: .13em;
        text-transform: uppercase;
    }

    /* NAV ROWS ---------------------------------------------------- */

    .lk-nav-link,
    .lk-nav-toggle {
        position: relative;
        display: flex;
        min-height: 41px;
        width: 100%;
        align-items: center;
        gap: 10px;
        padding: 0 11px;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #72503F;
        font: inherit;
        font-size: 10.5px;
        font-weight: 500;
        text-align: left;
        text-decoration: none;
        cursor: pointer;
        transition: .14s ease;
    }

    .lk-nav-link:hover,
    .lk-nav-toggle:hover {
        background: var(--buyer-soft);
        color: var(--buyer-primary);
    }

    .lk-nav-link.is-active,
    .lk-nav-toggle.is-active {
        background: var(--buyer-active);
        color: var(--buyer-primary);
        font-weight: 700;
    }

    .lk-nav-link.is-active::before,
    .lk-nav-toggle.is-active::before {
        position: absolute;
        top: 8px;
        bottom: 8px;
        left: 0;
        width: 3px;
        border-radius: 0 6px 6px 0;
        background: var(--buyer-primary);
        content: "";
    }

    .lk-nav-icon {
        display: grid;
        width: 26px;
        height: 26px;
        flex: 0 0 26px;
        place-items: center;
        color: currentColor;
    }

    .lk-nav-icon svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.6;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .lk-nav-text {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-nav-count {
        display: inline-flex;
        min-width: 20px;
        height: 20px;
        align-items: center;
        justify-content: center;
        padding: 0 6px;
        border-radius: 999px;
        background: #9E7D68;
        color: #FFF;
        font-size: 8px;
        font-weight: 800;
    }

    .lk-nav-arrow {
        display: grid;
        width: 16px;
        height: 16px;
        flex: 0 0 16px;
        place-items: center;
        color: #8F6C59;
        transition: transform .18s ease;
    }

    .lk-nav-arrow svg {
        width: 12px;
        height: 12px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.65;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .lk-nav-toggle.is-open .lk-nav-arrow {
        transform: rotate(180deg);
    }

    /* SUBMENUS ---------------------------------------------------- */

    .lk-nav-submenu {
        display: grid;
        gap: 2px;
        margin: 2px 0 5px 37px;
        padding-left: 10px;
        border-left: 1px solid var(--buyer-line);
    }

    .lk-nav-submenu[hidden] {
        display: none;
    }

    .lk-nav-submenu a {
        display: flex;
        min-height: 33px;
        align-items: center;
        padding: 0 9px;
        border-radius: 8px;
        color: #8B6A59;
        font-size: 9px;
        font-weight: 500;
        text-decoration: none;
        transition: .14s ease;
    }

    .lk-nav-submenu a:hover {
        background: var(--buyer-soft);
        color: var(--buyer-primary);
    }

    .lk-nav-submenu a.is-active {
        background: var(--buyer-active);
        color: var(--buyer-primary);
        font-weight: 700;
    }

    /* FOOTER ------------------------------------------------------ */

    .lk-sidebar-foot {
        display: grid;
        flex: 0 0 auto;
        gap: 2px;
        padding: 7px 8px max(9px, env(safe-area-inset-bottom));
        border-top: 1px solid var(--buyer-line);
        background: var(--buyer-bg);
    }

    .lk-sidebar-foot form {
        margin: 0;
    }

    .lk-sidebar-foot .lk-nav-link {
        min-height: 43px;
    }

    #themeToggle {
        justify-content: space-between;
    }

    #themeToggle > .lk-theme-left {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 10px;
    }

    .lk-theme-copy {
        display: grid;
        min-width: 0;
        text-align: left;
    }

    .lk-theme-copy strong {
        color: inherit;
        font-size: 10.5px;
        font-weight: 500;
        line-height: 1.1;
    }

    .lk-theme-copy span {
        margin-top: 2px;
        color: var(--buyer-muted);
        font-size: 8px;
        line-height: 1.1;
    }

    #themeToggleBadge {
        display: inline-flex;
        min-height: 21px;
        align-items: center;
        padding: 0 9px;
        border-radius: 999px;
        background: #E3E3E2;
        color: #494644;
        font-size: 7px;
        font-weight: 900;
    }

    .lk-logout {
        width: 100%;
        justify-content: flex-start;
    }

    /* COLLAPSED --------------------------------------------------- */

    .lk-sidebar.is-collapsed {
        width: var(--buyer-side-collapsed);
    }

    .lk-sidebar.is-collapsed .lk-brand,
    .lk-sidebar.is-collapsed .lk-sidebar-profile-copy,
    .lk-sidebar.is-collapsed .lk-sidebar-verified,
    .lk-sidebar.is-collapsed .lk-nav-section-title,
    .lk-sidebar.is-collapsed .lk-nav-text,
    .lk-sidebar.is-collapsed .lk-nav-count,
    .lk-sidebar.is-collapsed .lk-nav-arrow,
    .lk-sidebar.is-collapsed .lk-nav-submenu,
    .lk-sidebar.is-collapsed .lk-theme-copy,
    .lk-sidebar.is-collapsed #themeToggleBadge {
        display: none !important;
    }

    .lk-sidebar.is-collapsed .lk-sidebar-head {
        justify-content: center;
        padding-inline: 0;
    }

    .lk-sidebar.is-collapsed .lk-sidebar-profile {
        display: flex;
        justify-content: center;
        margin-inline: 8px;
        padding: 8px 0;
    }

    .lk-sidebar.is-collapsed .lk-nav-link,
    .lk-sidebar.is-collapsed .lk-nav-toggle {
        justify-content: center;
        padding-inline: 0;
    }

    /* DARK -------------------------------------------------------- */

    html.dark .lk-sidebar,
    html.dark .lk-sidebar-foot {
        border-color: #30231F;
        background: #130F0E;
    }

    html.dark .lk-sidebar-head,
    html.dark .lk-sidebar-foot {
        border-color: #30231F;
    }

    html.dark .lk-side-toggle {
        border-color: #3B2E27;
        background: #211B17;
        color: #EBA99D;
    }

    html.dark .lk-sidebar-profile {
        border-color: #30231F;
        background: #1B1513;
    }

    html.dark .lk-sidebar-profile-copy strong {
        color: #F5EFE8;
    }

    html.dark .lk-sidebar-profile-copy span,
    html.dark .lk-theme-copy span,
    html.dark .lk-nav-section-title {
        color: #8F817A;
    }

    html.dark .lk-nav-link,
    html.dark .lk-nav-toggle {
        color: #B8AAA1;
    }

    html.dark .lk-nav-link:hover,
    html.dark .lk-nav-toggle:hover {
        background: #211B17;
        color: #F3C6BA;
    }

    html.dark .lk-nav-link.is-active,
    html.dark .lk-nav-toggle.is-active {
        background: #3A1111;
        color: #F3C6BA;
    }

    html.dark .lk-nav-link.is-active::before,
    html.dark .lk-nav-toggle.is-active::before {
        background: #A84538;
    }

    html.dark .lk-nav-submenu {
        border-color: #30231F;
    }

    html.dark .lk-nav-submenu a {
        color: #AFA19A;
    }

    html.dark .lk-nav-submenu a:hover,
    html.dark .lk-nav-submenu a.is-active {
        background: #211B17;
        color: #F3C6BA;
    }

    html.dark #themeToggleBadge {
        background: #A84538;
        color: #FFF;
    }

    /* MOBILE ------------------------------------------------------ */

    @media (max-width: 1023px) {
        .lk-sidebar,
        .lk-sidebar.is-collapsed {
            width: var(--buyer-side-width);
            height: 100dvh;
            max-height: 100dvh;
            transform: translateX(-100%);
        }

        .lk-sidebar.is-open,
        .lk-sidebar.lk-sidebar-open {
            transform: translateX(0);
        }

        .lk-sidebar.is-collapsed .lk-brand,
        .lk-sidebar.is-collapsed .lk-sidebar-profile-copy,
        .lk-sidebar.is-collapsed .lk-sidebar-verified,
        .lk-sidebar.is-collapsed .lk-nav-section-title,
        .lk-sidebar.is-collapsed .lk-nav-text,
        .lk-sidebar.is-collapsed .lk-nav-count,
        .lk-sidebar.is-collapsed .lk-nav-arrow,
        .lk-sidebar.is-collapsed .lk-theme-copy,
        .lk-sidebar.is-collapsed #themeToggleBadge {
            display: initial !important;
        }

        .lk-sidebar.is-collapsed .lk-nav-submenu.expanded {
            display: grid !important;
        }

        .lk-sidebar.is-collapsed .lk-sidebar-head {
            justify-content: flex-start;
            padding-inline: 11px;
        }

        .lk-sidebar.is-collapsed .lk-sidebar-profile {
            display: grid;
            grid-template-columns: auto minmax(0,1fr) auto;
            justify-content: initial;
            margin-inline: 10px;
            padding: 10px;
        }

        .lk-sidebar.is-collapsed .lk-nav-link,
        .lk-sidebar.is-collapsed .lk-nav-toggle {
            justify-content: initial;
            padding-inline: 11px;
        }
    }
</style>

<aside
    class="lk-sidebar"
    id="buyer-sidebar"
    data-lk-sidebar
    aria-label="Buyer navigation"
>
    <div class="lk-sidebar-head">
        <button
            type="button"
            class="lk-side-toggle"
            data-lk-sidebar-toggle
            aria-label="Collapse sidebar"
            aria-expanded="true"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>

        <a
            href="{{ route('buyer.home') }}"
            class="lk-brand"
            aria-label="LIKHAE Buyer Home"
        >
            <x-likhae-logo
                context="Buyer Center"
                class="likhae-logo--sidebar"
            />
        </a>
    </div>

    <div class="lk-sidebar-profile">
        <span class="lk-sidebar-avatar">
            <x-shared.profile-image :path="$profilePhoto" role="buyer" :alt="$buyerName" />
        </span>

        <span class="lk-sidebar-profile-copy">
            <strong>{{ $buyerName }}</strong>
            <span>LIKHAE Buyer</span>
        </span>

        <span
            class="lk-sidebar-verified"
            title="Buyer account"
        >
            ✓
        </span>
    </div>

    <nav class="lk-sidebar-scroll">

        {{-- Marketplace --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Marketplace
            </span>

            <a
                href="{{ route('buyer.home') }}"
                class="lk-nav-link {{ $active === 'home' ? 'is-active' : '' }}"
                @if($active === 'home') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['home'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Home
                </span>
            </a>

            <a
                href="{{ route('buyer.products') }}"
                class="lk-nav-link {{ $active === 'products' ? 'is-active' : '' }}"
                @if($active === 'products') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['products'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Categories
                </span>
            </a>

            <a
                href="{{ route('buyer.cart') }}"
                class="lk-nav-link {{ $active === 'cart' ? 'is-active' : '' }}"
                @if($active === 'cart') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['cart'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Cart
                </span>

                @if($cartCount > 0)
                    <span class="lk-nav-count">
                        {{ $cartCount > 99 ? '99+' : $cartCount }}
                    </span>
                @endif
            </a>
        </div>

        {{-- Orders --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Order Management
            </span>

            <button
                type="button"
                class="lk-nav-toggle {{ $active === 'orders' ? 'is-active is-open' : '' }}"
                data-nav-toggle
                aria-expanded="{{ $active === 'orders' ? 'true' : 'false' }}"
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['orders'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    My Orders
                </span>

                <span class="lk-nav-arrow">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m7 10 5 5 5-5"></path>
                    </svg>
                </span>
            </button>

            <div
                class="lk-nav-submenu {{ $active === 'orders' ? 'expanded' : '' }}"
                data-nav-submenu
                @if($active !== 'orders') hidden @endif
            >
                @foreach($orderLinks as $key => $label)
                    <a
                        href="{{ route('buyer.orders', ['status' => $key]) }}"
                        class="{{ $active === 'orders' && $orderStatus === $key ? 'is-active' : '' }}"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Customer Service --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Customer Service
            </span>

            <a
                href="{{ route('buyer.messages') }}"
                class="lk-nav-link {{ $active === 'messages' ? 'is-active' : '' }}"
                @if($active === 'messages') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['messages'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Messages
                </span>

                @if($messageCount > 0)
                    <span class="lk-nav-count">
                        {{ $messageCount > 99 ? '99+' : $messageCount }}
                    </span>
                @endif
            </a>
        </div>

        {{-- Rewards --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Benefits
            </span>

            <button
                type="button"
                class="lk-nav-toggle {{ $active === 'rewards' ? 'is-active is-open' : '' }}"
                data-nav-toggle
                aria-expanded="{{ $active === 'rewards' ? 'true' : 'false' }}"
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['rewards'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Rewards &amp; Vouchers
                </span>

                <span class="lk-nav-arrow">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m7 10 5 5 5-5"></path>
                    </svg>
                </span>
            </button>

            <div
                class="lk-nav-submenu {{ $active === 'rewards' ? 'expanded' : '' }}"
                data-nav-submenu
                @if($active !== 'rewards') hidden @endif
            >
                @foreach($rewardLinks as $key => $label)
                    <a
                        href="{{ route('buyer.rewards', ['tab' => $key]) }}"
                        class="{{ $active === 'rewards' && $rewardTab === $key ? 'is-active' : '' }}"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Account --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Account
            </span>

            <button
                type="button"
                class="lk-nav-toggle {{ $active === 'account' ? 'is-active is-open' : '' }}"
                data-nav-toggle
                aria-expanded="{{ $active === 'account' ? 'true' : 'false' }}"
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['account'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Account Management
                </span>

                <span class="lk-nav-arrow">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m7 10 5 5 5-5"></path>
                    </svg>
                </span>
            </button>

            <div
                class="lk-nav-submenu {{ $active === 'account' ? 'expanded' : '' }}"
                data-nav-submenu
                @if($active !== 'account') hidden @endif
            >
                @foreach($accountLinks as $key => $label)
                    <a
                        href="{{ route('buyer.account', ['tab' => $key]) }}"
                        class="{{ $active === 'account' && $accountTab === $key ? 'is-active' : '' }}"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

    </nav>

    <div class="lk-sidebar-foot">

        <button
            id="themeToggle"
            type="button"
            class="lk-nav-link"
            data-title="Appearance"
        >
            <span class="lk-theme-left">
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"></path>
                    </svg>
                </span>

                <span class="lk-theme-copy">
                    <strong>Appearance</strong>
                    <span>Light / Dark Mode</span>
                </span>
            </span>

            <span id="themeToggleBadge">
                OFF
            </span>
        </button>

        @if(Route::has('logout'))
            <form
                method="POST"
            action="{{ route('logout') }}"
            data-confirm-title="Log out?"
            data-confirm-message="You will be signed out of your LIKHAE account."
            data-confirm-label="Log out"
            >
                @csrf

                <button
                    type="submit"
                    class="lk-nav-link lk-logout"
                    data-title="Logout"
                >
                    <span class="lk-nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            {!! $icons['logout'] !!}
                        </svg>
                    </span>

                    <span class="lk-nav-text">
                        Logout
                    </span>
                </button>
            </form>
        @else
            <a
                href="{{ Route::has('login') ? route('login') : url('/login') }}"
                class="lk-nav-link lk-logout"
                data-title="Logout"
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['logout'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Logout
                </span>
            </a>
        @endif

    </div>
</aside>
