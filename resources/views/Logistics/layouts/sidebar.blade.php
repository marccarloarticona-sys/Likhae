@php
    $icons = [
        'dashboard' => '
            <rect x="3" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/>
        ',
        'parcel' => '
            <path d="M21 8 12 3 3 8l9 5 9-5Z"/>
            <path d="M3 8v8l9 5 9-5V8"/>
            <path d="M12 13v8"/>
        ',
        'plus' => '
            <path d="M12 5v14"/>
            <path d="M5 12h14"/>
        ',
        'sort' => '
            <path d="M8 3v18"/>
            <path d="m4 7 4-4 4 4"/>
            <path d="M16 21V3"/>
            <path d="m12 17 4 4 4-4"/>
        ',
        'assignment' => '
            <circle cx="8" cy="7" r="3"/>
            <path d="M3 19c0-3 2-5 5-5"/>
            <path d="M13 13h8"/>
            <path d="m18 9 4 4-4 4"/>
        ',
        'tracking' => '
            <circle cx="12" cy="12" r="8"/>
            <circle cx="12" cy="12" r="3"/>
        ',
        'riders' => '
            <circle cx="9" cy="7" r="3"/>
            <path d="M3 20c0-4 2.5-6 6-6s6 2 6 6"/>
            <path d="M17 11h4"/>
            <path d="M19 9v4"/>
        ',
        'application' => '
            <path d="M6 3h9l4 4v14H6z"/>
            <path d="M14 3v5h5"/>
            <path d="m9 14 2 2 4-4"/>
        ',
        'map' => '
            <path d="M12 21s7-5 7-11a7 7 0 1 0-14 0c0 6 7 11 7 11Z"/>
            <circle cx="12" cy="10" r="2"/>
        ',
        'message' => '
            <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/>
        ',
        'reports' => '
            <path d="M5 20V10"/>
            <path d="M12 20V4"/>
            <path d="M19 20v-7"/>
        ',
        'profile' => '
            <circle cx="12" cy="8" r="4"/>
            <path d="M4 21c0-5 3-8 8-8s8 3 8 8"/>
        ',
        'logout' => '
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <path d="m16 17 5-5-5-5"/>
            <path d="M21 12H9"/>
        ',
        'moon' => '
            <path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/>
        ',
        'sun' => '
            <circle cx="12" cy="12" r="4"/>
            <path d="M12 2v2"/>
            <path d="M12 20v2"/>
            <path d="M4.93 4.93l1.41 1.41"/>
            <path d="M17.66 17.66l1.41 1.41"/>
            <path d="M2 12h2"/>
            <path d="M20 12h2"/>
            <path d="M6.34 17.66l-1.41 1.41"/>
            <path d="M19.07 4.93l-1.41 1.41"/>
        ',
        'close' => '
            <path d="M6 6l12 12"/>
            <path d="M18 6 6 18"/>
        ',
    ];

    $navGroups = [
        [
            'label' => 'Operations',
            'items' => [
                [
                    'label' => 'All Parcels',
                    'href' => route('logistics.parcels'),
                    'active' => ['logistics.parcels', 'logistics.parcels.show'],
                    'icon' => 'parcel',
                ],
                [
                    'label' => 'Pickup Requests',
                    'href' => route('logistics.pickups'),
                    'active' => ['logistics.pickups', 'logistics.pickups.*'],
                    'icon' => 'truck',
                ],
                [
                    'label' => 'Receive Parcel',
                    'href' => route('logistics.parcels.receive'),
                    'active' => ['logistics.parcels.receive'],
                    'icon' => 'plus',
                ],
                [
                    'label' => 'Parcel Sorting',
                    'href' => route('logistics.sorting'),
                    'active' => ['logistics.sorting', 'logistics.sorting.*'],
                    'icon' => 'sort',
                ],
                [
                    'label' => 'Rider Assignment',
                    'href' => route('logistics.dispatch'),
                    'active' => ['logistics.dispatch'],
                    'icon' => 'assignment',
                ],
                [
                    'label' => 'Parcel Tracking',
                    'href' => route('logistics.parcels.tracking'),
                    'active' => ['logistics.parcels.tracking'],
                    'icon' => 'tracking',
                ],
            ],
        ],
        [
            'label' => 'Delivery Management',
            'items' => [
                [
                    'label' => 'Riders',
                    'href' => route('logistics.riders'),
                    'active' => ['logistics.riders', 'logistics.riders.show'],
                    'icon' => 'riders',
                ],
                [
                    'label' => 'Delivery Areas',
                    'href' => route('logistics.delivery-areas'),
                    'active' => ['logistics.delivery-areas', 'logistics.delivery-areas.*'],
                    'icon' => 'map',
                ],
            ],
        ],
        [
            'label' => 'Communication',
            'items' => [
                [
                    'label' => 'Messages',
                    'href' => route('logistics.messages'),
                    'active' => ['logistics.messages', 'logistics.messages.*'],
                    'icon' => 'message',
                    'dot' => true,
                ],
            ],
        ],
        [
            'label' => 'Analytics',
            'items' => [
                [
                    'label' => 'Reports',
                    'href' => route('logistics.reports'),
                    'active' => ['logistics.reports', 'logistics.reports.*'],
                    'icon' => 'reports',
                ],
            ],
        ],
    ];

    $isActiveRoute = function (array $patterns) {
        return collect($patterns)->contains(fn ($pattern) => request()->routeIs($pattern));
    };

    $dashboardActive = request()->routeIs('logistics.dashboard');
