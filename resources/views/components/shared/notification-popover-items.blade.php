@forelse($notifications as $notification)
    @php($isSellerNotification = $notificationUser?->isAccountType(\App\Models\User::TYPE_SELLER))
    @if($isSellerNotification)
        <form method="POST" action="{{ route('seller.notifications.open', $notification) }}" class="lk-notification-popover__form">@csrf
        <button type="submit" class="lk-notification-popover__item {{ is_null($notification->read_at) ? 'is-unread' : '' }}" data-notification-item>
    @else
        <a href="{{ $notification->action_url ?: route('notifications.index') }}" class="lk-notification-popover__item {{ is_null($notification->read_at) ? 'is-unread' : '' }}" data-notification-item>
    @endif
        <span class="lk-notification-popover__dot" aria-hidden="true"></span>
        <span class="lk-notification-popover__copy">
            <strong>{{ $notification->title }}</strong>
            @if($notification->message)<span>{{ \Illuminate\Support\Str::limit($notification->message, 98) }}</span>@endif
            <small>{{ optional($notification->created_at)->diffForHumans() }}</small>
        </span>
    @if($isSellerNotification)</button></form>@else</a>@endif
@empty
    <div class="lk-notification-popover__empty">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
        <strong>No notifications yet</strong>
        <span>Updates for your account will appear here.</span>
    </div>
@endforelse
