@props(['title' => 'Dashboard', 'subtitle' => 'Logistics operations workspace.', 'notificationCount' => 0, 'messageCount' => 0])
@php($notificationCount = (int) $notificationCount)
@php($messageCount = (int) $messageCount)

<header class="sl-workspace-header sticky top-0 z-30 hidden h-[62px] items-center justify-between gap-3 border-b border-line bg-page/95 px-6 backdrop-blur-xl lg:flex">
    <div class="sl-header-title min-w-0">
        <strong class="block truncate text-sm font-semibold text-ink">{{ $title }}</strong>
        <span class="text-[10px] text-muted">{{ $subtitle }}</span>
    </div>
    <div class="sl-search-wrap" data-workspace-search data-search-workspace="logistics" data-search-label="Logistics">
        <form class="sl-search" action="{{ route('logistics.dashboard') }}" method="GET" role="search" data-workspace-search-form>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input name="q" type="search" placeholder="Search all Logistics pages..." aria-label="Search all Logistics pages" aria-autocomplete="list" aria-expanded="false" aria-controls="logisticsSearchSuggestions" autocomplete="off" data-workspace-search-input>
            <kbd>&#8984; K</kbd>
        </form>
        <div class="sl-search-dropdown" id="logisticsSearchSuggestions" role="listbox" aria-label="Logistics search suggestions" hidden data-workspace-search-results>
            <p class="sl-search-dropdown__heading" data-workspace-search-heading></p>
            <div class="sl-search-dropdown__options" data-workspace-search-options></div>
            <p class="sl-search-dropdown__empty" hidden data-workspace-search-empty></p>
            <button type="button" class="sl-search-dropdown__clear" hidden data-workspace-search-clear>Clear search history</button>
        </div>
    </div>
    <div class="sl-header-actions">
        <a href="{{ route('logistics.messages') }}" aria-label="Messages" title="Messages" class="sl-header-icon-btn relative grid h-9 w-9 place-items-center rounded-lg border border-line bg-surface text-ink transition hover:bg-surface-hover">
            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"/><path d="M8 9h8M8 13h5"/></svg>
            @if($messageCount > 0)<span class="sl-header-badge">{{ $messageCount }}</span>@endif
        </a>
        <button type="button" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle class="sl-header-icon-btn relative grid h-9 w-9 place-items-center rounded-full border border-line bg-surface text-ink transition hover:bg-surface-hover">
            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
            @if($notificationCount > 0)<span class="sl-header-badge">{{ $notificationCount }}</span>@endif
        </button>
        <x-shared.workspace.account-menu :label="__('settings.workspaces.logistics')" profile-route="logistics.profile" />
    </div>
</header>

<header class="sl-mobile-header sticky top-0 z-30 flex h-[62px] items-center justify-between border-b border-line bg-page/95 px-4 backdrop-blur-xl lg:hidden">
    <button type="button" id="mobileSidebarToggle" data-sl-mobile-menu data-open-label="Open navigation" data-close-label="Close navigation" aria-controls="logisticsSidebar" aria-expanded="false" aria-label="Open navigation" class="sl-header-icon-btn grid h-9 w-9 place-items-center rounded-lg text-ink transition hover:bg-surface-hover">
        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current stroke-[1.7]" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
    </button>
    <a href="{{ route('logistics.dashboard') }}" class="flex items-center gap-2" aria-label="LIKHAE Logistics Center">
        <x-shared.logistics-logo label="LIKHAE Logistics Center" class="sl-logistics-logo--compact" />
    </a>
    <div class="sl-mobile-header-actions">
        <a href="{{ route('logistics.messages') }}" aria-label="Messages" title="Messages" class="sl-header-icon-btn relative grid h-9 w-9 place-items-center rounded-lg border border-line bg-surface text-ink transition hover:bg-surface-hover">
            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"/><path d="M8 9h8M8 13h5"/></svg>
            @if($messageCount > 0)<span class="sl-header-badge">{{ $messageCount }}</span>@endif
        </a>
        <button type="button" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle class="sl-header-icon-btn relative grid h-9 w-9 place-items-center rounded-lg border border-line bg-surface text-ink transition hover:bg-surface-hover">
            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
            @if($notificationCount > 0)<span class="sl-header-badge">{{ $notificationCount }}</span>@endif
        </button>
        <x-shared.workspace.account-menu :label="__('settings.workspaces.logistics')" profile-route="logistics.profile" compact />
    </div>
</header>
