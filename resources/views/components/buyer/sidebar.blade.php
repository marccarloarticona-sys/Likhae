@props(['active' => 'home', 'cartCount' => null, 'orderCount' => null, 'messageCount' => null, 'notificationCount' => null])

@php
    $cartCount = $cartCount ?? (int) data_get($buyerUiCounts ?? [], 'cart', 0);
    $orderCount = $orderCount ?? (int) data_get($buyerUiCounts ?? [], 'orders', 0);
    $messageCount = $messageCount ?? (int) data_get($buyerUiCounts ?? [], 'messages', 0);
    $notificationCount = $notificationCount ?? (int) data_get($buyerUiCounts ?? [], 'notifications', 0);

    if (request()->routeIs('notifications.index', 'buyer.notifications')) {
        $active = 'notifications';
    } elseif (request()->routeIs('buyer.settings', 'buyer.settings.*')) {
        $active = 'settings';
    }

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
        'notifications' => '
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
            <path d="M10 21h4"></path>
        ',
        'settings' => '
            <circle cx="12" cy="12" r="3"></circle>
            <path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a7.8 7.8 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a7.8 7.8 0 0 1-1.5-.9l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.7.6a7.8 7.8 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a7.8 7.8 0 0 1 1.5.9l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z"></path>
        ',
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

    .lk-nav-link {
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

    .lk-nav-link:hover {
        background: var(--buyer-soft);
        color: var(--buyer-primary);
    }

    .lk-nav-link.is-active {
        background: var(--buyer-active);
        color: var(--buyer-primary);
        font-weight: 700;
    }

    .lk-nav-link.is-active::before {
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

    .lk-sidebar.lk-sidebar-collapsed {
        width: var(--buyer-side-collapsed);
    }

    .lk-sidebar.lk-sidebar-collapsed .lk-brand,
    .lk-sidebar.lk-sidebar-collapsed .lk-nav-section-title,
    .lk-sidebar.lk-sidebar-collapsed .lk-nav-text,
    .lk-sidebar.lk-sidebar-collapsed .lk-nav-count,
    .lk-sidebar.lk-sidebar-collapsed .lk-theme-copy,
    .lk-sidebar.lk-sidebar-collapsed #themeToggleBadge {
        display: none !important;
    }

    .lk-sidebar.lk-sidebar-collapsed .lk-sidebar-head {
        justify-content: center;
        padding-inline: 0;
    }

    .lk-sidebar.lk-sidebar-collapsed .lk-nav-link {
        justify-content: center;
        padding-inline: 0;
    }

    .lk-sidebar.lk-sidebar-collapsed #themeToggle {
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

    html.dark .lk-theme-copy span,
    html.dark .lk-nav-section-title {
        color: #8F817A;
    }

    html.dark .lk-nav-link {
        color: #B8AAA1;
    }

    html.dark .lk-nav-link:hover {
        background: #211B17;
        color: #F3C6BA;
    }

    html.dark .lk-nav-link.is-active {
        background: #3A1111;
        color: #F3C6BA;
    }

    html.dark .lk-nav-link.is-active::before {
        background: #A84538;
    }

    html.dark #themeToggleBadge {
        background: #A84538;
        color: #FFF;
    }

    /* MOBILE ------------------------------------------------------ */

    @media (max-width: 1023px) {
        .lk-sidebar,
        .lk-sidebar.lk-sidebar-collapsed {
            width: min(86vw, 300px);
            height: 100dvh;
            max-height: 100dvh;
            transform: translateX(-100%);
        }

        .lk-sidebar.is-open,
        .lk-sidebar.lk-sidebar-open {
            transform: translateX(0);
        }

        .lk-sidebar.lk-sidebar-collapsed .lk-brand,
        .lk-sidebar.lk-sidebar-collapsed .lk-nav-section-title,
        .lk-sidebar.lk-sidebar-collapsed .lk-nav-text,
        .lk-sidebar.lk-sidebar-collapsed .lk-nav-count,
        .lk-sidebar.lk-sidebar-collapsed .lk-theme-copy,
        .lk-sidebar.lk-sidebar-collapsed #themeToggleBadge {
            display: initial !important;
        }

        .lk-sidebar.lk-sidebar-collapsed .lk-sidebar-head {
            justify-content: flex-start;
            padding-inline: 11px;
        }

        .lk-sidebar.lk-sidebar-collapsed .lk-nav-link {
            justify-content: initial;
            padding-inline: 11px;
        }

        .lk-sidebar.lk-sidebar-collapsed #themeToggle {
            justify-content: space-between;
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
            <x-shared.likhae-logo
                context="Buyer Center"
                class="likhae-logo--sidebar {{ request()->routeIs('buyer.home') ? 'likhae-logo--buyer-home' : '' }}"
            />
        </a>
    </div>

    <nav class="lk-sidebar-scroll">

        {{-- Navigation --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Navigation
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
        </div>

        {{-- Marketplace --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Marketplace
            </span>

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
                Orders
            </span>

            <a
                href="{{ route('buyer.orders') }}"
                class="lk-nav-link {{ $active === 'orders' ? 'is-active' : '' }}"
                @if($active === 'orders') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['orders'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Orders
                </span>
                @if($orderCount > 0)
                    <span class="lk-nav-count">{{ $orderCount > 99 ? '99+' : $orderCount }}</span>
                @endif
            </a>
        </div>

        {{-- Communication --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Communication
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

            <a
                href="{{ route('notifications.index') }}"
                class="lk-nav-link {{ $active === 'notifications' ? 'is-active' : '' }}"
                @if($active === 'notifications') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['notifications'] !!}
                    </svg>
                </span>
                <span class="lk-nav-text">Notifications</span>
                @if($notificationCount > 0)
                    <span class="lk-nav-count">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
                @endif
            </a>
        </div>

        {{-- Account --}}
        <div class="lk-nav-section">
            <span class="lk-nav-section-title">
                Account
            </span>

            <a
                href="{{ route('buyer.rewards', ['tab' => 'vouchers']) }}"
                class="lk-nav-link {{ $active === 'rewards' ? 'is-active' : '' }}"
                @if($active === 'rewards') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['rewards'] !!}
                    </svg>
                </span>

                <span class="lk-nav-text">
                    Rewards
                </span>
            </a>
            <a
                href="{{ route('buyer.account', ['tab' => 'profile']) }}"
                class="lk-nav-link {{ $active === 'account' ? 'is-active' : '' }}"
                @if($active === 'account') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['account'] !!}
                    </svg>
                </span>
                <span class="lk-nav-text">
                    My Account
                </span>
            </a>

            <a
                href="{{ route('buyer.settings') }}"
                class="lk-nav-link {{ $active === 'settings' ? 'is-active' : '' }}"
                @if($active === 'settings') aria-current="page" @endif
            >
                <span class="lk-nav-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        {!! $icons['settings'] !!}
                    </svg>
                </span>
                <span class="lk-nav-text">
                    System Settings
                </span>
            </a>
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
