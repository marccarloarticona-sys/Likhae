@php
    $notificationUser = auth()->user();
    $recentNotifications = $notificationUser
        ? $notificationUser->notifications()->latest()->limit(8)->get()
        : collect();
    $unreadNotifications = $recentNotifications->whereNull('read_at')->count();
    $notificationSoundKey = match (strtoupper((string) $notificationUser?->account_type)) {
        'SELLER' => 'seller_notification_sounds',
        'ADMIN' => 'admin_notification_sounds',
        'LOGISTICS' => 'logistics_notification_sounds',
        'RIDER' => 'rider_notification_sounds',
        default => 'notification_sounds',
    };
    $messagesUrl = match (strtoupper((string) $notificationUser?->account_type)) {
        'SELLER' => route('seller.messages'),
        'ADMIN' => route('admin.messages'),
        'LOGISTICS' => route('logistics.messages'),
        'RIDER' => route('rider.messages'),
        default => route('buyer.messages'),
    };
    $notificationSoundsEnabled = (bool) data_get($notificationUser?->notification_preferences, $notificationSoundKey, true);
@endphp

<script>window.LIKHAE_NOTIFICATION_SOUND_CONFIG = @json(['userId' => $notificationUser?->id, 'enabled' => $notificationSoundsEnabled]); window.LIKHAE_MESSAGES_URL = @json($messagesUrl);</script>

<section id="notificationPopover" class="lk-notification-popover" data-notification-popover hidden aria-label="Notifications">
    <div class="lk-notification-popover__head">
        <div>
            <strong>Notifications</strong>
            <span>{{ $unreadNotifications ? $unreadNotifications.' unread' : 'You are all caught up' }}</span>
        </div>
        <div class="lk-notification-popover__actions">
            @if($unreadNotifications)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit">Mark all read</button>
                </form>
            @endif
            <a href="{{ route('notifications.index') }}">View all</a>
        </div>
    </div>

    <label class="lk-notification-popover__search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
        <input type="search" data-notification-search placeholder="Search notifications..." aria-label="Search notifications">
    </label>

    <div class="lk-notification-popover__list" data-notification-list>
        @forelse($recentNotifications as $notification)
            <a
                href="{{ $notification->action_url ?: route('notifications.index') }}"
                class="lk-notification-popover__item {{ is_null($notification->read_at) ? 'is-unread' : '' }}"
                data-notification-item
            >
                <span class="lk-notification-popover__dot" aria-hidden="true"></span>
                <span class="lk-notification-popover__copy">
                    <strong>{{ $notification->title }}</strong>
                    @if($notification->message)<span>{{ \Illuminate\Support\Str::limit($notification->message, 98) }}</span>@endif
                    <small>{{ optional($notification->created_at)->diffForHumans() }}</small>
                </span>
            </a>
        @empty
            <div class="lk-notification-popover__empty">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                <strong>No notifications yet</strong>
                <span>Updates for your account will appear here.</span>
            </div>
        @endforelse
    </div>
</section>

