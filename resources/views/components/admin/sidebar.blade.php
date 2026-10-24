@props([
    'active' => null,
    'notificationCount' => null,
])

@php
    $user = auth()->user();
    $demoUser = session('demo_user');

    $profileName = data_get($user, 'name')
        ?? data_get($demoUser, 'name')
        ?? 'Admin Account';

    $profileInitials = collect(explode(' ', trim($profileName)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('') ?: 'LK';

    $avatar = data_get($user, 'profile_photo_url')
        ?? data_get($user, 'profile_photo_path')
        ?? data_get($user, 'avatar_url');

    $adminUiCounts = $adminUiCounts ?? ['messages' => 0, 'notifications' => 0];
    $sidebarCounts = $sidebarCounts ?? [];
    $notificationCount = $notificationCount ?? ($sidebarCounts['notifications'] ?? ($adminUiCounts['notifications'] ?? 0));
    $workspaceName = 'Admin Center';
    $profileLabel = 'LIKHAE Administrator';
    $dashboardRoute = 'admin.dashboard';
    $dashboardLabel = 'Dashboard';

    $icons = [
        'dashboard' => '
            <rect x="3" y="3" width="7" height="7"></rect>
            <rect x="14" y="3" width="7" height="7"></rect>
            <rect x="3" y="14" width="7" height="7"></rect>
            <rect x="14" y="14" width="7" height="7"></rect>
        ',

        'registrations' => '
            <path d="M6 3h9l4 4v14H6z"></path>
            <path d="M14 3v5h5"></path>
            <path d="M9 13h6"></path>
            <path d="M9 17h4"></path>
        ',

        'users' => '
            <circle cx="9" cy="8" r="3"></circle>
            <path d="M3 20c0-4 2.5-6 6-6s6 2 6 6"></path>
            <circle cx="17" cy="9" r="2.5"></circle>
            <path d="M15 15c3.7 0 6 1.7 6 5"></path>
        ',

        'products' => '
            <path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"></path>
            <path d="m4 7.5 8 4.5 8-4.5"></path>
            <path d="M12 12v9"></path>
        ',

        'list' => '
            <path d="M8 6h13M8 12h13M8 18h13"></path>
            <path d="M3 6h.01M3 12h.01M3 18h.01"></path>
        ',

        'compliance' => '
            <path d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6z"></path>
            <path d="m9 12 2 2 4-4"></path>
        ',

        'complaints' => '
            <path d="M4 5h16v11H8l-4 4z"></path>
            <path d="M12 8v4"></path>
            <path d="M12 15h.01"></path>
        ',

        'finance' => '
            <path d="M4 7h16v12H4z"></path>
            <path d="M4 10h16"></path>
            <path d="M8 15h4"></path>
            <path d="M8 4h8"></path>
        ',

        'reports' => '
            <path d="M5 20V10"></path>
            <path d="M12 20V4"></path>
            <path d="M19 20v-7"></path>
            <path d="M3 20h18"></path>
        ',

        'messages' => '
            <path d="M4 5h16v11H8l-4 4z"></path>
            <path d="M8 9h8"></path>
            <path d="M8 13h5"></path>
        ',

        'notifications' => '
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
            <path d="M10 21h4"></path>
        ',

        'settings' => '
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1L7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V3h4v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1v4H21a1.7 1.7 0 0 0-1.6 1Z"></path>
        ',

        'account' => '
            <circle cx="12" cy="8" r="4"></circle>
            <path d="M4 21a8 8 0 0 1 16 0"></path>
        ',

        'moon' => '
            <path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"></path>
        ',

        'logout' => '
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <path d="m16 17 5-5-5-5"></path>
            <path d="M21 12H9"></path>
        ',

        'menu' => '
            <path d="M4 6h16"></path>
            <path d="M4 12h16"></path>
            <path d="M4 18h16"></path>
        ',
        'cart' => '
            <path d="M3 4h2l2.1 10h10.8l2-7H6"></path>
            <circle cx="9" cy="19" r="1.4"></circle>
            <circle cx="18" cy="19" r="1.4"></circle>
        ',
        'orders' => '
            <path d="M6 3h12l2 4v14H4V7z"></path>
            <path d="M4 7h16"></path>
            <path d="M9 11h6"></path>
            <path d="M9 15h6"></path>
        ',
        'shipment' => '
            <path d="M3 6h11v11H3z"></path>
            <path d="M14 10h4l3 3v4h-7z"></path>
            <circle cx="7" cy="19" r="2"></circle>
            <circle cx="18" cy="19" r="2"></circle>
        ',
        'pickup' => '
            <path d="M21 8 12 3 3 8l9 5 9-5Z"></path>
            <path d="M3 8v8l9 5 9-5V8"></path>
            <path d="M12 13v8"></path>
        ',
        'history' => '
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 7v5l3 3"></path>
        ',
        'riders' => '
            <circle cx="9" cy="7" r="3"></circle>
            <path d="M3 20c0-4 2.5-6 6-6s6 2 6 6"></path>
            <path d="M17 11h4"></path>
            <path d="M19 9v4"></path>
        ',
        'sorting' => '
            <path d="M8 3v18"></path>
            <path d="m4 7 4-4 4 4"></path>
            <path d="M16 21V3"></path>
            <path d="m12 17 4 4 4-4"></path>
        ',
        'tracking' => '
            <circle cx="12" cy="12" r="8"></circle>
            <circle cx="12" cy="12" r="3"></circle>
        ',
        'map' => '
            <path d="M12 21s7-5 7-11a7 7 0 1 0-14 0c0 6 7 11 7 11Z"></path>
            <circle cx="12" cy="10" r="2"></circle>
        ',
    ];

    $groups = [
        [
            'label' => 'User Management',
            'items' => [
                [
                    'key' => 'registrations',
                    'label' => 'Registrations',
                    'route' => 'admin.registrations',
                    'icon' => 'registrations',
                    'count' => $sidebarCounts['registrations'] ?? 0,
                    'active' => [
                        'admin.registrations',
                        'admin.registrations.*',
                        'admin.sellers.approvals',
                    ],
                ],
                [
                    'key' => 'users',
                    'label' => 'User Accounts',
                    'route' => 'admin.users',
                    'icon' => 'users',
                    'active' => [
                        'admin.users',
                        'admin.riders',
                    ],
                ],
            ],
        ],

        [
            'label' => 'Marketplace',
            'items' => [
                [
                    'key' => 'products',
                    'label' => 'Products',
                    'route' => 'admin.products',
                    'icon' => 'products',
                    'count' => $sidebarCounts['products'] ?? 0,
                    'active' => [
                        'admin.products',
                    ],
                ],
                [
                    'key' => 'marketing',
                    'label' => 'Platform Marketing',
                    'route' => 'admin.marketing',
                    'icon' => 'finance',
                    'active' => ['admin.marketing', 'admin.marketing.*'],
                ],
                [
                    'key' => 'categories',
                    'label' => 'Categories',
                    'route' => 'admin.categories',
                    'icon' => 'list',
                    'active' => [
                        'admin.categories',
                    ],
                ],
                [
                    'key' => 'compliance',
                    'label' => 'Seller Compliance',
                    'route' => 'admin.compliance',
                    'icon' => 'compliance',
                    'count' => $sidebarCounts['compliance'] ?? 0,
                    'active' => [
                        'admin.compliance',
                    ],
                ],
                [
                    'key' => 'complaints',
                    'label' => 'Refunds & Disputes',
                    'route' => 'admin.complaints',
                    'icon' => 'complaints',
                    'count' => $sidebarCounts['disputes'] ?? 0,
                    'active' => [
                        'admin.complaints',
                        'admin.refunds',
                    ],
                ],
            ],
        ],

        [
            'label' => 'Finance & Analytics',
            'items' => [
                [
                    'key' => 'finance',
                    'label' => 'Finance & Payouts',
                    'route' => 'admin.finance',
                    'icon' => 'finance',
                    'count' => $sidebarCounts['payout_requests'] ?? 0,
                    'active' => [
                        'admin.finance',
                        'admin.payments',
                    ],
                ],
                [
                    'key' => 'shipping-rate-requests',
                    'label' => 'Shipping Rate Requests',
                    'route' => 'admin.shipping-rate-requests',
                    'icon' => 'finance',
                    'active' => ['admin.shipping-rate-requests', 'admin.shipping-rate-requests.*'],
                ],
                [
                    'key' => 'protection-rules',
                    'label' => 'Additional Care Rules',
                    'route' => 'admin.protection-rules',
                    'icon' => 'finance',
                    'active' => ['admin.protection-rules'],
                ],
                [
                    'key' => 'reports',
                    'label' => 'Reports',
                    'route' => 'admin.reports',
                    'icon' => 'reports',
                    'active' => [
                        'admin.reports',
                    ],
                ],
            ],
        ],

        [
            'label' => 'Communication',
            'items' => [
                [
                    'key' => 'messages',
                    'label' => 'Messages',
                    'route' => 'admin.messages',
                    'icon' => 'messages',
                    'count' => $sidebarCounts['messages'] ?? 0,
                    'active' => [
                        'admin.messages',
                    ],
                ],
                [
                    'key' => 'notifications',
                    'label' => 'Notifications',
                    'route' => 'notifications.index',
                    'icon' => 'notifications',
                    'active' => [
                        'notifications.index',
                        'admin.notifications',
                    ],
                    'count' => $notificationCount,
                ],
            ],
        ],

        [
            'label' => 'System',
            'items' => [
                [
                    'key' => 'settings',
                    'label' => 'Platform Settings',
                    'route' => 'admin.settings',
                    'icon' => 'settings',
                    'active' => [
                        'admin.settings',
                    ],
                ],
                [
                    'key' => 'system-settings',
                    'label' => 'System Settings',
                    'route' => 'admin.system-settings',
                    'icon' => 'settings',
                    'active' => ['admin.system-settings'],
                ],
                [
                    'key' => 'account',
                    'label' => 'My Account',
                    'route' => 'admin.account',
                    'icon' => 'account',
                    'active' => [
                        'admin.account',
                    ],
                ],
            ],
        ],
    ];

    $routeActive = function (array $routes) {
        return collect($routes)
            ->contains(fn ($route) => request()->routeIs($route));
    };
@endphp



<button
    id="adminSidebarOverlay"
    class="lk-admin-overlay"
    type="button"
    tabindex="-1"
    aria-label="Close navigation"
    aria-hidden="true"
></button>

<aside
    id="adminSidebar"
    class="lk-admin-navigation"
    aria-label="{{ $workspaceName }} navigation"
>
    <div class="ad-sidebar-head">
        <button
            type="button"
            id="adminSidebarToggle"
            class="ad-side-toggle"
            aria-label="Toggle admin sidebar"
            aria-expanded="true"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                {!! $icons['menu'] !!}
            </svg>
        </button>

        <a
            href="{{ route($dashboardRoute) }}"
            class="ad-brand"
            aria-label="LIKHAE {{ $workspaceName }}"
        >
            <x-shared.likhae-logo
                :context="$workspaceName"
                class="likhae-logo--sidebar"
            />
        </a>
    </div>

    {{--
    <section class="ad-side-profile">
        <span class="ad-side-avatar">
            @if($avatar)
                <img
                    src="{{ $avatar }}"
                    alt="{{ $profileName }}"
                >
            @else
                {{ $profileInitials }}
            @endif
        </span>

        <span class="ad-side-profile-copy">
            <strong>
                {{ $profileName }}
            </strong>

            <small>
                {{ $profileLabel }}
            </small>
        </span>

        <span
            class="ad-side-verified"
            title="{{ $profileLabel }}"
        >
            ✓
        </span>
    </section>
    --}}

    <nav class="ad-sidebar-scroll">

        <p class="ad-nav-heading">
            Navigation
        </p>

        <a
            href="{{ route($dashboardRoute) }}"
            class="ad-nav-link
                {{ request()->routeIs($dashboardRoute) || $active === 'dashboard' ? 'is-active' : '' }}"
            @if(request()->routeIs($dashboardRoute) || $active === 'dashboard')
                aria-current="page"
            @endif
        >
            <span class="ad-nav-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    {!! $icons['dashboard'] !!}
                </svg>
            </span>

            <span class="ad-nav-text">
                {{ $dashboardLabel }}
            </span>
        </a>

        @foreach($groups as $group)
            <section class="ad-nav-group">
                <p class="ad-nav-heading">
                    {{ $group['label'] }}
                </p>

                <div class="ad-nav-list">
                    @foreach($group['items'] as $item)
                        @php
                            $matchesRoute = $routeActive($item['active']);
                            if (isset($item['query'])) {
                                $matchesRoute = $matchesRoute
                                    && request()->query($item['query']['key'], $item['query']['default']) === $item['query']['value'];
                            }
                            $isActive = $active === $item['key'] || $matchesRoute;
                        @endphp

                        <a
                            href="{{ route($item['route'], $item['parameters'] ?? []) }}"
                            class="ad-nav-link {{ $isActive ? 'is-active' : '' }}"
                            @if($isActive) aria-current="page" @endif
                        >
                            <span class="ad-nav-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    {!! $icons[$item['icon']] !!}
                                </svg>
                            </span>

                            <span class="ad-nav-text">
                                {{ $item['label'] }}
                            </span>

                            @if(($item['count'] ?? 0) > 0)
                                <span class="ad-nav-badge">
                                    {{ $item['count'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

    </nav>

    <div class="ad-sidebar-foot">

        <button
            type="button"
            id="adminThemeToggle"
            class="ad-nav-link ad-theme-toggle"
            aria-label="Toggle light and dark mode"
            aria-pressed="false"
        >
            <span class="ad-nav-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    {!! $icons['moon'] !!}
                </svg>
            </span>

            <span class="ad-theme-copy">
                <strong>
                    Appearance
                </strong>

                <small>
                    Light / Dark Mode
                </small>
            </span>

            <span
                id="adminThemeState"
                class="ad-theme-state"
            >
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
                    class="ad-nav-link ad-logout"
                >
                    <span class="ad-nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            {!! $icons['logout'] !!}
                        </svg>
                    </span>

                    <span class="ad-nav-text">
                        Logout
                    </span>
                </button>
            </form>
        @endif

    </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const root =
        document.documentElement;

    const body =
        document.body;

    const app =
        document.querySelector('[data-admin-shell]');

    const sidebar =
        document.getElementById('adminSidebar');

    const overlay =
        document.getElementById('adminSidebarOverlay');

    const sidebarToggle =
        document.getElementById('adminSidebarToggle');

    const themeToggle =
        document.getElementById('adminThemeToggle');

    const themeState =
        document.getElementById('adminThemeState');


    function isMobile() {
        return window.matchMedia('(max-width: 1024px)').matches;
    }


    function syncTheme() {
        const dark =
            root.classList.contains('dark');

        if (themeState) {
            themeState.textContent =
                dark ? 'ON' : 'OFF';
        }

        themeToggle?.setAttribute(
            'aria-pressed',
            dark ? 'true' : 'false'
        );
    }


    function restoreTheme() {
        const buyerPreference = root.dataset.buyerThemePreference;
        const savedTheme =
            localStorage.getItem('likhae-theme') || 'light';
        const theme = ['light', 'dark', 'system'].includes(buyerPreference)
            ? buyerPreference
            : savedTheme;

        root.classList.toggle(
            'dark',
            theme === 'dark'
                || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
        );

        syncTheme();
    }


    function openMobileSidebar() {
        sidebar?.classList.add('is-open');
        overlay?.classList.add('is-visible');
        app?.classList.add('is-mobile-open');
        sidebarToggle?.setAttribute('aria-expanded', 'true');
        document.querySelectorAll('[data-admin-mobile-menu]')
            .forEach((button) => button.setAttribute('aria-expanded', 'true'));

        if (overlay) {
            overlay.setAttribute(
                'aria-hidden',
                'false'
            );
        }

        body.style.overflow = 'hidden';
    }


    function closeMobileSidebar() {
        sidebar?.classList.remove('is-open');
        overlay?.classList.remove('is-visible');
        app?.classList.remove('is-mobile-open');
        sidebarToggle?.setAttribute('aria-expanded', 'false');
        document.querySelectorAll('[data-admin-mobile-menu]')
            .forEach((button) => button.setAttribute('aria-expanded', 'false'));

        if (overlay) {
            overlay.setAttribute(
                'aria-hidden',
                'true'
            );
        }

        body.style.overflow = '';
    }


    function restoreSidebarState() {
        if (!sidebar) {
            return;
        }

        const collapsed = !isMobile()
            && localStorage.getItem('likhae-admin-sidebar') === 'collapsed';

        app?.classList.toggle('is-collapsed', collapsed);
        sidebarToggle?.setAttribute(
            'aria-expanded',
            isMobile() ? 'false' : (collapsed ? 'false' : 'true')
        );
    }


    function toggleSidebar() {
        if (!sidebar) {
            return;
        }

        if (isMobile()) {
            if (sidebar.classList.contains('is-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }

            return;
        }

        const collapsed = !app?.classList.contains('is-collapsed');

        app?.classList.toggle('is-collapsed', collapsed);

        sidebarToggle?.setAttribute(
            'aria-expanded',
            collapsed ? 'false' : 'true'
        );

        localStorage.setItem(
            'likhae-admin-sidebar',
            collapsed
                ? 'collapsed'
                : 'expanded'
        );

    }


    sidebarToggle?.addEventListener(
        'click',
        toggleSidebar
    );

    sidebar?.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeMobileSidebar);
    });


    overlay?.addEventListener(
        'click',
        closeMobileSidebar
    );

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMobileSidebar();
    });

    document
        .querySelectorAll('[data-admin-sidebar-open]')
        .forEach(function (button) {
            button.addEventListener(
                'click',
                openMobileSidebar
            );
        });

    document
        .querySelectorAll('[data-admin-mobile-menu]')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                if (sidebar?.classList.contains('is-open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            });
        });


    window.addEventListener(
        'likhae:admin-sidebar-open',
        openMobileSidebar
    );


    themeToggle?.addEventListener(
        'click',
        function () {
            const dark =
                !root.classList.contains('dark');
            const theme = dark ? 'dark' : 'light';

            root.classList.toggle(
                'dark',
                dark
            );

            localStorage.setItem(
                'likhae-theme',
                dark
                    ? 'dark'
                    : 'light'
            );

            const buyerThemeUpdateUrl = root.dataset.buyerThemeUpdateUrl;
            if (buyerThemeUpdateUrl) {
                const previousTheme = root.dataset.buyerThemePreference || 'system';
                root.dataset.buyerThemePreference = theme;
                const settingsRadio = document.querySelector(`input[name="theme"][value="${theme}"]`);
                if (settingsRadio) settingsRadio.checked = true;

                fetch(buyerThemeUpdateUrl, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ theme }),
                }).then((response) => {
                    if (!response.ok) throw new Error('Theme preference could not be saved.');
                    return response.json();
                }).then((payload) => {
                    if (payload.theme !== theme) throw new Error('Theme preference could not be saved.');
                }).catch(() => {
                    root.dataset.buyerThemePreference = previousTheme;
                    const restoredTheme = previousTheme === 'system'
                        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                        : previousTheme;
                    root.classList.toggle('dark', restoredTheme === 'dark');
                    localStorage.setItem('likhae-theme', restoredTheme);
                    const restoredRadio = document.querySelector(`input[name="theme"][value="${previousTheme}"]`);
                    if (restoredRadio) restoredRadio.checked = true;
                    syncTheme();
                });
            }

            syncTheme();
        }
    );


    window.addEventListener(
        'resize',
        function () {
            closeMobileSidebar();
            restoreSidebarState();
        }
    );


    restoreTheme();
    restoreSidebarState();
});
</script>
