<!DOCTYPE html>
<html lang="en">

<head>
    <x-likhae-favicon />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap"
        rel="stylesheet"
    >

    <title>@yield('title', 'LIKHAE Rider Panel')</title>

    <script>
        (function () {
            const savedTheme =
                localStorage.getItem('likhae-theme');

            document.documentElement.classList.toggle(
                'dark',
                savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)
            );
        })();
    </script>

    @vite([
        'resources/css/logistic/app.css',
        'resources/css/shared/likhae-workspace-ai.css',
        'resources/css/shared/workspace-sidebar.css',
        'resources/js/rider/app.js',
        'resources/js/shared/likhae-workspace-ai.js'
    ])
    @stack('head')

    <style>
        :root {
            --ra-page: #FBF7F2;
            --ra-page-soft: #F6EFE7;
            --ra-card: #FFFDF9;
            --ra-card-soft: #FFF9F3;

            --ra-line: #EADCCC;
            --ra-line-strong: #DBCEC1;

            --ra-maroon: #561C17;
            --ra-maroon-hover: #642920;
            --ra-maroon-dark: #3E130F;
            --ra-active: #F3E4DE;

            --ra-text: #3B211B;
            --ra-text-deep: #1C160F;
            --ra-muted: #987865;
            --ra-muted-light: #A99386;
            --ra-brown: #6C4936;

            --ra-success: #256F4A;
            --ra-success-soft: #EAF7EF;

            --ra-shadow: 0 14px 38px rgba(74, 35, 27, .06);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body.rider-shell {
            margin: 0;
            background: var(--ra-page);
            color: var(--ra-text);
            font-family: "DM Sans", system-ui, sans-serif;
        }

        .rider-layout {
            min-height: 100vh;
            background:
                radial-gradient(circle at 80% -10%, rgba(193, 151, 113, .08), transparent 27%),
                var(--ra-page);
        }

        .rider-sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 60;
            display: flex;
            width: 248px;
            height: 100vh;
            flex-direction: column;
            overflow: hidden;
            border-right: 1px solid var(--ra-line);
            background: rgba(255, 253, 249, .97);
            box-shadow: 8px 0 26px rgba(74, 35, 27, .035);
            backdrop-filter: blur(18px);
            transition: transform .25s ease;
        }

        .rider-brand {
            display: flex;
            min-height: 62px;
            align-items: center;
            gap: 11px;
            padding: 0 18px;
            border-bottom: 1px solid var(--ra-line);
        }

        .rider-brand-mark {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border-radius: 10px;
            background: var(--ra-maroon);
            color: #FFFFFF;
            font-family: "Instrument Serif", Georgia, serif;
            font-size: 20px;
            line-height: 1;
            box-shadow: 0 8px 18px rgba(86, 28, 23, .16);
        }

        .rider-brand-copy strong {
            display: block;
            color: var(--ra-text-deep);
            font-size: 16px;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .rider-brand-copy span {
            display: block;
            margin-top: 1px;
            color: var(--ra-muted);
            font-size: 7px;
            font-weight: 900;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .rider-profile-card {
            margin: 14px 12px 6px;
            padding: 12px;
            border: 1px solid var(--ra-line);
            border-radius: 15px;
            background:
                radial-gradient(circle at 92% 10%, rgba(193,151,113,.13), transparent 32%),
                var(--ra-page-soft);
        }

        .rider-profile-top {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rider-avatar {
            display: grid;
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            place-items: center;
            border-radius: 999px;
            background: var(--ra-maroon);
            color: #FFFFFF;
            font-size: 10px;
            font-weight: 900;
            box-shadow: 0 8px 18px rgba(86, 28, 23, .13);
        }

        .rider-profile-copy {
            min-width: 0;
        }

        .rider-profile-copy strong {
            display: block;
            overflow: hidden;
            color: var(--ra-text);
            font-size: 10px;
            font-weight: 900;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .rider-profile-copy span {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 3px;
            color: var(--ra-success);
            font-size: 7px;
            font-weight: 850;
        }

        .rider-profile-copy span::before {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: var(--ra-success);
            content: "";
        }

        .rider-nav {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 12px 10px 18px;
        }

        .rider-nav-label {
            display: block;
            padding: 5px 10px 8px;
            color: var(--ra-muted-light);
            font-size: 7px;
            font-weight: 900;
            letter-spacing: .13em;
            text-transform: uppercase;
        }

        .rider-nav-list {
            display: grid;
            gap: 3px;
        }

        .rider-nav-link {
            position: relative;
            display: flex;
            min-height: 42px;
            align-items: center;
            gap: 10px;
            padding: 0 11px;
            border-radius: 11px;
            color: var(--ra-brown);
            font-size: 9px;
            font-weight: 800;
            text-decoration: none;
            transition: .14s ease;
        }

        .rider-nav-link:hover {
            background: var(--ra-page-soft);
            color: var(--ra-maroon);
        }

        .rider-nav-link.is-active {
            background: var(--ra-active);
            color: var(--ra-maroon);
        }

        .rider-nav-link.is-active::before {
            position: absolute;
            top: 9px;
            bottom: 9px;
            left: 0;
            width: 3px;
            border-radius: 0 5px 5px 0;
            background: var(--ra-maroon);
            content: "";
        }

        .rider-nav-icon {
            display: grid;
            width: 26px;
            height: 26px;
            flex: 0 0 26px;
            place-items: center;
            color: currentColor;
        }

        .rider-nav-icon svg {
            width: 17px;
            height: 17px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .rider-sidebar-footer {
            display: grid;
            flex: 0 0 auto;
            gap: 7px;
            padding: 11px 10px max(13px, env(safe-area-inset-bottom));
            border-top: 1px solid var(--ra-line);
        }

        .rider-footer-btn {
            display: flex;
            min-height: 42px;
            width: 100%;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 0 11px;
            border: 1px solid transparent;
            border-radius: 11px;
            background: transparent;
            color: var(--ra-brown);
            font: inherit;
            cursor: pointer;
            transition: .14s ease;
        }

        .rider-footer-btn:hover {
            border-color: var(--ra-line);
            background: var(--ra-page-soft);
            color: var(--ra-maroon);
        }

        .rider-footer-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rider-footer-left svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.6;
        }

        .rider-appearance-text {
            display: grid;
            text-align: left;
        }

        .rider-appearance-text strong {
            color: inherit;
            font-size: 10.5px;
            font-weight: 500;
            line-height: 1.1;
        }

        .rider-appearance-text small {
            display: block;
            margin-top: 2px;
            color: currentColor;
            opacity: 0.5;
            font-size: 8px;
            line-height: 1.1;
            font-weight: inherit;
        }

        .rider-theme-badge {
            display: inline-flex;
            min-height: 23px;
            align-items: center;
            padding: 0 7px;
            border: 1px solid var(--ra-line);
            border-radius: 999px;
            background: var(--ra-card);
            color: var(--ra-muted);
            font-size: 7px;
            font-weight: 900;
        }

        .rider-logout-form {
            margin: 0;
        }

        .rider-logout-btn {
            justify-content: flex-start;
            color: var(--ra-muted);
        }

        .rider-main {
            min-height: 100vh;
            margin-left: 248px;
        }

        .rider-topbar {
            position: sticky;
            top: 0;
            z-index: 40;
            display: flex;
            min-height: 62px;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 0 26px;
            border-bottom: 1px solid var(--ra-line);
            background: rgba(251, 247, 242, .88);
            backdrop-filter: blur(18px);
        }

        .rider-topbar-copy strong {
            display: block;
            color: var(--ra-text-deep);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: -.025em;
        }

        .rider-topbar-copy span {
            display: block;
            margin-top: 2px;
            color: var(--ra-muted);
            font-size: 8px;
        }

        .rider-topbar-status {
            display: inline-flex;
            min-height: 31px;
            align-items: center;
            gap: 7px;
            padding: 0 10px;
            border: 1px solid #CFE8DA;
            border-radius: 999px;
            background: var(--ra-success-soft);
            color: var(--ra-success);
            font-size: 7px;
            font-weight: 900;
        }

        .rider-topbar-status::before {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: currentColor;
            content: "";
        }

        .rider-content {
            width: 100%;
            padding: 24px 24px 34px;
        }

        .rider-mobile-bar {
            display: none;
        }

        .rider-mobile-menu-btn {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border: 1px solid var(--ra-line);
            border-radius: 11px;
            background: var(--ra-card);
            color: var(--ra-maroon);
            cursor: pointer;
        }

        .rider-notification-trigger {
            position: relative;
        }

        .rider-notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            display: grid;
            min-width: 16px;
            height: 16px;
            place-items: center;
            padding: 0 3px;
            border: 2px solid var(--ra-card);
            border-radius: 999px;
            background: #b42318;
            color: #fff;
            font-size: 8px;
            font-weight: 800;
            line-height: 1;
        }

        .rider-mobile-menu-btn svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
        }

        .rider-sidebar-overlay {
            visibility: hidden;
            position: fixed;
            inset: 0;
            z-index: 55;
            background: rgba(28, 22, 15, .42);
            opacity: 0;
            backdrop-filter: blur(3px);
            transition: opacity .2s ease, visibility .2s ease;
        }

        .rider-sidebar-overlay.is-visible {
            visibility: visible;
            opacity: 1;
        }

        @media (max-width: 1023px) {
            .rider-sidebar {
                height: 100dvh;
                max-height: 100dvh;
                transform: translateX(-100%);
            }

            .rider-sidebar.is-open {
                transform: translateX(0);
            }

            .rider-main {
                margin-left: 0;
            }

            .rider-topbar {
                display: none;
            }

            .rider-mobile-bar {
                position: sticky;
                top: 0;
                z-index: 45;
                display: flex;
                min-height: 62px;
                align-items: center;
                justify-content: space-between;
                padding: 0 16px;
                border-bottom: 1px solid var(--ra-line);
                background: rgba(251, 247, 242, .92);
                backdrop-filter: blur(18px);
            }

            .rider-mobile-brand {
                display: flex;
                align-items: center;
                gap: 9px;
            }

            body.workspace-sidebar-open .rider-mobile-brand {
                visibility: hidden;
            }

            .rider-mobile-brand .rider-brand-mark {
                width: 31px;
                height: 31px;
                border-radius: 9px;
                font-size: 18px;
            }

            .rider-mobile-brand strong {
                color: var(--ra-text-deep);
                font-size: 13px;
                font-weight: 900;
                letter-spacing: -.04em;
            }

            .rider-content {
                padding: 20px 14px 28px;
            }
        }

        html.dark {
            color-scheme: dark;
        }

        html.dark body.rider-shell,
        html.dark .rider-layout {
            background: #130F0E;
            color: #F5EFE8;
        }

        html.dark .rider-sidebar {
            border-color: #30231F;
            background: rgba(19, 15, 14, .98);
            box-shadow: none;
        }

        html.dark .rider-brand,
        html.dark .rider-sidebar-footer,
        html.dark .rider-topbar,
        html.dark .rider-mobile-bar {
            border-color: #30231F;
        }

        html.dark .rider-brand-copy strong,
        html.dark .rider-topbar-copy strong,
        html.dark .rider-mobile-brand strong {
            color: #F5EFE8;
        }

        html.dark .rider-brand-copy span,
        html.dark .rider-topbar-copy span,
        html.dark .rider-nav-label {
            color: #8F817A;
        }

        html.dark .rider-profile-card {
            border-color: #30231F;
            background:
                radial-gradient(circle at 92% 10%, rgba(168,69,56,.10), transparent 32%),
                #1B1513;
        }

        html.dark .rider-profile-copy strong {
            color: #F5EFE8;
        }

        html.dark .rider-nav-link,
        html.dark .rider-footer-btn {
            color: #B8AAA1;
        }

        html.dark .rider-footer-btn .rider-appearance-text small {
            color: currentColor;
            opacity: 0.5;
        }

        html.dark .rider-nav-link:hover,
        html.dark .rider-footer-btn:hover {
            border-color: #3B2E27;
            background: #211B17;
            color: #F3C6BA;
        }

        html.dark .rider-nav-link.is-active {
            background: #3A1111;
            color: #F3C6BA;
        }

        html.dark .rider-nav-link.is-active::before {
            background: #A84538;
        }

        html.dark .rider-theme-badge,
        html.dark .rider-mobile-menu-btn {
            border-color: #3B2E27;
            background: #211B17;
            color: #EBA99D;
        }

        html.dark .rider-topbar,
        html.dark .rider-mobile-bar {
            background: rgba(19, 15, 14, .88);
        }

        html.dark .rider-topbar-status {
            border-color: #28543D;
            background: #16291F;
            color: #79C79D;
        }
    </style>
</head>

@php
    $riderUser = auth()->user();
    $riderName = $riderUser?->name ?? 'Rider';
    $riderInitials = collect(explode(' ', trim($riderName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: 'R';
    $riderStatus = \Illuminate\Support\Str::headline($riderUser?->status ?? 'active');
@endphp

<body class="rider-shell">
    <div class="rider-layout">
        <x-admin.sidebar role="rider" />

        <main class="rider-main" data-sidebar-content>

            <div class="rider-mobile-bar">
                <div class="rider-mobile-brand">
                    <span class="rider-brand-mark">
                        L
                    </span>

                    <strong>
                        LIKHAE Rider
                    </strong>
                </div>

                <button
                    id="riderMobileSidebarToggle"
                    type="button"
                    class="rider-mobile-menu-btn"
                    aria-label="Open rider navigation"
                >
                    <svg viewBox="0 0 24 24">
                        <path d="M4 7h16"></path>
                        <path d="M4 12h16"></path>
                        <path d="M4 17h16"></path>
                    </svg>
                </button>
                <button type="button" class="rider-mobile-menu-btn rider-notification-trigger" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
                    <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                    @if(($workspaceNotificationCount ?? 0) > 0)<span class="rider-notification-badge">{{ $workspaceNotificationCount }}</span>@endif
                </button>
                <x-workspace-account-menu label="Rider Account" profile-route="rider.profile" compact />
            </div>

            <header class="rider-topbar">
                <div class="rider-topbar-copy">
                    <strong>
                        LIKHAE Rider
                    </strong>

                    <span>
                        Operations workspace
                    </span>
                </div>

                <div style="display:flex;align-items:center;gap:10px"><button type="button" class="rider-mobile-menu-btn rider-notification-trigger" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>@if(($workspaceNotificationCount ?? 0) > 0)<span class="rider-notification-badge">{{ $workspaceNotificationCount }}</span>@endif</button><span class="rider-topbar-status">Rider Online</span><x-workspace-account-menu label="Rider Account" profile-route="rider.profile" /></div>
            </header>

            <div class="rider-content">
                @yield('content')
            </div>

            <x-rider.live-location />

        </main>

    </div>

    <x-notification-popover />
    @include('partials.confirm-action-modal')
    @php
        $workspaceAiPage = request()->route()?->getName() ?: 'rider.dashboard';
        $workspaceAiTitle = trim($__env->yieldContent('title')) ?: 'Rider';
    @endphp
    @if(data_get(auth()->user()?->notification_preferences, 'rider_ai_assistant_enabled', true))
        <x-workspace.ai-assistant workspace="rider" :page="$workspaceAiPage" :page-title="$workspaceAiTitle" />
    @endif
    @stack('scripts')
    <x-logout-confirmation />
</body>

</html>
