@extends('layouts.buyer')

@section('title', 'Notifications')
@section('active', 'account')
@section('subtitle', 'Order, message, reward, and account updates.')

@section('content')
@php
    $type = request('type', 'all');
    $notificationSource = $dbNotifications ?? $notifications ?? [];
    $notificationRows = $notificationSource instanceof \Illuminate\Pagination\AbstractPaginator
        ? collect($notificationSource->items())
        : collect($notificationSource);
    $notificationVersion = sha1($notificationRows->take(20)->map(fn ($notification) => [
        'id' => (int) $notification->id,
        'read_at' => $notification->read_at?->toIso8601String(),
        'updated_at' => $notification->updated_at?->toIso8601String(),
    ])->values()->toJson());
    $notifications = $notificationRows->map(function ($notification) {
        $kind = in_array($notification->type, ['orders','messages','rewards','account','stores','announcements'], true) ? $notification->type : (strtoupper((string) $notification->type) === 'ANNOUNCEMENT' ? 'announcements' : (str_starts_with((string) $notification->type, 'STORE_') ? 'stores' : (str_contains((string) $notification->type, 'message') ? 'messages' : 'orders')));
        return [
            'id' => $notification->id,
            'type' => $kind,
            'priority' => strtoupper((string) $notification->type) === 'ANNOUNCEMENT',
            'title' => $notification->title,
            'message' => $notification->message ?? $notification->body,
            'time' => $notification->created_at?->diffForHumans() ?: '',
            'unread' => $notification->read_at === null,
            'open_url' => route('buyer.notifications.open', $notification),
        ];
    });
    $visible = $type === 'all' ? $notifications : $notifications->where('type', $type);
    $types = ['all'=>'All','orders'=>'Orders','messages'=>'Messages','stores'=>'Stores','rewards'=>'Rewards','account'=>'Account','announcements'=>'Announcements'];
    $icons = ['orders'=>'□','messages'=>'✉','stores'=>'◈','rewards'=>'%','account'=>'○'];
    $icons['announcements'] = '!';
@endphp

<div class="lk-page-narrow" data-buyer-notifications-live data-notification-stream-url="{{ route('buyer.notifications.stream') }}" data-notification-version="{{ $notificationVersion }}">
    <div class="lk-page-title"><div><span class="lk-kicker">Notification Center</span><h1>Notifications</h1><p>Important activity from your LIKHAE account.</p></div><form method="POST" action="{{ route('buyer.notifications.read-all') }}">@csrf<button type="submit" class="lk-btn lk-btn-light">Mark all as read</button></form></div>

    <nav class="flex gap-1 overflow-x-auto rounded-xl border border-stone-200 bg-white p-1 shadow-sm" aria-label="Notification filters">@foreach($types as $key=>$label)<a href="{{ route('buyer.notifications',['type'=>$key]) }}" class="whitespace-nowrap rounded-lg px-3.5 py-2.5 text-xs font-semibold {{ $type === $key ? 'bg-red-900 text-white' : 'text-stone-600 hover:bg-stone-50' }}">{{ $label }}@if($key==='all') <span class="ml-1 opacity-70" data-notification-unread-count>{{ $notifications->where('unread',true)->count() }}</span>@endif</a>@endforeach</nav>

    <section class="mt-5 overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-notification-list>
        @forelse($visible as $notification)
            <a href="{{ $notification['open_url'] }}" class="flex gap-3 border-b border-stone-100 p-4 transition last:border-0 hover:bg-stone-50 sm:p-5 {{ $notification['unread'] ? 'bg-red-50/40' : '' }}" data-notification-item data-notification-type="{{ $notification['type'] }}">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $notification['unread'] ? 'bg-red-900 text-white' : 'bg-stone-100 text-stone-500' }} text-sm font-bold">{{ $icons[$notification['type']] ?? '•' }}</span>
                <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center justify-between gap-2"><span class="flex min-w-0 flex-wrap items-center gap-2"><strong class="text-sm text-stone-900">{{ $notification['title'] }}</strong>@if($notification['priority'])<span class="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">Priority</span>@endif</span><time class="text-[10px] text-stone-400">{{ $notification['time'] }}</time></span><span class="mt-1 block text-xs leading-5 text-stone-500">{{ $notification['message'] }}</span></span>
                @if($notification['unread'])<i class="mt-2 h-2 w-2 shrink-0 rounded-full bg-red-800" aria-label="Unread"></i>@endif
            </a>
        @empty
            <div class="p-5"><x-buyer.empty-state title="No notifications" message="There are no updates in this section." /></div>
        @endforelse
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('[data-mark-all-read]')?.addEventListener('click', () => {
        document.querySelectorAll('[data-notification-item]').forEach((item) => {
            item.classList.remove('bg-red-50/40');
            item.querySelector('[aria-label="Unread"]')?.remove();
        });
        window.lkBuyerToast?.('All visible notifications marked as read.');
    });
});
</script>
@endpush
