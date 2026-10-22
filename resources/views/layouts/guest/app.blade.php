@php $pageTitle = trim($__env->yieldContent('title')) ?: 'Marketplace'; @endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} &middot; LIKHAE Marketplace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,700;0,800;1,700&family=Dancing+Script:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --lk-ed-hero-bg: url("{{ asset('images/hero-bg.webp') }}");
            --lk-ed-promo-bg: url("{{ asset('images/hero-marketplace.webp') }}");
        }
    </style>
    @if (request()->routeIs('home'))
        <link rel="preload" as="image" href="{{ asset('images/Warm Mediterranean Still Life with Terracotta Accents.webp') }}" type="image/webp" fetchpriority="high">
    @endif
    @vite(['resources/css/Buyer/buyer.css', 'resources/css/shared/modal-refresh.css', 'resources/js/Buyer/buyer.js'])
    @stack('head')
    <script>
        (function () { var theme = localStorage.getItem('likhae-theme'); if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); })();
    </script>
</head>
<body class="lk-buyer-body lk-guest-body {{ request()->routeIs('home') ? 'lk-landing-body' : '' }}">
    <div class="lk-guest-shell">
        @include('components.guest.header')
        <main class="lk-main lk-guest-main" id="main-content">@yield('content')</main>
        <x-buyer.footer :guest="true" />
    </div>
    <dialog class="lk-auth-dialog" data-auth-dialog>
        <form method="dialog" class="lk-auth-dialog-card">
            <button class="lk-auth-dialog-close" value="cancel" aria-label="Close">&times;</button>
            <span class="lk-auth-dialog-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg></span>
            <h2>Sign in to continue</h2>
            <p data-auth-message>Create a LIKHAE Buyer account or sign in to use this marketplace action.</p>
            <div class="lk-auth-dialog-actions">
                <a href="{{ Route::has('login') ? route('login') : url('/login') }}" class="lk-btn lk-btn-light">Sign In</a>
                <a href="{{ Route::has('register') ? route('register') : url('/register') }}" class="lk-btn lk-btn-red">Create Account</a>
            </div>
        </form>
    </dialog>
    <x-shared.feedback-region workspace="guest" />
    @include('components.shared.confirm-action-modal')
    @include('components.shared.darkmode')
    @stack('scripts')
</body>
</html>