@once
    <style>
        .lk-notification-popover {
            --notification-surface: rgba(255, 253, 249, .9);
            --notification-surface-soft: rgba(250, 243, 236, .78);
            --notification-border: rgba(220, 200, 184, .86);
            --notification-text: #321d17;
            --notification-muted: #866d60;
            --notification-link: #9f3328;
            --notification-hover: rgba(243, 228, 222, .78);
            --notification-unread: rgba(244, 225, 218, .82);
            --notification-dot: #ba3b2d;
            position: fixed; z-index: 1000; top: 72px; right: 18px;
            width: min(390px, calc(100vw - 24px)); overflow: hidden;
            border: 1px solid var(--notification-border); border-radius: 14px;
            background: var(--notification-surface); color: var(--notification-text);
            box-shadow: 0 22px 56px rgba(86, 28, 23, .18);
            backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        html.dark .lk-notification-popover {
            --notification-surface: rgba(31, 24, 21, .9);
            --notification-surface-soft: rgba(49, 37, 32, .78);
            --notification-border: rgba(90, 68, 58, .86);
            --notification-text: #f5efe8;
            --notification-muted: #c9b8ae;
            --notification-link: #eba99d;
            --notification-hover: rgba(73, 47, 40, .82);
            --notification-unread: rgba(85, 43, 37, .82);
            --notification-dot: #eba99d;
            box-shadow: 0 22px 56px rgba(0, 0, 0, .35);
        }
        .lk-notification-popover[hidden] { display: none; }
        .lk-notification-popover__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 15px 15px 12px; border-bottom: 1px solid var(--notification-border); }
        .lk-notification-popover__head strong { display: block; font-size: 14px; line-height: 1.2; }
        .lk-notification-popover__head span { display: block; margin-top: 4px; color: var(--notification-muted); font-size: 11px; }
        .lk-notification-popover__actions { display: flex; align-items: center; gap: 10px; white-space: nowrap; }
        .lk-notification-popover__actions form { margin: 0; }
        .lk-notification-popover__actions button, .lk-notification-popover__actions a { border: 0; background: none; padding: 0; color: var(--notification-link); font: inherit; font-size: 11px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .lk-notification-popover__actions button:hover, .lk-notification-popover__actions a:hover { text-decoration: underline; }
        .lk-notification-popover__search { display: flex; align-items: center; gap: 8px; margin: 10px 12px; border: 1px solid var(--notification-border); border-radius: 10px; padding: 0 10px; background: var(--notification-surface-soft); color: var(--notification-muted); }
        .lk-notification-popover__search svg { width: 16px; height: 16px; flex: 0 0 auto; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; }
        .lk-notification-popover__search input { width: 100%; border: 0; outline: 0; background: transparent; padding: 10px 0; color: var(--notification-text); font: inherit; font-size: 12px; }
        .lk-notification-popover__search input::placeholder { color: var(--notification-muted); }
        .lk-notification-popover__list { max-height: min(57vh, 430px); overflow-y: auto; padding: 2px 0 8px; }
        .lk-notification-popover__item { position: relative; display: flex; gap: 9px; padding: 11px 13px; color: inherit; text-decoration: none; transition: background .15s ease; }
        .lk-notification-popover__item:hover { background: var(--notification-hover); }
        .lk-notification-popover__item.is-unread { background: var(--notification-unread); }
        .lk-notification-popover__item.is-unread:hover { background: var(--notification-hover); }
        .lk-notification-popover__dot { width: 7px; height: 7px; flex: 0 0 7px; margin-top: 6px; border-radius: 50%; background: transparent; }
        .lk-notification-popover__item.is-unread .lk-notification-popover__dot { background: var(--notification-dot); }
        .lk-notification-popover__copy { min-width: 0; }
        .lk-notification-popover__copy strong, .lk-notification-popover__copy span, .lk-notification-popover__copy small { display: block; }
        .lk-notification-popover__copy strong { overflow: hidden; color: var(--notification-text); font-size: 12px; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
        .lk-notification-popover__copy span { display: -webkit-box; overflow: hidden; margin-top: 3px; color: var(--notification-muted); font-size: 11px; line-height: 1.32; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .lk-notification-popover__copy small { margin-top: 4px; color: var(--notification-muted); font-size: 10px; }
        .lk-audio-unlock-prompt { position: fixed; right: 18px; bottom: 18px; z-index: 1100; display: flex; align-items: center; gap: 12px; max-width: calc(100vw - 36px); border: 1px solid #d9b7aa; border-radius: 12px; background: #fffdf9; padding: 10px 12px; color: #493027; box-shadow: 0 14px 34px rgba(86,28,23,.18); font: 600 12px system-ui,sans-serif; }
        .lk-audio-unlock-prompt button { border: 0; border-radius: 8px; background: #8f382d; padding: 7px 10px; color: #fff; font: 700 11px system-ui,sans-serif; cursor: pointer; }
        .lk-audio-unlock-prompt button:hover { background: #6f2921; }
        html.dark .lk-audio-unlock-prompt { border-color: #5a443c; background: #241b18; color: #f7eee9; }
        html.dark .lk-audio-unlock-prompt button { background: #a84538; }
        .lk-notification-popover__empty { display: grid; justify-items: center; gap: 5px; padding: 33px 20px; text-align: center; }
        .lk-notification-popover__empty svg { width: 29px; height: 29px; margin-bottom: 4px; fill: none; stroke: var(--notification-muted); stroke-width: 1.5; }
        .lk-notification-popover__empty strong { font-size: 13px; }
        .lk-notification-popover__empty span { color: var(--notification-muted); font-size: 11px; }
        @media (max-width: 640px) { .lk-notification-popover { top: 62px; right: 12px; } .lk-notification-popover__head { padding: 13px 12px 10px; } .lk-notification-popover__actions { gap: 8px; } }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const popover = document.querySelector('[data-notification-popover]');
            if (!popover) return;
            const triggers = Array.from(document.querySelectorAll('[data-notification-toggle]'));
            const close = function () { popover.hidden = true; triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'false')); };
            triggers.forEach(function (trigger) { trigger.addEventListener('click', function () { const opening = popover.hidden; close(); if (opening) { popover.hidden = false; trigger.setAttribute('aria-expanded', 'true'); popover.querySelector('[data-notification-search]')?.focus(); } }); });
            document.addEventListener('click', function (event) { if (!popover.hidden && !popover.contains(event.target) && !event.target.closest('[data-notification-toggle]')) close(); });
            document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !popover.hidden) close(); });
            popover.querySelector('[data-notification-search]')?.addEventListener('input', function (event) { const query = event.target.value.trim().toLowerCase(); popover.querySelectorAll('[data-notification-item]').forEach((item) => { item.hidden = query !== '' && !item.textContent.toLowerCase().includes(query); }); });
        });
    </script>
@endonce
