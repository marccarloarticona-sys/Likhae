@props(['title' => 'Buyer', 'guest' => false, 'active' => 'home'])

@php
    $user = auth()->user();
    $demoUser = session('demo_user');
    $buyerName = trim(collect([
        data_get($user, 'first_name'),
        data_get($user, 'last_name'),
    ])->filter()->implode(' '));
    $buyerName = $buyerName
        ?: data_get($demoUser, 'name')
        ?: (data_get($demoUser, 'role') === 'buyer' || !$guest ? __('common.labels.buyer_account') : 'Guest Shopper');
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
    $orderCount = (int) data_get($buyerUiCounts ?? [], 'orders', 0);
    $messageCount = (int) data_get($buyerUiCounts ?? [], 'messages', 0);
    $notificationCount = (int) data_get($buyerUiCounts ?? [], 'notifications', 0);
    $profilePhoto = data_get($user, 'profile_photo_path');
    $savedAccounts = collect(session('saved_accounts', []))
        ->filter(fn ($account) => is_array($account) && (int) ($account['id'] ?? 0) !== (int) data_get($user, 'id'))
        ->take(4)
        ->values();
    $activeNavigation = request()->routeIs('notifications.index', 'buyer.notifications') ? 'notifications' : $active;
    $buyerNavigation = [
        ['key' => 'home', 'label' => __('navigation.items.home'), 'url' => route('buyer.home'), 'count' => 0],
        ['key' => 'products', 'label' => __('navigation.items.categories'), 'url' => route('buyer.products'), 'count' => 0],
        ['key' => 'cart', 'label' => __('navigation.items.cart'), 'url' => route('buyer.cart'), 'count' => $cartCount],
        ['key' => 'orders', 'label' => __('navigation.items.orders'), 'url' => route('buyer.orders'), 'count' => $orderCount],
        ['key' => 'messages', 'label' => __('navigation.items.messages'), 'url' => route('buyer.messages'), 'count' => $messageCount],
        ['key' => 'account', 'label' => __('navigation.items.my_account'), 'url' => route('buyer.account', ['tab' => 'profile']), 'count' => 0],
    ];
@endphp

