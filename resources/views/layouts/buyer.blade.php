@php
    $pageTitle = html_entity_decode(
        trim($__env->yieldContent('title')) ?: 'Buyer',
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $activePage = trim($__env->yieldContent('active')) ?: 'home';
    $globalErrorMessage = session('upload_error') ?: session('buyer_error') ?: session('error');
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-likhae-favicon />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} &middot; LIKHAE Buyer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/Buyer/buyer.css', 'resources/css/Buyer/likhae-buyer-ai.css', 'resources/css/shared/workspace-sidebar.css', 'resources/css/shared/mapbox.css', 'resources/js/Buyer/buyer.js', 'resources/js/Buyer/realtime.js', 'resources/js/Buyer/likhae-buyer-ai.js', 'resources/js/shared/mapbox.js', 'resources/js/shared/address-pin.js'])
    @stack('head')
    <script>
        (function(){var t=localStorage.getItem('likhae-theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}})();
    </script>
</head>
<body class="lk-buyer-body">
    <div class="lk-buyer-app" data-lk-buyer-app data-buyer-user-id="{{ auth()->id() }}">
        <x-admin.sidebar role="buyer" :active="$activePage" />
        <div class="lk-shell" data-sidebar-content>
            <x-buyer.header :title="$pageTitle" />
            <main class="lk-main" id="main-content">@yield('content')</main>
            <x-buyer.footer />
        </div>
    </div>
    <div id="lkBuyerToast" class="lk-toast" aria-live="polite" aria-atomic="true"></div>
    <x-notification-popover />
    @if(data_get(auth()->user()?->notification_preferences, 'ai_assistant_enabled', true))
        <x-buyer.ai-assistant :page="$activePage" :page-title="$pageTitle" />
    @endif
    <style>
        .lk-global-error-backdrop{position:fixed;inset:0;z-index:500;display:grid;place-items:center;padding:18px;background:rgba(35,20,17,.58);backdrop-filter:blur(3px)}.lk-global-error-backdrop[hidden]{display:none}.lk-global-error-card{width:min(100%,460px);border:1px solid #eadbce;border-radius:20px;background:#fffdf9;padding:24px;box-shadow:0 24px 80px rgba(35,20,17,.25);color:#321d17}.lk-global-error-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:#fee2e2;color:#b42318;font-size:23px;font-weight:800}.lk-global-error-card h2{margin:14px 0 6px;font-size:20px;font-weight:800}.lk-global-error-card p{margin:0;color:#705c54;font-size:13px;line-height:1.55}.lk-global-error-actions{display:flex;justify-content:flex-end;margin-top:20px}.lk-global-error-close{border:0;border-radius:10px;background:#943b30;color:#fff;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer}.dark .lk-global-error-card{border-color:#4b3831;background:#241b18;color:#f7eee9}.dark .lk-global-error-card p{color:#c7b5ac}.dark .lk-global-error-icon{background:#4a2923;color:#ffb4a8}
    </style>
    <div class="lk-global-error-backdrop" data-global-error-modal @if(!$globalErrorMessage) hidden @endif role="alertdialog" aria-modal="true" aria-labelledby="globalErrorTitle" aria-describedby="globalErrorMessage">
        <div class="lk-global-error-card"><div class="lk-global-error-icon" aria-hidden="true">!</div><h2 id="globalErrorTitle">Upload could not be completed</h2><p id="globalErrorMessage" data-global-error-message>{{ $globalErrorMessage }}</p><div class="lk-global-error-actions"><button type="button" class="lk-global-error-close" data-global-error-close>Close</button></div></div>
    </div>
    <script>
        (function(){const modal=document.querySelector('[data-global-error-modal]');const message=modal?.querySelector('[data-global-error-message]');const close=()=>{if(modal)modal.hidden=true};window.likhaeShowError=function(text){if(!modal)return;if(message)message.textContent=text;modal.hidden=false;modal.querySelector('[data-global-error-close]')?.focus()};modal?.querySelector('[data-global-error-close]')?.addEventListener('click',close);modal?.addEventListener('click',event=>{if(event.target===modal)close()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal?.hidden)close()})})();
    </script>
    @include('partials.darkmode')
    @stack('scripts')
</body>
</html>
