<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-shared.likhae-favicon />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · LIKHAE Admin Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/admin/admin.css', 'resources/css/shared/modal-refresh.css', 'resources/js/admin/admin.js'])
    <style>
        /* Keep navigation usable when a deployed view reaches the server before its Vite CSS. */
        .ad-body [data-admin-shell] > .lk-admin-navigation {
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 60;
            display: flex;
            width: var(--ad-sidebar, 252px);
            height: 100vh;
            flex-direction: column;
            overflow: hidden;
            border-right: 1px solid var(--ad-border, #eadccc);
            background: #fffdf9;
        }
        .ad-body [data-admin-shell] > .ad-shell {
            display: flex;
            min-width: 0;
            min-height: 100vh;
            margin-left: var(--ad-sidebar, 252px);
            flex-direction: column;
        }
        .ad-body [data-admin-shell] > .ad-shell > .lk-admin-topbar {
            position: sticky;
            top: 0;
            z-index: 55;
            display: grid;
            width: 100%;
            min-height: var(--ad-header, 62px);
            padding: 8px 24px;
            flex: 0 0 auto;
            grid-template-columns: minmax(230px, .85fr) minmax(280px, 480px) minmax(260px, .85fr);
            align-items: center;
            gap: 18px;
            border-bottom: 1px solid var(--ad-border, #eadccc);
            background: #fffdf9;
        }
        .ad-body [data-admin-shell] > .ad-shell > .lk-admin-main {
            box-sizing: border-box;
            width: 100%;
            max-width: 1580px;
            min-height: calc(100vh - 120px);
            margin: 0 auto;
            padding: 22px 24px 32px;
            flex: 1 0 auto;
        }
        .ad-body [data-admin-shell] > .ad-shell > .lk-admin-footer {
            display: flex;
            width: 100%;
            max-width: 1580px;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: 0 auto;
            padding: 18px 24px;
            border-top: 1px solid var(--ad-border, #eadccc);
            color: var(--ad-muted, #987865);
            font-size: 9px;
        }
        .ad-body [data-admin-shell] > .lk-admin-overlay { display: none; }
        .ad-body [data-admin-shell].is-collapsed > .lk-admin-navigation { width: var(--ad-sidebar-small, 72px); }
        .ad-body [data-admin-shell].is-collapsed > .ad-shell { margin-left: var(--ad-sidebar-small, 72px); }
        html.dark .ad-body [data-admin-shell] > .lk-admin-navigation,
        html.dark .ad-body [data-admin-shell] > .ad-shell > .lk-admin-topbar,
        html.dark .ad-body [data-admin-shell] > .ad-shell > .lk-admin-footer {
            border-color: var(--ad-border, #382c28);
            background: #161210;
        }
        @media (max-width: 1280px) {
            .ad-body [data-admin-shell] > .ad-shell > .lk-admin-topbar {
                grid-template-columns: minmax(210px, .75fr) minmax(240px, 420px) auto;
            }
        }
        @media (max-width: 1024px) {
            .ad-body [data-admin-shell] > .lk-admin-navigation,
            .ad-body [data-admin-shell].is-collapsed > .lk-admin-navigation {
                width: var(--ad-sidebar, 252px);
                height: 100dvh;
                transform: translateX(-100%);
            }
            .ad-body [data-admin-shell].is-mobile-open > .lk-admin-navigation { transform: translateX(0); }
            .ad-body [data-admin-shell] > .ad-shell,
            .ad-body [data-admin-shell].is-collapsed > .ad-shell { margin-left: 0; }
            .ad-body [data-admin-shell] > .ad-shell > .lk-admin-topbar {
                grid-template-columns: minmax(210px, 1fr) minmax(220px, 380px) auto;
            }
            .ad-body [data-admin-shell] > .lk-admin-overlay {
                position: fixed;
                inset: 0;
                z-index: 45;
                border: 0;
                background: rgba(86, 28, 23, .36);
            }
            .ad-body [data-admin-shell].is-mobile-open > .lk-admin-overlay { display: block; }
        }
        @media (max-width: 760px) {
            .ad-body [data-admin-shell] > .ad-shell > .lk-admin-topbar {
                display: flex;
                padding: 8px 14px;
            }
            .ad-body [data-admin-shell] > .ad-shell > .lk-admin-main { padding: 16px 14px 26px; }
            .ad-body [data-admin-shell] > .ad-shell > .lk-admin-footer {
                align-items: flex-start;
                flex-direction: column;
                padding-inline: 14px;
            }
        }
    </style>
    @stack('head')

    <script>
        (function(){var t=localStorage.getItem('likhae-theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}})();
    </script></head>
<body class="ad-body">
    <div class="ad-app" data-admin-shell>
        <x-admin.sidebar :active="trim($__env->yieldContent('active', 'dashboard'))" />

        <div class="ad-shell" data-sidebar-content>
            <x-admin.header
                :title="trim($__env->yieldContent('title', 'Dashboard'))"
                :subtitle="trim($__env->yieldContent('subtitle', 'Platform operations and marketplace health at a glance.'))"
            />

            <main class="lk-admin-main" id="main-content">
                @yield('content')
            </main>

            <x-admin.footer />
        </div>
    </div>

    <x-shared.feedback-region workspace="admin" />
    <x-shared.notification-popover />
    @include('components.shared.confirm-action-modal')

    @include('components.shared.darkmode')
    @stack('scripts')
</body>
</html>
