@extends($layout)

@section('title', 'Notifications')
@section('subtitle', 'Review the latest updates for your account.')
@section('active', 'notifications')

@section('content')
    @php
        $notificationUser = auth()->user();
        $isSeller = $notificationUser->isAccountType(\App\Models\User::TYPE_SELLER);
    @endphp

    <style>
        .notification-center {
            --nc-ink: #321d17;
            --nc-muted: #866d60;
            --nc-border: #eadccc;
            --nc-border-soft: rgba(125, 95, 75, .14);
            --nc-surface: #fffdf9;
            --nc-surface-soft: #fbf7f2;
            --nc-accent: #7d3028;
            --nc-accent-soft: #f5e9e4;
            width: min(100%, 1280px);
            margin: 0 auto;
            padding: 8px 0 30px;
            color: var(--nc-ink);
        }
        .dark .notification-center {
            --nc-ink: #f5eee8;
            --nc-muted: #c2afa4;
            --nc-border: #4b3831;
            --nc-border-soft: rgba(204, 177, 160, .14);
            --nc-surface: #241b18;
            --nc-surface-soft: #2b211e;
            --nc-accent: #efb0a4;
            --nc-accent-soft: #422a25;
        }
        .nc-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 22px; }
        .nc-heading { min-width: 0; }
        .nc-eyebrow { display: block; margin-bottom: 7px; color: var(--nc-accent); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .nc-heading h1 { margin: 0; color: inherit; font-size: clamp(25px, 3vw, 34px); font-weight: 800; letter-spacing: -.035em; line-height: 1.15; }
        .nc-heading p { margin: 8px 0 0; color: var(--nc-muted); font-size: 13px; }
        .nc-header-tools { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .nc-stat { min-width: 102px; padding: 9px 13px; border: 1px solid var(--nc-border); border-radius: 11px; background: var(--nc-surface); }
        .nc-stat strong { display: block; font-size: 15px; line-height: 1.2; }
        .nc-stat span { display: block; margin-top: 3px; color: var(--nc-muted); font-size: 10px; }
        .nc-mark-read { min-height: 40px; padding: 0 15px; border: 1px solid var(--nc-border); border-radius: 10px; background: var(--nc-surface); color: var(--nc-ink); font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; transition: border-color .15s ease, background .15s ease, color .15s ease; }
        .nc-mark-read:hover { border-color: var(--nc-accent); color: var(--nc-accent); background: var(--nc-accent-soft); }
        .nc-list { overflow: hidden; border: 1px solid var(--nc-border); border-radius: 16px; background: var(--nc-surface); box-shadow: 0 8px 26px rgba(60, 33, 23, .045); }
        .nc-item-form { margin: 0; }
        .nc-item { position: relative; display: grid; width: 100%; min-height: 96px; grid-template-columns: 48px minmax(0, 1fr) auto 8px; align-items: center; gap: 16px; padding: 17px 20px; border: 0; border-bottom: 1px solid var(--nc-border-soft); background: transparent; color: inherit; font: inherit; text-align: left; text-decoration: none; cursor: pointer; transition: background .15s ease; }
        .nc-item:last-child { border-bottom: 0; }
        .nc-item:hover { background: var(--nc-surface-soft); }
        .nc-item.is-unread { background: color-mix(in srgb, var(--nc-accent-soft) 52%, var(--nc-surface)); }
        .nc-item.is-unread:hover { background: var(--nc-accent-soft); }
        .nc-item:focus-visible, .nc-mark-read:focus-visible { outline: 3px solid color-mix(in srgb, var(--nc-accent) 55%, transparent); outline-offset: -3px; }
        .nc-icon { display: grid; width: 46px; height: 46px; place-items: center; border: 1px solid transparent; border-radius: 13px; color: #78352d; background: #f6e9e4; }
        .nc-icon svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
        .nc-icon.is-orders { color: #6f542d; background: #f7f0df; }
        .nc-icon.is-delivery { color: #326c58; background: #e9f3ed; }
        .nc-icon.is-rewards, .nc-icon.is-earnings { color: #8b6428; background: #fbf2dc; }
        .nc-icon.is-reviews { color: #665b8a; background: #efecf8; }
        .nc-icon.is-pickup { color: #356b78; background: #e6f2f4; }
        .dark .nc-icon { color: #efb0a4; background: #422a25; }
        .dark .nc-icon.is-orders { color: #e3c688; background: #3b3323; }
        .dark .nc-icon.is-delivery { color: #9ed1b3; background: #23392e; }
        .dark .nc-icon.is-rewards, .dark .nc-icon.is-earnings { color: #e6c27b; background: #3d3422; }
        .dark .nc-icon.is-reviews { color: #c2b4ec; background: #302b40; }
        .dark .nc-icon.is-pickup { color: #9acbd4; background: #23373b; }
        .nc-copy { min-width: 0; }
        .nc-title { display: block; overflow: hidden; color: var(--nc-ink); font-size: 14px; font-weight: 750; line-height: 1.35; text-overflow: ellipsis; }
        .nc-message { display: block; overflow: hidden; margin-top: 4px; color: var(--nc-muted); font-size: 12px; line-height: 1.45; text-overflow: ellipsis; }
        .nc-meta { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 7px; }
        .nc-kind, .nc-page { display: inline-flex; align-items: center; min-height: 23px; padding: 3px 8px; border: 1px solid var(--nc-border-soft); border-radius: 99px; color: var(--nc-muted); font-size: 10px; font-weight: 650; white-space: nowrap; }
        .nc-kind { color: var(--nc-accent); border-color: color-mix(in srgb, var(--nc-accent) 20%, transparent); background: var(--nc-accent-soft); }
        .nc-priority { display: inline-flex; align-items: center; min-height: 23px; padding: 3px 8px; border: 1px solid #e6b6a7; border-radius: 99px; color: #8c3027; background: #fff0e9; font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
        .dark .nc-priority { border-color: #80443b; color: #ffb6a5; background: #42251f; }
        .nc-page::before { content: ''; width: 5px; height: 5px; margin-right: 6px; border-radius: 50%; background: currentColor; opacity: .65; }
        .nc-time { display: block; margin-left: 3px; color: var(--nc-muted); font-size: 10px; text-align: right; white-space: nowrap; }
        .nc-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: transparent; }
        .nc-item.is-unread .nc-unread-dot { background: var(--nc-accent); box-shadow: 0 0 0 3px var(--nc-accent-soft); }
        .nc-empty { display: grid; justify-items: center; padding: 62px 20px; text-align: center; }
        .nc-empty-icon { display: grid; width: 52px; height: 52px; place-items: center; margin-bottom: 12px; border-radius: 16px; color: var(--nc-accent); background: var(--nc-accent-soft); }
        .nc-empty-icon svg { width: 25px; height: 25px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
        .nc-empty strong { font-size: 15px; }
        .nc-empty p { margin: 5px 0 0; color: var(--nc-muted); font-size: 12px; }
        .nc-pagination { margin-top: 18px; }
        .nc-pagination nav { justify-content: center; }
        @media (max-width: 760px) {
            .notification-center { padding-top: 4px; }
            .nc-header { display: grid; align-items: start; gap: 15px; }
            .nc-header-tools { justify-content: flex-start; }
            .nc-stat { min-width: 92px; }
            .nc-item { min-height: 86px; grid-template-columns: 42px minmax(0, 1fr) 8px; gap: 12px; padding: 14px; }
            .nc-icon { width: 40px; height: 40px; border-radius: 11px; }
            .nc-icon svg { width: 20px; height: 20px; }
            .nc-meta { grid-column: 2; grid-row: 2; align-items: center; flex-wrap: wrap; margin-top: -6px; }
            .nc-time { margin: 0; text-align: left; }
            .nc-unread-dot { grid-column: 3; grid-row: 1; }
        }
        @media (max-width: 430px) {
            .nc-item { grid-template-columns: 36px minmax(0, 1fr) 7px; gap: 10px; padding: 13px 11px; }
            .nc-icon { width: 36px; height: 36px; }
            .nc-title { font-size: 13px; }
            .nc-message { font-size: 11px; }
            .nc-kind, .nc-page { min-height: 21px; padding: 2px 7px; font-size: 9px; }
            .nc-stat { min-width: 82px; padding: 8px 10px; }
            .nc-mark-read { min-height: 37px; padding-inline: 11px; }
        }
    </style>

    <div class="notification-center">
        <header class="nc-header">
            <div class="nc-heading">
                <span class="nc-eyebrow">Activity center</span>
                <h1>Notifications</h1>
                <p>Stay up to date with activity relevant to your account.</p>
            </div>
            <div class="nc-header-tools">
                <div class="nc-stat"><strong>{{ number_format($notifications->total()) }}</strong><span>Total updates</span></div>
                <div class="nc-stat"><strong>{{ number_format($unreadCount) }}</strong><span>Unread</span></div>
                @if($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="nc-mark-read">Mark all read</button>
                    </form>
                @endif
            </div>
        </header>

        <div class="nc-list" @if($isSeller) data-seller-notification-list data-seller-notification-open-template="{{ route('seller.notifications.open', ['notification' => '__id__']) }}" @endif>
            @forelse($notifications as $notification)
                @php
                    $type = strtoupper((string) $notification->type);
                    [$notificationKind, $kindLabel] = match (true) {
                        $type === 'ANNOUNCEMENT' => ['announcements', 'Announcements'],
                        str_contains($type, 'SHIPMENT') => ['delivery', 'Delivery'],
                        str_starts_with($type, 'ORDER_'), str_starts_with($type, 'SELLER_ORDER_') => ['orders', 'Orders'],
                        str_contains($type, 'REWARD') => ['rewards', 'Rewards'],
                        str_contains($type, 'REVIEW') => ['reviews', 'Reviews'],
                        str_contains($type, 'EARNING') => ['earnings', 'Earnings'],
                        str_contains($type, 'PICKUP'), str_contains($type, 'ASSIGNMENT') => ['pickup', 'Pickup'],
                        default => ['general', 'Account'],
                    };
                    $actionPath = trim((string) parse_url((string) $notification->action_url, PHP_URL_PATH), '/');
                    $pageLabel = match (true) {
                        str_starts_with($actionPath, 'buyer/orders') => 'Buyer orders',
                        str_contains($actionPath, 'refund') => 'Order refunds',
                        str_starts_with($actionPath, 'seller/orders') => 'Seller orders',
                        str_starts_with($actionPath, 'buyer/returns') => 'Returns',
                        str_starts_with($actionPath, 'buyer/rewards') => 'Rewards',
                        str_starts_with($actionPath, 'buyer/products') => 'Product details',
                        str_starts_with($actionPath, 'seller/reviews') => 'Reviews',
                        str_starts_with($actionPath, 'seller/products') => 'Seller products',
                        str_starts_with($actionPath, 'seller/compliance') => 'Seller compliance',
                        str_starts_with($actionPath, 'rider/dashboard') => 'Rider dashboard',
                        str_starts_with($actionPath, 'rider/earnings') => 'Earnings',
                        str_starts_with($actionPath, 'logistics/parcels') => 'Parcel details',
                        str_starts_with($actionPath, 'logistics/shipping-rate') => 'Shipping rates',
                        str_starts_with($actionPath, 'admin/complaints') => 'Complaints',
                        str_starts_with($actionPath, 'admin/products') => 'Product review',
                        str_starts_with($actionPath, 'admin/compliance') => 'Compliance',
                        str_starts_with($actionPath, 'admin/shipping-rate-requests') => 'Shipping rates',
                        str_starts_with($actionPath, 'track/') => 'Parcel tracking',
                        str_starts_with($actionPath, 'notifications') || $actionPath === '' => 'Notification center',
                        default => 'Account activity',
                    };
                    $isUnread = is_null($notification->read_at);
                    $itemClass = 'nc-item'.($isUnread ? ' is-unread' : '');
                    $isPriorityAnnouncement = $type === 'ANNOUNCEMENT';
                    $notificationMessage = $notification->message;
                    if (preg_match('/\s+is now\s+(.+?)[.!?]?\s*$/i', (string) $notificationMessage, $statusMatch)) {
                        $repeatedStatus = trim($statusMatch[1], " .!?\t\n\r\0\x0B");
                        if ($repeatedStatus !== '' && str_contains(strtolower((string) $notification->title), strtolower($repeatedStatus))) {
                            $notificationMessage = preg_replace('/\s+is now\s+.+[.!?]?\s*$/i', '', (string) $notificationMessage);
                        }
                    }
                @endphp

                @if($isSeller)
                    <form method="POST" action="{{ route('seller.notifications.open', $notification) }}" class="nc-item-form">
                        @csrf
                        <button type="submit" class="{{ $itemClass }}" data-notification data-seller-notification-event-id="{{ $notification->id }}" aria-label="Open {{ $pageLabel }}: {{ $notification->title }}">
                @else
                    <a href="{{ $notification->action_url ?: route('notifications.index') }}" class="{{ $itemClass }}" aria-label="Open {{ $pageLabel }}: {{ $notification->title }}">
                @endif
                            <span class="nc-icon is-{{ $notificationKind }}" aria-hidden="true">
                                <svg viewBox="0 0 24 24">
                                    @if($notificationKind === 'delivery')
                                        <path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3.5 7.8 12 12l8.5-4.2M12 12v8.5M7.5 5.3l9 4.5"/>
                                    @elseif($notificationKind === 'orders')
                                        <path d="M7 3.5h8l4 4V20a1 1 0 0 1-1 1H7a2 2 0 0 1-2-2V5.5a2 2 0 0 1 2-2Z"/><path d="M15 3.8V8h4M8.5 12h7M8.5 16h7"/>
                                    @elseif($notificationKind === 'rewards' || $notificationKind === 'earnings')
                                        <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
                                    @elseif($notificationKind === 'reviews')
                                        <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H7l-4 2v-5.1A7.5 7.5 0 1 1 20 11.5Z"/><path d="m9.3 11.6 1.7 1.7 3.8-4"/>
                                    @elseif($notificationKind === 'pickup')
                                        <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>
                                    @else
                                        <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/>
                                    @endif
                                </svg>
                            </span>
                            <span class="nc-copy">
                                <strong class="nc-title">{{ $notification->title }}</strong>
                                @if($notificationMessage)<span class="nc-message">{{ $notificationMessage }}</span>@endif
                            </span>
                            <span class="nc-meta" aria-label="{{ $kindLabel }} notification, page: {{ $pageLabel }}">
                                @if($isPriorityAnnouncement)<span class="nc-priority">Priority</span>@endif
                                <span class="nc-kind">{{ $kindLabel }}</span>
                                <span class="nc-page">Page: {{ $pageLabel }}</span>
                                <time class="nc-time" datetime="{{ optional($notification->created_at)->toIso8601String() }}">{{ optional($notification->created_at)->diffForHumans() }}</time>
                            </span>
                            <span class="nc-unread-dot" aria-label="{{ $isUnread ? 'Unread' : 'Read' }}"></span>
                @if($isSeller)
                        </button>
                    </form>
                @else
                    </a>
                @endif
            @empty
                <div class="nc-empty">
                    <span class="nc-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg></span>
                    <strong>You’re all caught up</strong>
                    <p>New updates for your account will appear here.</p>
                </div>
            @endforelse
        </div>

        @if($notifications->hasPages())
            <div class="nc-pagination">{{ $notifications->links() }}</div>
        @endif
    </div>
@endsection
