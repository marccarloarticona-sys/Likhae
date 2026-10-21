@props(['notificationCount' => 0])
@php($notificationCount = (int) $notificationCount)

<header class="sticky top-0 z-30 hidden h-[62px] items-center justify-between gap-3 border-b border-line bg-page/95 px-6 backdrop-blur-xl lg:flex">
    <div class="min-w-0">
        <strong class="block truncate text-sm font-semibold text-ink">Logistics Center</strong>
        <span class="text-[10px] text-muted">Operations workspace</span>
    </div>
    <button type="button" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle class="relative grid h-9 w-9 place-items-center rounded-full border border-line bg-surface text-ink transition hover:bg-surface-hover">
        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
        @if($notificationCount > 0)<span class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border-2 border-white bg-red-600 px-1 text-[8px] font-bold leading-none text-white">{{ $notificationCount }}</span>@endif
    </button>
    <x-shared.workspace.account-menu label="Logistics Account" profile-route="logistics.profile" />
</header>

<header class="sticky top-0 z-30 flex h-[62px] items-center justify-between border-b border-line bg-page/95 px-4 backdrop-blur-xl lg:hidden">
    <button type="button" id="mobileSidebarToggle" data-sl-mobile-menu data-open-label="Open navigation" data-close-label="Close navigation" aria-controls="logisticsSidebar" aria-expanded="false" aria-label="Open navigation" class="grid h-9 w-9 place-items-center rounded-lg text-ink transition hover:bg-surface-hover">
        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current stroke-[1.7]" aria-hidden="true"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
    </button>
    <a href="{{ route('logistics.dashboard') }}" class="flex items-center gap-2" aria-label="LIKHAE Logistics Center">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-primary text-[12px] font-black text-white">L</span>
        <span class="text-[13px] font-extrabold tracking-[-0.04em] text-ink">LIKHAE</span>
    </a>
    <button type="button" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle class="relative grid h-9 w-9 place-items-center rounded-full border border-line bg-surface text-ink transition hover:bg-surface-hover">
        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
        @if($notificationCount > 0)<span class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border-2 border-white bg-red-600 px-1 text-[8px] font-bold leading-none text-white">{{ $notificationCount }}</span>@endif
    </button>
    <x-shared.workspace.account-menu label="Logistics Account" profile-route="logistics.profile" compact />
</header>
