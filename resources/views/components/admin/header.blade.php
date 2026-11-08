@props([
    'title' => 'Dashboard',
    'subtitle' => 'Platform operations and marketplace health at a glance.',
])

@php
    $admin = auth()->user();
    $adminName = data_get($admin, 'name', 'Admin User');
    $adminUiCounts = $adminUiCounts ?? ['messages' => 0, 'notifications' => 0];
    $adminMessagePreview = $adminMessagePreview ?? ['items' => [], 'unread_count' => $adminUiCounts['messages'] ?? 0];
    $adminMessageUnreadCount = (int) ($adminMessagePreview['unread_count'] ?? $adminUiCounts['messages'] ?? 0);
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
        <button type="button" class="ad-icon-btn" aria-label="Messages" aria-controls="adminMessagePopover" aria-expanded="false" data-admin-message-preview-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"/><path d="M8 9h8M8 13h5"/></svg>
            <span class="ad-badge" data-admin-message-count aria-label="{{ $adminMessageUnreadCount }} unread messages" style="{{ $adminMessageUnreadCount > 0 ? '' : 'display:none' }}">{{ $adminMessageUnreadCount }}</span>
        </button>
        <section id="adminMessagePopover" class="ad-message-popover" data-admin-message-popover data-preview-url="{{ route('admin.messages.preview') }}" data-message-url-template="{{ route('admin.messages', ['section' => 'chats', 'conversation' => '__conversation__']) }}" hidden aria-label="Recent messages">
            <div class="ad-message-popover__head">
                <div><strong>Messages</strong><span data-admin-message-preview-summary>{{ $adminMessageUnreadCount > 0 ? $adminMessageUnreadCount.' unread' : 'You are all caught up' }}</span></div>
                <a href="{{ route('admin.messages') }}">View inbox</a>
            </div>
            <div class="ad-message-popover__list" data-admin-message-preview-list>
                @forelse(($adminMessagePreview['items'] ?? []) as $messagePreview)
                    <a href="{{ route('admin.messages', ['section' => 'chats', 'conversation' => $messagePreview['id']]) }}" class="ad-message-preview {{ $messagePreview['unread_count'] > 0 ? 'is-unread' : '' }}" data-admin-message-preview-item data-conversation-id="{{ $messagePreview['id'] }}">
                        <span class="ad-message-preview__avatar" aria-hidden="true">{{ $messagePreview['initial'] }}</span>
                        <span class="ad-message-preview__copy"><strong data-admin-message-preview-name>{{ $messagePreview['name'] }}</strong><span data-admin-message-preview-text>{{ $messagePreview['preview'] }}</span><small data-admin-message-preview-time>{{ $messagePreview['time'] }}</small></span>
                        <span class="ad-message-preview__unread" data-admin-message-preview-unread style="{{ $messagePreview['unread_count'] > 0 ? '' : 'display:none' }}">{{ $messagePreview['unread_count'] }}</span>
                    </a>
                @empty
                    <div class="ad-message-popover__empty" data-admin-message-preview-empty><strong>No messages yet</strong><span>New conversations will show up here.</span></div>
                @endforelse
            </div>
        </section>
        <button type="button" class="ad-icon-btn" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            @if(($adminUiCounts['notifications'] ?? 0) > 0)<span class="ad-badge">{{ $adminUiCounts['notifications'] }}</span>@endif
        </button>
        <a class="ad-profile" href="{{ route('admin.account') }}">
            <span><strong>{{ $adminName }}</strong><small>Platform Administrator</small></span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 10 3 3 3-3"/></svg>
        </a>
    </div>
</header>
