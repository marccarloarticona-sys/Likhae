@props([
    'title' => 'Seller Center',
    'subtitle' => 'Manage your store operations and performance.',
])

@php
@endphp

<header class="sl-header">
    <div class="sl-header-left">
        <button class="sl-icon-btn sl-mobile-menu" type="button" data-sl-mobile-menu data-open-label="Open navigation" data-close-label="Close navigation" aria-controls="seller-sidebar" aria-label="Open navigation" aria-expanded="false">
            <span class="sl-mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
        </button>
        <div class="sl-header-title">
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>
    </div>

    <div class="sl-header-search sl-search-wrap" data-workspace-search data-search-workspace="seller" data-search-label="Seller">
        <form class="sl-search" action="{{ route('seller.dashboard') }}" method="GET" role="search" data-workspace-search-form>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input name="q" type="search" placeholder="Search all Seller pages..." aria-label="Search all Seller pages" aria-autocomplete="list" aria-expanded="false" aria-controls="sellerSearchSuggestions" autocomplete="off" data-workspace-search-input>
            <kbd>Ctrl K</kbd>
        </form>
        <div class="sl-search-dropdown" id="sellerSearchSuggestions" role="listbox" aria-label="Seller search suggestions" hidden data-workspace-search-results>
            <p class="sl-search-dropdown__heading" data-workspace-search-heading></p>
            <div class="sl-search-dropdown__options" data-workspace-search-options></div>
            <p class="sl-search-dropdown__empty" hidden data-workspace-search-empty></p>
            <button type="button" class="sl-search-dropdown__clear" hidden data-workspace-search-clear>Clear search history</button>
        </div>
    </div>

    <div class="sl-header-actions">
        @php($messageUnreadCount = (int) ($sellerMessagePreview['unread_count'] ?? $sellerUiCounts['messages'] ?? 0))
        <button type="button" class="sl-icon-btn sl-header-action" aria-label="Messages" aria-controls="sellerMessagePopover" aria-expanded="false" data-message-preview-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>
            <span class="sl-action-badge" data-seller-message-count style="{{ $messageUnreadCount > 0 ? '' : 'display:none' }}">{{ $messageUnreadCount }}</span>
        </button>
        <section id="sellerMessagePopover" class="sl-message-popover" data-message-header-popover data-preview-url="{{ route('seller.messages.preview') }}" data-message-url-template="{{ route('seller.messages', ['conversation' => '__conversation__']) }}" hidden aria-label="Recent messages">
            <div class="sl-message-popover__head">
                <div><strong>Messages</strong><span data-message-preview-summary>{{ $messageUnreadCount > 0 ? $messageUnreadCount.' unread' : 'You are all caught up' }}</span></div>
                <a href="{{ route('seller.messages') }}">View inbox</a>
            </div>
            <div class="sl-message-popover__list" data-message-preview-list>
                @forelse(($sellerMessagePreview['items'] ?? []) as $messagePreview)
                    <a href="{{ route('seller.messages', ['conversation' => $messagePreview['id']]) }}" class="sl-message-preview" data-message-preview-item data-conversation-id="{{ $messagePreview['id'] }}">
                        <span class="sl-message-preview__avatar" aria-hidden="true">{{ $messagePreview['initial'] }}</span>
                        <span class="sl-message-preview__copy"><strong data-message-preview-name>{{ $messagePreview['name'] }}</strong><span data-message-preview-text>{{ $messagePreview['preview'] }}</span><small data-message-preview-time>{{ $messagePreview['time'] }}</small></span>
                        <span class="sl-message-preview__unread" data-message-preview-unread style="{{ $messagePreview['unread_count'] > 0 ? '' : 'display:none' }}">{{ $messagePreview['unread_count'] }}</span>
                    </a>
                @empty
                    <div class="sl-message-popover__empty" data-message-preview-empty><strong>No messages yet</strong><span>New conversations will show up here.</span></div>
                @endforelse
            </div>
        </section>
        <button type="button" class="sl-icon-btn sl-header-action" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            @php($notificationCount = (int) ($sellerUiCounts['notifications'] ?? 0))
            <span class="sl-action-badge" data-seller-notification-count style="{{ $notificationCount > 0 ? '' : 'display:none' }}">{{ $notificationCount }}</span>
        </button>
        <x-shared.workspace.account-menu :label="__('settings.workspaces.seller')" profile-route="seller.account" />
    </div>
</header>
