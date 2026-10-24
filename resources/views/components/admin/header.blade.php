@props([
    'title' => 'Dashboard',
    'subtitle' => 'Platform operations and marketplace health at a glance.',
])

@php
    $admin = auth()->user();
    $adminName = data_get($admin, 'name', 'Admin User');
    $initials = collect(explode(' ', $adminName))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: 'A';
    $adminUiCounts = $adminUiCounts ?? ['messages' => 0, 'notifications' => 0];
@endphp

<header class="lk-admin-topbar" data-admin-header role="banner">
    <div class="ad-header-title">
        <button class="ad-icon-btn ad-mobile-menu" type="button" data-admin-mobile-menu aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div>
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>
    </div>

    <div class="ad-search-wrap" data-admin-search>
        <form class="ad-search" action="{{ route('admin.dashboard') }}" method="GET" role="search" data-admin-search-form data-user-search-url="{{ route('admin.users') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input name="admin_search" type="search" value="{{ request('admin_search') }}" placeholder="Search all Admin pages..." aria-label="Search all Admin pages" aria-autocomplete="list" aria-expanded="false" aria-controls="adminSearchSuggestions" autocomplete="off" data-admin-search-input>
            <kbd>⌘ K</kbd>
        </form>
        <div class="ad-search-dropdown" id="adminSearchSuggestions" role="listbox" aria-label="Admin search suggestions" hidden data-admin-search-results>
            <p class="ad-search-dropdown__heading" data-admin-search-heading></p>
            <div class="ad-search-dropdown__options" data-admin-search-options></div>
            <p class="ad-search-dropdown__empty" hidden data-admin-search-empty></p>
            <button type="button" class="ad-search-dropdown__clear" hidden data-admin-search-clear>Clear search history</button>
        </div>
    </div>

    <div class="ad-header-actions">
        <a class="ad-icon-btn" href="{{ route('admin.messages') }}" aria-label="Messages">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"/><path d="M8 9h8M8 13h5"/></svg>
            @if(($adminUiCounts['messages'] ?? 0) > 0)<span class="ad-badge">{{ $adminUiCounts['messages'] }}</span>@endif
        </a>
        <button type="button" class="ad-icon-btn" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            @if(($adminUiCounts['notifications'] ?? 0) > 0)<span class="ad-badge">{{ $adminUiCounts['notifications'] }}</span>@endif
        </button>
        <a class="ad-profile" href="{{ route('admin.account') }}">
            <span class="ad-avatar">{{ $initials }}</span>
            <span><strong>{{ $adminName }}</strong><small>Platform Administrator</small></span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 10 3 3 3-3"/></svg>
        </a>
    </div>
</header>
