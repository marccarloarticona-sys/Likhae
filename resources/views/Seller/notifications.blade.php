@extends('layouts.seller')

@section('title','Notifications')
@section('active','notifications')
@section('subtitle','Review operational alerts, account updates, and marketplace announcements.')

@section('content')
<div class="sl-page sl-page-narrow">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Activity Center</span><h2>Notifications</h2><p>Alerts are now loaded from workspace_notifications.</p></div><form method="POST" action="{{ route('seller.notifications.read-all') }}">@csrf<button type="submit" class="sl-btn sl-btn-ghost">Mark All as Read</button></form></div>
    <section class="sl-notification-layout">
        <nav class="sl-notification-filter sl-card">@foreach(['all'=>'All','orders'=>'Orders','inventory'=>'Inventory','finance'=>'Finance','system'=>'System'] as $key=>$label)<button type="button" class="{{ $loop->first?'is-active':'' }}" data-notification-filter="{{ $key }}">{{ $label }} <span>{{ $notificationCounts[$key] ?? 0 }}</span></button>@endforeach</nav>
        <div class="sl-notification-list" data-notification-list>
            @forelse($notifications as $notification)
                <form method="POST" action="{{ route('seller.notifications.open', $notification) }}" data-notification-form>@csrf<button type="submit" class="sl-notification-item {{ $notification->read_at ? '' : 'is-unread' }}" data-notification data-type="{{ $notification->category }}"><span class="sl-notification-icon is-{{ $notification->category }}">{{ strtoupper(mb_substr($notification->category,0,2)) }}</span><span class="sl-notification-copy"><strong>{{ $notification->title }}</strong><span>{{ $notification->message }}</span><small>{{ $notification->created_at?->diffForHumans() }}</small></span>@if(!$notification->read_at)<i></i>@endif<span class="sl-notification-arrow">&#8250;</span></button></form>
            @empty<div class="sl-empty-state"><h3>No notifications</h3><p>New seller updates will appear here.</p></div>@endforelse
            <div class="sl-empty-state" data-notification-empty hidden><h3>No notifications in this category</h3><p>New seller updates will appear here.</p></div>
        </div>
    </section>
    @if($notifications->hasPages())<div class="sl-card-body">{{ $notifications->links() }}</div>@endif
</div>
@endsection