@endphp

@once
<style>
    :root {
        --logi-bg: #FBF7F2;
        --logi-bg-soft: #F6EFE7;
        --logi-card: #FFFDF9;
        --logi-border: #EADCCC;

        --logi-maroon: #561C17;
        --logi-maroon-2: #642920;
        --logi-maroon-dark: #3E130F;

        --logi-text: #3B211B;
        --logi-muted: #CBB7AA;
        --logi-muted-strong: #F3D8CC;

        --logi-tan: #C19771;
        --logi-success: #77C896;
        --logi-danger: #F2A49A;
    }

    #logisticsSidebar,
    .logi-sidebar {
        width: 252px !important;
        height: 100vh;
        overflow: hidden;
        background:
            radial-gradient(circle at 12% 5%, rgba(193, 151, 113, 0.18), transparent 26%),
            radial-gradient(circle at 100% 30%, rgba(255, 253, 249, 0.07), transparent 34%),
            linear-gradient(180deg, var(--logi-maroon) 0%, var(--logi-maroon-2) 46%, var(--logi-maroon-dark) 100%) !important;
        color: #FFFFFF !important;
        border-right: 1px solid rgba(255, 255, 255, 0.10) !important;
        box-shadow: 12px 0 36px rgba(86, 28, 23, 0.14) !important;
    }

    .logi-brand {
        display: flex;
        min-height: 78px;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 0 18px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.10);
    }

    .logi-brand-link {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 12px;
        color: #FFFFFF;
        text-decoration: none;
    }

    .logi-logo-mark {
        display: grid;
        width: 40px;
        height: 40px;
        flex: 0 0 40px;
        place-items: center;
        border-radius: 14px;
        background: #FFFDF9;
        color: var(--logi-maroon);
        font-size: 17px;
        font-weight: 950;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.16);
    }

    .logi-brand-text strong {
        display: block;
        color: #FFFFFF;
        font-size: 15px;
        font-weight: 950;
        line-height: 1;
        letter-spacing: -0.04em;
    }

    .logi-brand-text small {
        display: block;
        margin-top: 5px;
        color: rgba(255, 255, 255, 0.50);
        font-size: 7px;
        font-weight: 900;
        letter-spacing: 0.18em;
        text-transform: uppercase;
    }

    .logi-mobile-close {
        display: grid;
        width: 34px;
        height: 34px;
        place-items: center;
        border: 1px solid rgba(255, 255, 255, 0.10);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.06);
        color: rgba(255, 255, 255, 0.72);
        cursor: pointer;
        transition: 160ms ease;
    }

    .logi-mobile-close:hover {
        background: rgba(255, 255, 255, 0.12);
        color: #FFFFFF;
    }

    .logi-mobile-close svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .logi-user-wrap {
        padding: 16px;
    }

    .logi-user-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 18px;
        background:
            radial-gradient(circle at 92% 10%, rgba(255, 253, 249, 0.10), transparent 30%),
            rgba(255, 255, 255, 0.065);
    }

    .logi-user-avatar {
        display: grid;
        width: 40px;
        height: 40px;
        flex: 0 0 40px;
        place-items: center;
        border-radius: 14px;
        background: #F3E4DE;
        color: var(--logi-maroon);
        font-size: 10px;
        font-weight: 950;
    }

    .logi-user-copy {
        min-width: 0;
        flex: 1;
    }

    .logi-user-copy strong {
        display: block;
        overflow: hidden;
        color: #FFFFFF;
        font-size: 11px;
        font-weight: 900;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .logi-user-copy span {
        display: block;
        margin-top: 4px;
        color: rgba(255, 255, 255, 0.46);
        font-size: 8px;
        font-weight: 700;
    }

    .logi-online-dot {
        width: 9px;
        height: 9px;
        flex: 0 0 9px;
        border-radius: 999px;
        background: var(--logi-success);
        box-shadow: 0 0 0 4px rgba(119, 200, 150, 0.14);
    }

    .logi-nav {
        display: flex;
        min-height: 0;
        flex: 1;
        flex-direction: column;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding: 0 12px 18px;
    }

    .logi-nav-group {
        margin-top: 22px;
    }

    .logi-nav-title {
        margin: 0 0 8px;
        padding: 0 10px;
        color: rgba(255, 255, 255, 0.35);
        font-size: 7px;
        font-weight: 950;
        letter-spacing: 0.20em;
        text-transform: uppercase;
    }

    .logi-nav-list {
        display: grid;
        gap: 5px;
    }

    .logi-nav-link {
        position: relative;
        display: flex;
        min-height: 42px;
        align-items: center;
        gap: 11px;
        padding: 0 10px;
        border: 1px solid transparent;
        border-radius: 14px;
        color: rgba(255, 255, 255, 0.68);
        font-size: 10px;
        font-weight: 850;
        line-height: 1;
        text-decoration: none;
        transition: 160ms ease;
    }

    .logi-nav-link:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #FFFFFF;
    }

    .logi-nav-link.is-active {
        background: #FFFDF9;
        border-color: rgba(255, 255, 255, 0.24);
        color: var(--logi-maroon);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.14);
    }

    .logi-nav-link.is-active::before {
        position: absolute;
        top: 10px;
        bottom: 10px;
        left: -12px;
        width: 4px;
        border-radius: 0 999px 999px 0;
        background: #F3D8CC;
        content: "";
    }

    .logi-nav-icon {
        display: grid;
        width: 30px;
        height: 30px;
        flex: 0 0 30px;
        place-items: center;
        border-radius: 11px;
        background: rgba(255, 255, 255, 0.075);
        color: currentColor;
    }

    .logi-nav-link.is-active .logi-nav-icon {
        background: #F3E4DE;
        color: var(--logi-maroon);
    }

    .logi-nav-icon svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.65;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .logi-nav-label {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .logi-badge {
        display: grid;
        min-width: 24px;
        height: 22px;
        place-items: center;
        padding: 0 7px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.11);
        color: rgba(255, 255, 255, 0.82);
        font-size: 8px;
        font-weight: 950;
    }

    .logi-nav-link.is-active .logi-badge {
        background: #F3E4DE;
        color: var(--logi-maroon);
    }

    .logi-badge.is-danger {
        background: #F3D8CC;
        color: var(--logi-maroon);
    }

    .logi-dot-alert {
        width: 8px;
        height: 8px;
        flex: 0 0 8px;
        border-radius: 999px;
        background: #F2A49A;
        box-shadow: 0 0 0 4px rgba(242, 164, 154, 0.13);
    }

    .logi-spacer {
        min-height: 24px;
        flex: 1;
    }

    .logi-footer-group {
        margin-top: 0;
        padding-top: 18px;
        border-top: 1px solid rgba(255, 255, 255, 0.10);
    }

    .logi-sidebar-foot {
        flex: 0 0 auto;
        padding: 0 12px max(18px, env(safe-area-inset-bottom));
    }

    @media (max-width: 1023px) {
        #logisticsSidebar,
        .logi-sidebar {
            height: 100dvh;
            max-height: 100dvh;
        }
    }

    .logi-theme-toggle {
        display: flex;
        width: 100%;
        min-height: 62px;
        align-items: center;
        gap: 12px;
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 18px;
        background:
            radial-gradient(circle at 92% 10%, rgba(255, 253, 249, 0.10), transparent 30%),
            rgba(255, 255, 255, 0.065);
        color: #FFFFFF;
        text-align: left;
        cursor: pointer;
        transition: 160ms ease;
    }

    .logi-theme-toggle:hover {
        background: rgba(255, 255, 255, 0.10);
    }

    .logi-theme-icon {
        display: grid;
        width: 40px;
        height: 40px;
        flex: 0 0 40px;
        place-items: center;
        border-radius: 14px;
        background: #FFFDF9;
        color: var(--logi-maroon);
    }

    .logi-theme-icon svg {
        width: 17px;
        height: 17px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.7;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .logi-theme-copy {
        min-width: 0;
        flex: 1;
    }

    .logi-theme-copy strong {
        display: block;
        color: #FFFFFF;
        font-size: 11px;
        font-weight: 900;
    }

    .logi-theme-copy small {
        display: block;
        margin-top: 3px;
        color: rgba(255, 255, 255, 0.48);
        font-size: 9px;
        font-weight: 700;
    }

    .logi-theme-state {
        display: inline-flex;
        min-height: 24px;
        align-items: center;
        padding: 0 9px;
        border-radius: 999px;
        background: #F3E4DE;
        color: var(--logi-maroon);
        font-size: 8px;
        font-weight: 950;
        letter-spacing: 0.08em;
    }

    .logi-profile-area {
        display: grid;
        gap: 5px;
        margin-top: 14px;
    }

    .logi-logout {
        width: 100%;
        cursor: pointer;
        text-align: left;
    }

    .logi-logout:hover {
        background: rgba(180, 35, 24, 0.18);
        color: #FFDAD6;
    }

    #themeSunIcon.hidden,
    #themeMoonIcon.hidden {
        display: none;
    }

    html.dark #logisticsSidebar,
    html.dark .logi-sidebar {
        background:
            radial-gradient(circle at 12% 5%, rgba(193, 151, 113, 0.12), transparent 26%),
            radial-gradient(circle at 100% 30%, rgba(255, 253, 249, 0.045), transparent 34%),
            linear-gradient(180deg, #321614 0%, #21100F 100%) !important;
    }

    html.dark .logi-nav-link.is-active {
        background: #2D1414;
        border-color: #60463A;
        color: #EBA99D;
    }

    html.dark .logi-nav-link.is-active .logi-nav-icon,
    html.dark .logi-nav-link.is-active .logi-badge,
    html.dark .logi-theme-icon {
        background: #1E1A17;
        color: #EBA99D;
    }

    @media (min-width: 1024px) {
        .logi-mobile-close {
            display: none;
        }
    }
</style>
@endonce

<aside
    id="logisticsSidebar"
    class="logi-sidebar fixed inset-y-0 left-0 z-50 flex -translate-x-full flex-col transition-transform duration-300 ease-out lg:translate-x-0"
>
    <div class="logi-brand">
        <a href="{{ route('logistics.dashboard') }}" class="logi-brand-link">
            <span class="logi-logo-mark">
                L
            </span>

            <span class="logi-brand-text">
                <strong>LIKHAE</strong>
                <small>Logistics Center</small>
            </span>
        </a>

        <button
            type="button"
            id="mobileSidebarClose"
            class="logi-mobile-close"
            aria-label="Close navigation"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                {!! $icons['close'] !!}
            </svg>
        </button>
    </div>

    <nav class="logi-nav" aria-label="Logistics navigation">
        <a
            href="{{ route('logistics.dashboard') }}"
            class="logi-nav-link {{ $dashboardActive ? 'is-active' : '' }}"
        >
            <span class="logi-nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    {!! $icons['dashboard'] !!}
                </svg>
            </span>

            <span class="logi-nav-label">
                Dashboard
            </span>
        </a>

        @foreach ($navGroups as $group)
            <div class="logi-nav-group">
                <p class="logi-nav-title">
                    {{ $group['label'] }}
                </p>

                <div class="logi-nav-list">
                    @foreach ($group['items'] as $item)
                        @php
                            $active = $isActiveRoute($item['active']);
                            $badgeTone = data_get($item, 'badgeTone') === 'danger' ? 'is-danger' : '';
                        @endphp

                        <a
                            href="{{ $item['href'] }}"
                            class="logi-nav-link {{ $active ? 'is-active' : '' }}"
                        >
                            <span class="logi-nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    {!! $icons[$item['icon']] ?? $icons['parcel'] !!}
                                </svg>
                            </span>

                            <span class="logi-nav-label">
                                {{ $item['label'] }}
                            </span>

                            @if (!empty($item['badge']))
                                <span class="logi-badge {{ $badgeTone }}">
                                    {{ $item['badge'] }}
                                </span>
                            @endif

                            @if (!empty($item['dot']))
                                <span class="logi-dot-alert" aria-label="Unread messages"></span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

    </nav>

    <div class="logi-sidebar-foot">
        <div class="logi-footer-group">
            <p class="logi-nav-title">
                Appearance
            </p>

            <button
                type="button"
                id="logisticsThemeToggle"
                class="logi-theme-toggle"
                aria-label="Toggle light and dark theme"
                aria-pressed="false"
            >
                <span class="logi-theme-icon" aria-hidden="true">
                    <svg id="themeMoonIcon" viewBox="0 0 24 24">
                        {!! $icons['moon'] !!}
                    </svg>

                    <svg id="themeSunIcon" viewBox="0 0 24 24" class="hidden">
                        {!! $icons['sun'] !!}
                    </svg>
                </span>

                <span class="logi-theme-copy">
                    <strong id="themeToggleTitle">
                        Dark Mode
                    </strong>

                    <small id="themeToggleDescription">
                        Switch to dark theme
                    </small>
                </span>

                <span id="themeToggleTrack" class="logi-theme-state">
                    OFF
                </span>
            </button>
        </div>

        <div class="logi-profile-area">
            <a
                href="{{ route('logistics.profile') }}"
                class="logi-nav-link {{ request()->routeIs('logistics.profile', 'logistics.profile.*') ? 'is-active' : '' }}"
            >
                <span class="logi-nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        {!! $icons['profile'] !!}
                    </svg>
                </span>

                <span class="logi-nav-label">
                    My Profile
                </span>
            </a>

            <form method="POST" action="{{ route('logout') }}" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
                @csrf

                <button type="submit" class="logi-nav-link logi-logout">
                    <span class="logi-nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            {!! $icons['logout'] !!}
                        </svg>
                    </span>

                    <span class="logi-nav-label">
                        Logout
                    </span>
                </button>
            </form>
        </div>
    </div>
</aside>
