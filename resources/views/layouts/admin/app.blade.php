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
    @vite(['resources/css/admin/admin.css', 'resources/css/shared/modal-refresh.css', 'resources/css/shared/workspace-sidebar.css', 'resources/js/admin/admin.js'])
    @stack('head')

    <script>
        (function(){var t=localStorage.getItem('likhae-theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}})();
    </script></head>
<body class="ad-body">
    <div class="ad-app" data-admin-shell>
        <x-admin.sidebar :active="trim($__env->yieldContent('active', 'dashboard'))" />
        <button class="ad-overlay" type="button" data-admin-overlay aria-label="Close navigation"></button>

        <div class="ad-shell" data-sidebar-content>
            <div class="ad-header-host" data-admin-header-host>
                <x-admin.header
                    :title="trim($__env->yieldContent('title', 'Dashboard'))"
                    :subtitle="trim($__env->yieldContent('subtitle', 'Platform operations and marketplace health at a glance.'))"
                />
            </div>

            <main class="ad-main" id="main-content">
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
