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
            --lk-ed-hero-bg: url("{{ asset('images/hero-bg.png') }}");
            --lk-ed-promo-bg: url("{{ asset('images/hero-marketplace.png') }}");
        }
    </style>
    @vite(['resources/css/Buyer/buyer.css', 'resources/css/shared/modal-refresh.css', 'resources/js/Buyer/buyer.js'])
    @stack('head')
    <script>
        (function () { var theme = localStorage.getItem('likhae-theme'); if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); })();
    </script>
</head>
<body class="lk-buyer-body lk-guest-body {{ request()->routeIs('home') ? 'lk-landing-body' : '' }}">
    <div class="lk-guest-shell">
        <header class="lk-header is-guest" role="banner">
            <div class="lk-guest-header-left">
                <a href="{{ route('home') }}" class="lk-guest-brand" aria-label="LIKHAE Marketplace home">
                    <x-likhae-logo context="Marketplace" class="likhae-logo--guest" />
                </a>
                <nav class="lk-guest-nav" aria-label="Main navigation">
                    <a @class(['is-active' => request()->routeIs('home')]) @if(request()->routeIs('home')) aria-current="page" @endif href="{{ route('home') }}">Home</a>
                    <a @class(['is-active' => request()->routeIs('products', 'products.show', 'guest.home')]) @if(request()->routeIs('products', 'products.show', 'guest.home')) aria-current="page" @endif href="{{ route('products') }}">Categories</a>
                    <a href="{{ route('sell.create') }}">Seller Centre</a>
                    <a href="{{ route('logistics.home') }}">Logistics Centre</a>
                </nav>
            </div>

            <form action="{{ route('products') }}" method="GET" class="lk-search lk-guest-search" role="search" data-marketplace-search data-suggestions-url="{{ route('marketplace.search-suggestions') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search products, categories, local sellers..." aria-label="Search marketplace" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="marketplaceSearchSuggestions">
                <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
                <div id="marketplaceSearchSuggestions" class="lk-search-dropdown" data-market-search-dropdown hidden>
                    <p class="lk-search-dropdown__heading" data-market-search-heading></p>
                    <div class="lk-search-dropdown__options" role="listbox" aria-label="Marketplace search suggestions" data-market-search-options></div>
                    <p class="lk-search-dropdown__empty" data-market-search-empty hidden></p>
                    <button type="button" class="lk-search-dropdown__clear" data-market-search-clear hidden>Clear recent searches</button>
                </div>
            </form>

            <nav class="lk-guest-actions" aria-label="Guest actions">
                <button type="button" class="lk-icon-btn lk-guest-theme-toggle" id="themeToggle" aria-label="Toggle dark mode" title="Toggle dark mode">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></svg>
                </button>
                <a class="lk-guest-signin" href="{{ Route::has('login') ? route('login') : url('/login') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                    Sign In
                </a>
                <a class="lk-btn lk-btn-red lk-guest-shopnow" href="{{ Route::has('register') ? route('register') : url('/register') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l1 13H5z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
                    Shop Now
                </a>
            </nav>
        </header>
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
    @include('partials.confirm-action-modal')
    @include('partials.darkmode')
    @stack('scripts')
</body>
</html>
