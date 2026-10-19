@props([
    'title' => 'Seller Center',
    'subtitle' => 'Manage your store operations and performance.',
])

@php
@endphp

<header class="sl-header">
    <div class="sl-header-left">
        <button class="sl-icon-btn sl-mobile-menu" type="button" data-sl-mobile-menu aria-label="Open navigation" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="sl-header-title">
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>
    </div>

    <div class="sl-header-actions">
        <a href="{{ route('seller.messages') }}" class="sl-icon-btn sl-header-action" aria-label="Messages">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>
            @if(($sellerUiCounts['messages'] ?? 0) > 0)<span class="sl-action-badge">{{ $sellerUiCounts['messages'] }}</span>@endif
        </a>
        <button type="button" class="sl-icon-btn sl-header-action" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            @php($notificationCount = (int) ($sellerUiCounts['notifications'] ?? 0))
            <span class="sl-action-badge" data-seller-notification-count style="{{ $notificationCount > 0 ? '' : 'display:none' }}">{{ $notificationCount }}</span>
        </button>
        <x-workspace-account-menu label="Seller Account" profile-route="seller.account" />
    </div>
</header>