<header class="lk-header {{ $guest ? 'is-guest' : 'is-buyer' }}">
    @if($guest)
        <a href="{{ route('home') }}" class="lk-guest-brand" aria-label="LIKHAE Marketplace home">
            <x-shared.likhae-logo context="Marketplace" class="likhae-logo--guest" />
        </a>
        <form action="{{ $searchRoute }}" method="GET" class="lk-search" role="search" data-marketplace-search data-suggestions-url="{{ route('marketplace.search-suggestions') }}">
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
        <nav class="lk-guest-actions" aria-label="Guest actions">
            <a class="lk-btn lk-btn-light" href="{{ Route::has('login') ? route('login') : url('/login') }}">Sign In</a>
            <a class="lk-btn lk-btn-red" href="{{ Route::has('register') ? route('register') : url('/register') }}">Register</a>
        </nav>
    @else
        <a href="{{ route('buyer.home') }}" class="lk-buyer-header-brand" aria-label="LIKHAE Buyer home">
            <x-shared.likhae-logo context="Buyer" class="likhae-logo--buyer-header" />
        </a>

        <nav class="lk-buyer-nav" aria-label="Buyer navigation">
            @foreach($buyerNavigation as $item)
                <a href="{{ $item['url'] }}" class="lk-buyer-nav-link {{ $activeNavigation === $item['key'] ? 'is-active' : '' }}" @if($activeNavigation === $item['key']) aria-current="page" @endif>
                    <span>{{ $item['label'] }}</span>
                    @if($item['count'] > 0 || $item['key'] === 'notifications')
                        <span class="lk-buyer-nav-count {{ $item['key'] === 'notifications' ? 'is-notification-count' : '' }}" @if($item['key'] === 'notifications') data-buyer-notification-count @if($item['count'] < 1) style="display:none" @endif @endif>{{ $item['count'] > 99 ? '99+' : $item['count'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="lk-header-primary">
            <div class="lk-buyer-header-search" data-buyer-header-search>
                <button type="button" class="lk-icon-btn lk-buyer-search-toggle" data-buyer-search-toggle aria-label="Open search" title="Search" aria-controls="buyerHeaderSearchForm" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
                <form id="buyerHeaderSearchForm" action="{{ $searchRoute }}" method="GET" class="lk-search lk-buyer-header-search-form" role="search" data-buyer-search-form data-marketplace-search data-suggestions-url="{{ route('marketplace.search-suggestions') }}" hidden>
                @if(Route::currentRouteName() === 'buyer.products')
                    @foreach($catalogSearchParameters as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                @endif
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" value="{{ $searchValue }}" placeholder="Search products..." aria-label="Search products, categories, and local sellers" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="marketplaceSearchSuggestions">
                <button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
                <div id="marketplaceSearchSuggestions" class="lk-search-dropdown" data-market-search-dropdown hidden>
                    <p class="lk-search-dropdown__heading" data-market-search-heading></p>
                    <div class="lk-search-dropdown__options" role="listbox" aria-label="Marketplace search suggestions" data-market-search-options></div>
                    <p class="lk-search-dropdown__empty" data-market-search-empty hidden></p>
                    <button type="button" class="lk-search-dropdown__clear" data-market-search-clear hidden>Clear recent searches</button>
                </div>
                </form>
            </div>

            <div class="lk-header-actions">
                <button id="themeToggle" type="button" class="lk-icon-btn lk-buyer-theme-toggle" aria-label="Toggle light or dark mode" title="Toggle light or dark mode" aria-pressed="false">
                    <svg class="lk-theme-toggle-icon lk-theme-toggle-icon--dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.985 12.486A9 9 0 1 1 11.51 3.015a.75.75 0 0 0 9.475 9.47Z"/></svg>
                    <svg class="lk-theme-toggle-icon lk-theme-toggle-icon--light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                </button>
                <button type="button" class="lk-icon-btn lk-buyer-notification-toggle" aria-label="Open notifications" title="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                    <span class="lk-buyer-notification-count" data-buyer-notification-count @if($notificationCount < 1) style="display:none" @endif aria-hidden="true">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
                </button>
                <div class="lk-account-menu" data-account-menu>
                    <button type="button" class="lk-profile-container" data-account-menu-toggle aria-expanded="false" aria-controls="buyerAccountMenu">
                        <span class="lk-profile-avatar"><x-shared.profile-image :path="$profilePhoto" role="buyer" :alt="$buyerName" /></span>
                        <span class="lk-profile-info"><strong>{{ $buyerName }}</strong><small>{{ __('common.labels.buyer_account') }}</small></span>
                        <svg class="lk-profile-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
                    </button>
                    <div id="buyerAccountMenu" class="lk-account-dropdown" data-account-menu-panel hidden>
                        <a href="{{ route('buyer.account') }}" class="lk-account-dropdown__current"><span class="lk-account-dropdown__avatar"><x-shared.profile-image :path="$profilePhoto" role="buyer" :alt="$buyerName" /></span><span><strong>{{ $buyerName }}</strong><small>{{ __('common.labels.manage_buyer_account') }}</small></span></a>
                        <div class="lk-account-dropdown__divider"></div>
                        <p class="lk-account-dropdown__label">{{ __('common.labels.saved_accounts') }}</p>
                        @forelse($savedAccounts as $account)
                            @php $accountInitials = collect(explode(' ', trim((string) ($account['name'] ?? $account['email']))))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'LK'; @endphp
                            <form method="POST" action="{{ route('account.switch') }}">
                                @csrf
                                <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                                <button type="submit" class="lk-account-dropdown__account"><span class="lk-account-dropdown__avatar">{{ $accountInitials }}</span><span><strong>{{ $account['name'] ?? $account['email'] }}</strong><small>{{ str($account['account_type'] ?? 'Account')->headline() }} · {{ $account['email'] }}</small></span></button>
                            </form>
                        @empty
                            <p class="lk-account-dropdown__empty">{{ __('common.labels.other_accounts_empty') }}</p>
                        @endforelse
                        <div class="lk-account-dropdown__divider"></div>
                        <form method="POST" action="{{ route('account.switch') }}">@csrf<button type="submit" class="lk-account-dropdown__switch"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10l-3-3M17 17H7l3 3M17 7l3 3M7 17l-3-3"/></svg>{{ __('common.labels.switch_another_account') }}</button></form>
                        @if(Route::has('logout'))
                            <div class="lk-account-dropdown__divider"></div>
                            <form method="POST" action="{{ route('logout') }}" class="lk-account-dropdown__logout-form" data-confirm-title="{{ __('common.confirmation.logout_title') }}" data-confirm-message="{{ __('common.confirmation.logout_message') }}" data-confirm-label="{{ __('common.confirmation.logout_label') }}">
                                @csrf
                                <button type="submit" class="lk-account-dropdown__logout"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>{{ __('navigation.items.logout') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</header>
