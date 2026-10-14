<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-likhae-favicon />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · LIKHAE Admin Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/admin/admin.css', 'resources/css/shared/workspace-sidebar.css', 'resources/js/admin/admin.js'])
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

    <div class="ad-toast-region" data-toast-region aria-live="polite" aria-atomic="true"></div>
    <x-notification-popover />
    @include('partials.confirm-action-modal')

    <dialog class="ad-dialog" data-confirm-dialog>
        <form method="dialog" class="ad-dialog-card">
            <div class="ad-dialog-icon" data-dialog-icon>!</div>
            <div>
                <h2 data-dialog-title>Confirm action</h2>
                <p data-dialog-message>Please review this action before continuing.</p>
            </div>
            <label class="ad-field" data-dialog-reason-wrap hidden>
                <span>Reason</span>
                <textarea rows="3" data-dialog-reason placeholder="Add a clear reason for the audit log"></textarea>
            </label>
            <div class="ad-dialog-actions">
                <button class="ad-btn ad-btn-secondary" value="cancel">Cancel</button>
                <button class="ad-btn ad-btn-primary" value="confirm" data-dialog-confirm>Confirm</button>
            </div>
        </form>
    </dialog>

    @include('partials.darkmode')
    @stack('scripts')
</body>
</html>
