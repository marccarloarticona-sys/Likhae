@php
    $pageTitle = trim($__env->yieldContent('title')) ?: 'Seller Center';
    $activePage = trim($__env->yieldContent('active')) ?: 'dashboard';
    $pageSubtitle = trim($__env->yieldContent('subtitle')) ?: 'Manage your store operations and performance.';
    $sellerThemePreference = data_get(auth()->user()?->notification_preferences, 'theme', 'system');
    $sellerAiEnabled = (bool) data_get(auth()->user()?->notification_preferences, 'seller_ai_assistant_enabled', true);
@endphp
<!doctype html>
<html lang="en">
<head>
    <x-likhae-favicon />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} · LIKHAE Seller Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/seller/seller.css', 'resources/css/shared/status-guidance.css', 'resources/css/shared/modal-refresh.css', 'resources/css/shared/workspace-sidebar.css', 'resources/js/seller/seller.js'])
    @unless(request()->routeIs('seller.messages'))
        @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/mapbox.js', 'resources/js/shared/address-pin.js'])
    @endunless
    @if($sellerAiEnabled) @vite(['resources/css/shared/likhae-workspace-ai.css', 'resources/js/shared/likhae-workspace-ai.js']) @endif
    @stack('head')
    <script>
        (function(){var t=@json($sellerThemePreference);if(!['light','dark','system'].includes(t))t='system';try{if(t==='system')localStorage.removeItem('likhae-theme');else localStorage.setItem('likhae-theme',t)}catch(_){}document.documentElement.classList.toggle('dark',t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches))})();
    </script></head>
<body class="sl-body">
    <x-admin.sidebar role="seller" :active="$activePage" />

    <div class="sl-shell" data-sidebar-content>
        @include('components.seller.header', [
            'title' => $pageTitle,
            'subtitle' => $pageSubtitle,
        ])

        <main class="sl-main" id="mainContent">
            @yield('content')
        </main>

        @include('components.seller.footer')
    </div>

    <x-shared.feedback-region workspace="seller" />
    <x-notification-popover />
    @include('partials.confirm-action-modal')
    @if($sellerAiEnabled)
        <x-workspace.ai-assistant workspace="seller" :page="$activePage" :page-title="$pageTitle" />
    @endif

    @include('partials.darkmode')
    @stack('scripts')
</body>
</html>
