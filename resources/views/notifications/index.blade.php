@extends($layout)

@section('title', 'Notifications')
@section('subtitle', 'Review the latest updates for your account.')
@section('active', 'notifications')

@section('content')
    <div style="max-width:920px;margin:0 auto;padding:8px 0 24px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px">
            <div><h1 style="margin:0;color:inherit;font-size:clamp(22px,3vw,30px);font-weight:800">Notifications</h1><p style="margin:6px 0 0;opacity:.66;font-size:13px">Stay up to date with activity relevant to your account.</p></div>
            @if($notifications->contains(fn ($notification) => is_null($notification->read_at)))
                <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button type="submit" style="border:1px solid currentColor;border-radius:9px;background:transparent;color:inherit;padding:9px 12px;font:inherit;font-size:12px;font-weight:700;cursor:pointer">Mark all read</button></form>
            @endif
        </div>
        <div style="overflow:hidden;border:1px solid rgba(125,95,75,.23);border-radius:14px;background:rgba(255,255,255,.5)" @if(auth()->user()->isAccountType(\App\Models\User::TYPE_SELLER)) data-seller-notification-list data-seller-notification-open-template="{{ route('seller.notifications.open', ['notification' => '__id__']) }}" @endif>
            @forelse($notifications as $notification)
                @if(auth()->user()->isAccountType(\App\Models\User::TYPE_SELLER))
                    <form method="POST" action="{{ route('seller.notifications.open', $notification) }}">@csrf
                    <button type="submit" style="display:block;width:100%;border:0;text-align:left;background:transparent;padding:15px 17px;border-bottom:1px solid rgba(125,95,75,.16);color:inherit;font:inherit;cursor:pointer;{{ is_null($notification->read_at) ? 'background:rgba(80,130,180,.10);' : '' }}">
                @else
                    <a href="{{ $notification->action_url ?: '#' }}" style="display:block;padding:15px 17px;border-bottom:1px solid rgba(125,95,75,.16);color:inherit;text-decoration:none;{{ is_null($notification->read_at) ? 'background:rgba(80,130,180,.10);' : '' }}">
                @endif
                    <strong style="display:block;font-size:14px">{{ $notification->title }}</strong>
                    @if($notification->message)<span style="display:block;margin-top:4px;opacity:.72;font-size:13px;line-height:1.45">{{ $notification->message }}</span>@endif
                    <small style="display:block;margin-top:6px;opacity:.55;font-size:11px">{{ optional($notification->created_at)->diffForHumans() }}</small>
                @if(auth()->user()->isAccountType(\App\Models\User::TYPE_SELLER))</button></form>@else</a>@endif
            @empty
                <div style="padding:42px 20px;text-align:center;opacity:.65">You have no notifications yet.</div>
            @endforelse
        </div>
        <div style="margin-top:16px">{{ $notifications->links() }}</div>
    </div>
@endsection
