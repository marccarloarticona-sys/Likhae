@props(['title' => 'Buyer', 'guest' => false])

@php
    $user = auth()->user();
    $demoUser = session('demo_user');
    $buyerName = trim(collect([
        data_get($user, 'first_name'),
        data_get($user, 'last_name'),
    ])->filter()->implode(' '));
    $buyerName = $buyerName
        ?: data_get($demoUser, 'name')
        ?: (data_get($demoUser, 'role') === 'buyer' || !$guest ? 'Buyer Account' : 'Guest Shopper');
    $initials = collect(explode(' ', trim($buyerName)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'BA';
    $searchRoute = $guest ? route('home') : route('buyer.products');
    $searchValue = request()->query('q', request()->query('search', ''));
    $searchValue = is_scalar($searchValue) ? (string) $searchValue : '';
    $catalogSearchParameters = [];
    if (!$guest && Route::currentRouteName() === 'buyer.products') {
        foreach (['category', 'min_price', 'max_price', 'rating', 'location', 'sort', 'view'] as $key) {
            $value = request()->query($key);
            if (is_scalar($value) && (string) $value !== '') {
                $catalogSearchParameters[$key] = (string) $value;
            }
        }
    }
    $cartCount = (int) data_get($buyerUiCounts ?? [], 'cart', 0);
    $notificationCount = (int) data_get($buyerUiCounts ?? [], 'notifications', 0);
    $profilePhoto = data_get($user, 'profile_photo_path');
    $savedAccounts = collect(session('saved_accounts', []))
        ->filter(fn ($account) => is_array($account) && (int) ($account['id'] ?? 0) !== (int) data_get($user, 'id'))
        ->take(4)
        ->values();
@endphp

<header class="lk-header {{ $guest ? 'is-guest' : '' }}">
    @unless($guest)
        <button type="button" class="lk-mobile-menu" data-lk-mobile-menu aria-label="Open sidebar" aria-controls="buyer-sidebar" aria-expanded="false">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    @endunless
    @if($guest)
        <a href="{{ route('home') }}" class="lk-guest-brand" aria-label="LIKHAE Marketplace home">
            <x-likhae-logo context="Marketplace" class="likhae-logo--guest" />
        </a>
    @else
        <div class="lk-header-title"><strong>{{ preg_replace('/\s+-\s+LIKHAE$/', '', $title) }}</strong><span>Shop smart with LIKHAE</span></div>
    @endif
    <form action="{{ $searchRoute }}" method="GET" class="lk-search" role="search" data-marketplace-search data-suggestions-url="{{ route('marketplace.search-suggestions') }}">
        @if(!$guest && Route::currentRouteName() === 'buyer.products')
            @foreach($catalogSearchParameters as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
        @endif
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="{{ $searchValue }}" placeholder="Search products, categories, local sellers..." aria-label="Search marketplace" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="marketplaceSearchSuggestions">
        <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
        <div id="marketplaceSearchSuggestions" class="lk-search-dropdown" data-market-search-dropdown hidden>
            <p class="lk-search-dropdown__heading" data-market-search-heading></p>
            <div class="lk-search-dropdown__options" role="listbox" aria-label="Marketplace search suggestions" data-market-search-options></div>
            <p class="lk-search-dropdown__empty" data-market-search-empty hidden></p>
            <button type="button" class="lk-search-dropdown__clear" data-market-search-clear hidden>Clear recent searches</button>
        </div>
    </form>
    @if($guest)
        <nav class="lk-guest-actions" aria-label="Guest actions">
            <a class="lk-btn lk-btn-light" href="{{ Route::has('login') ? route('login') : url('/login') }}">Sign In</a>
            <a class="lk-btn lk-btn-red" href="{{ Route::has('register') ? route('register') : url('/register') }}">Register</a>
        </nav>
    @else
        <div class="lk-header-actions">
            <button type="button" class="lk-icon-btn" title="Notifications" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><span class="lk-badge" data-buyer-notification-count style="{{ $notificationCount ? '' : 'display:none' }}">{{ $notificationCount }}</span></button>
            <a href="{{ route('buyer.cart') }}" class="lk-icon-btn" title="Cart" aria-label="Cart"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h15l-2 8H8z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>@if($cartCount)<span class="lk-badge">{{ $cartCount }}</span>@endif</a>
        </div>
        <div class="lk-account-menu" data-account-menu>
            <button type="button" class="lk-profile-container" data-account-menu-toggle aria-expanded="false" aria-controls="buyerAccountMenu">
                <span class="lk-profile-avatar">
                    <x-shared.profile-image :path="$profilePhoto" role="buyer" :alt="$buyerName" />
                </span><span class="lk-profile-info"><strong>{{ $buyerName }}</strong><small>Buyer Account</small></span><svg class="lk-profile-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
            </button>
            <div id="buyerAccountMenu" class="lk-account-dropdown" data-account-menu-panel hidden>
                <a href="{{ route('buyer.account') }}" class="lk-account-dropdown__current"><span class="lk-account-dropdown__avatar"><x-shared.profile-image :path="$profilePhoto" role="buyer" :alt="$buyerName" /></span><span><strong>{{ $buyerName }}</strong><small>Manage Buyer Account</small></span></a>
                <div class="lk-account-dropdown__divider"></div>
                <p class="lk-account-dropdown__label">Saved accounts</p>
                @forelse($savedAccounts as $account)
                    @php $accountInitials = collect(explode(' ', trim((string) ($account['name'] ?? $account['email']))))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'LK'; @endphp
                    <form method="POST" action="{{ route('account.switch') }}">
                        @csrf
                        <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                        <button type="submit" class="lk-account-dropdown__account"><span class="lk-account-dropdown__avatar">{{ $accountInitials }}</span><span><strong>{{ $account['name'] ?? $account['email'] }}</strong><small>{{ str($account['account_type'] ?? 'Account')->headline() }} · {{ $account['email'] }}</small></span></button>
                    </form>
                @empty
                    <p class="lk-account-dropdown__empty">Other accounts you sign in to here will appear in this list.</p>
                @endforelse
                <div class="lk-account-dropdown__divider"></div>
                <form method="POST" action="{{ route('account.switch') }}">@csrf<button type="submit" class="lk-account-dropdown__switch"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10l-3-3M17 17H7l3 3M17 7l3 3M7 17l-3-3"/></svg>Switch another account</button></form>
            </div>
        </div>
    @endif
</header>
