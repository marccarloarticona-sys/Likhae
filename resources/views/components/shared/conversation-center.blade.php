@php
    $messageConversations = collect($conversations ?? []);
    $selectedConversationId = (int) request('conversation', 0);
    $activeConversation = $selectedConversationId > 0 ? $messageConversations->firstWhere('id', $selectedConversationId) : null;
    $activeOther = $activeConversation?->participants?->first(fn ($participant) => (int) $participant->id !== (int) auth()->id());
    $activeOtherName = $activeOther?->name ?: 'Conversation';
    $conversationService = app(\App\Services\Communication\ConversationService::class);
    $activeOtherAvatar = $activeOther
        ? $conversationService->avatarUrlFor($activeOther)
        : asset('images/defaults/profile-avatar.svg');
    $activeOtherReadAt = filled($activeOther?->pivot?->last_read_at)
        ? \Illuminate\Support\Carbon::parse($activeOther->pivot->last_read_at)
        : null;
    $activeOtherOnline = $activeOther
        && (bool) data_get($activeOther->notification_preferences, 'show_online_status', true)
        && \Illuminate\Support\Facades\Cache::store('database')->has('message-presence:user:'.$activeOther->id);
    $activeStreamUrl = $activeConversation && filled($streamRoute ?? null)
        ? $streamRoute.(str_contains($streamRoute, '?') ? '&' : '?').'conversation='.$activeConversation->id
        : null;
@endphp

@once
<style>
    .lk-unified-messages { --msg-bg: #FBF7F2; --msg-soft: #F6EFE7; --msg-card: #FFFDF9; --msg-border: #EADCCC; --msg-maroon: #561C17; --msg-maroon-2: #642920; --msg-text: #3B211B; --msg-muted: #987865; --msg-muted-2: #A99386; display: grid; min-height: 0; gap: 18px; }
    .lk-unified-messages .lk-messages-shell { display: grid; height: max(280px, min(720px, calc(100dvh - 205px))); min-height: 0; overflow: hidden; border: 1px solid var(--msg-border); border-radius: 24px; background: var(--msg-card); box-shadow: 0 8px 24px rgba(86,28,23,.055); }
    @media (min-width: 1024px) { .lk-unified-messages .lk-messages-shell { grid-template-columns: 330px minmax(0,1fr); } }
    .lk-unified-messages .lk-messages-sidebar { display: flex; min-width: 0; min-height: 0; flex-direction: column; border-bottom: 1px solid var(--msg-border); background: radial-gradient(circle at 0 0,rgba(193,151,113,.16),transparent 32%),linear-gradient(180deg,#FFFDF9,#F8F0E8); }
    @media (min-width: 1024px) { .lk-unified-messages .lk-messages-sidebar { border-right: 1px solid var(--msg-border); border-bottom: 0; } }
    .lk-unified-messages .lk-messages-sidebar-head,.lk-unified-messages .lk-chat-head,.lk-unified-messages .lk-chat-form { border-color: var(--msg-border); background: rgba(255,253,249,.92); }
    .lk-unified-messages .lk-chat-head,.lk-unified-messages .lk-chat-form { flex: 0 0 auto; }
    .lk-unified-messages .lk-message-context { padding: 10px 16px; border-bottom: 1px solid var(--msg-border); background: var(--msg-card); color: var(--msg-text); font-size: 11px; line-height: 1.5; }
    .lk-unified-messages .lk-message-context strong { font-weight: 900; }
    .lk-unified-messages .lk-messages-sidebar-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px; border-bottom: 1px solid var(--msg-border); }
    .lk-unified-messages .lk-messages-sidebar-head h2 { margin: 0; color: var(--msg-text); font-size: 14px; font-weight: 900; }
    .lk-unified-messages .lk-conversation-list { min-height: 0; flex: 1; overflow-y: auto; overscroll-behavior: contain; }
    .lk-unified-messages .lk-conversation-item { display: flex; align-items: flex-start; gap: 12px; padding: 15px 18px; border-bottom: 1px solid #EFE1D5; color: var(--msg-text); text-decoration: none; transition: background 160ms ease; }
    .lk-unified-messages .lk-conversation-item:hover { background: #F6EFE7; }
    .lk-unified-messages .lk-conversation-item.is-active { background: #F3E4DE; box-shadow: inset 4px 0 var(--msg-maroon); }
    .lk-unified-messages .lk-chat-avatar { width: 42px; height: 42px; flex: 0 0 42px; border: 2px solid #fff; border-radius: 999px; background: var(--msg-maroon); object-fit: cover; box-shadow: 0 8px 18px rgba(86,28,23,.12); }
    .lk-unified-messages .lk-conversation-avatar-wrap { position: relative; width: 42px; height: 42px; flex: 0 0 42px; }
    .lk-unified-messages .lk-conversation-avatar-wrap .lk-chat-avatar { display: block; }
    .lk-unified-messages .lk-conversation-presence-dot { position: absolute; right: 0; bottom: 0; width: 12px; height: 12px; border: 2px solid #FFFDF9; border-radius: 999px; background: #A99E98; }
    .lk-unified-messages .lk-conversation-presence-dot.is-online { background: #2EAA60; }
    .lk-unified-messages .lk-conversation-copy { min-width: 0; flex: 1; }
    .lk-unified-messages .lk-conversation-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .lk-unified-messages .lk-conversation-name { overflow: hidden; color: var(--msg-text); font-size: 12px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-conversation-time { color: var(--msg-muted-2); font-size: 10px; font-weight: 600; white-space: nowrap; }
    .lk-unified-messages .lk-conversation-unread { display: inline-flex; min-width: 18px; height: 18px; align-items: center; justify-content: center; padding: 0 5px; border-radius: 999px; background: var(--msg-maroon); color: #fff; font-size: 10px; font-weight: 900; }
    .lk-unified-messages .lk-conversation-preview { overflow: hidden; margin: 4px 0 0; color: var(--msg-muted); font-size: 11px; line-height: 1.45; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-messages-empty { padding: 42px 20px; color: var(--msg-muted); font-size: 12px; text-align: center; }
    .lk-unified-messages .lk-chat-area { display: flex; min-width: 0; min-height: 0; flex-direction: column; overflow: hidden; background: var(--msg-soft); }
    .lk-unified-messages .lk-chat-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 18px; border-bottom: 1px solid var(--msg-border); }
    .lk-unified-messages .lk-chat-back { display: none; align-items: center; justify-content: center; gap: 5px; min-height: 38px; padding: 0 9px; border: 1px solid var(--msg-border); border-radius: 11px; background: var(--msg-card); color: var(--msg-maroon); font: 700 11px/1 Poppins,system-ui,sans-serif; cursor: pointer; }
    .lk-unified-messages .lk-chat-back svg { width: 16px; height: 16px; }
    .lk-unified-messages .lk-chat-user { display: flex; min-width: 0; align-items: center; gap: 12px; }
    .lk-unified-messages .lk-chat-user-copy { min-width: 0; }
    .lk-unified-messages .lk-chat-user-copy strong { display: block; overflow: hidden; color: var(--msg-text); font-size: 14px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-chat-status { display: inline-flex; align-items: center; gap: 6px; margin-top: 3px; color: #256F4A; font-size: 11px; font-weight: 700; }
    .lk-unified-messages .lk-chat-status-dot { width: 8px; height: 8px; border-radius: 999px; background: #256F4A; }
    .lk-unified-messages .lk-chat-status.is-offline { color: #7B716B; }
    .lk-unified-messages .lk-chat-status.is-offline .lk-chat-status-dot { background: #A99E98; }
    .lk-unified-messages .lk-chat-feedback { margin: 0; padding: 7px 18px; color: var(--msg-muted); font-size: 11px; }
    .lk-unified-messages .lk-chat-feedback.is-error { color: #9A5B11; }
    .lk-unified-messages .lk-chat-stream { min-height: 0; flex: 1; overflow-y: auto; overscroll-behavior: contain; scrollbar-gutter: stable; padding: 20px; background: radial-gradient(circle at 12% 12%,rgba(193,151,113,.10),transparent 28%),linear-gradient(180deg,#FBF7F2,#F6EFE7); }
    .lk-unified-messages .lk-chat-day { display: flex; justify-content: center; margin-bottom: 18px; }
    .lk-unified-messages .lk-chat-day span { display: inline-flex; align-items: center; min-height: 26px; padding: 0 12px; border: 1px solid var(--msg-border); border-radius: 999px; background: rgba(255,253,249,.86); color: var(--msg-muted); font-size: 10px; font-weight: 800; }
    .lk-unified-messages .lk-message-row { display: flex; max-width: 82%; align-items: flex-start; gap: 10px; margin-top: 14px; }
    .lk-unified-messages .lk-message-row.is-mine { justify-content: flex-end; margin-left: auto; }
    .lk-unified-messages .lk-message-small-avatar { width: 30px; height: 30px; flex: 0 0 30px; border-radius: 999px; object-fit: cover; }
    .lk-unified-messages .lk-message-bubble { padding: 13px 14px; border: 1px solid var(--msg-border); border-radius: 18px; border-top-left-radius: 6px; background: var(--msg-card); color: var(--msg-text); box-shadow: 0 8px 20px rgba(86,28,23,.055); font-size: 12px; line-height: 1.65; white-space: pre-wrap; overflow-wrap: anywhere; }
    .lk-unified-messages .lk-message-row.is-mine .lk-message-bubble { border-color: var(--msg-maroon); border-top-left-radius: 18px; border-top-right-radius: 6px; background: linear-gradient(135deg,var(--msg-maroon),var(--msg-maroon-2)); color: #fff; }
    .lk-unified-messages .lk-message-time { display: block; margin-top: 6px; color: var(--msg-muted-2); font-size: 10px; font-weight: 600; }
    .lk-unified-messages .lk-message-row.is-mine .lk-message-time { color: #D6A58D; text-align: right; }
    .lk-unified-messages .lk-message-meta { display: flex; align-items: center; justify-content: flex-start; gap: 7px; margin-top: 6px; }
    .lk-unified-messages .lk-message-meta .lk-message-time { margin-top: 0; }
    .lk-unified-messages .lk-message-row.is-mine .lk-message-meta { justify-content: flex-end; }
    .lk-unified-messages .lk-message-receipt { display: inline-flex; align-items: center; gap: 4px; color: var(--msg-muted-2); font-size: 10px; font-weight: 700; line-height: 1; }
    .lk-unified-messages .lk-message-row.is-mine .lk-message-receipt { color: #D6A58D; }
    .lk-unified-messages .lk-message-seen-avatar { width: 16px; height: 16px; border: 1px solid rgba(255,255,255,.9); border-radius: 999px; object-fit: cover; }
    .lk-unified-messages .lk-chat-form { display: flex; align-items: center; gap: 10px; padding: 14px; border-top: 1px solid var(--msg-border); }
    .lk-unified-messages .lk-chat-input { min-width: 0; flex: 1; min-height: 42px; padding: 0 15px; border: 1px solid var(--msg-border); border-radius: 14px; background: var(--msg-card); color: var(--msg-text); font-size: 12px; outline: none; }
    .lk-unified-messages .lk-chat-input:focus { border-color: #C19771; box-shadow: 0 0 0 4px rgba(86,28,23,.08); }
    .lk-unified-messages .lk-chat-send { min-height: 42px; padding: 0 16px; border: 0; border-radius: 14px; background: var(--msg-maroon); color: #fff; font-size: 12px; font-weight: 800; cursor: pointer; }
    .lk-unified-messages .lk-message-attachment { position: relative; display: inline-flex; width: 42px; height: 42px; flex: 0 0 42px; align-items: center; justify-content: center; border: 1px solid var(--msg-border); border-radius: 13px; background: var(--msg-card); color: var(--msg-maroon); cursor: pointer; transition: background 150ms ease,border-color 150ms ease,transform 150ms ease; }
    .lk-unified-messages .lk-message-attachment:hover { transform: translateY(-1px); border-color: #C19771; background: #FBF7F2; }
    .lk-unified-messages .lk-message-attachment svg { width: 20px; height: 20px; }
    .lk-unified-messages .lk-message-attachment input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
    .lk-unified-messages .lk-attachment-selection { display: inline-flex; min-width: 0; align-items: center; gap: 7px; }
    .lk-unified-messages [data-attachment-preview] { display: block; max-width: 76px; max-height: 54px; border-radius: 8px; object-fit: cover; }
    .lk-unified-messages [data-attachment-preview][hidden] { display: none; }
    .lk-unified-messages [data-attachment-filename] { max-width: 150px; overflow: hidden; color: var(--msg-muted); font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages [data-attachment-filename][hidden] { display: none; }
    .lk-unified-messages .lk-message-image-attachment { display: block; max-width: min(280px,75vw); max-height: 300px; margin-top: 8px; border-radius: 12px; object-fit: contain; }
    .lk-unified-messages .lk-message-file { display: flex; max-width: min(320px,75vw); align-items: center; gap: 10px; margin-top: 8px; padding: 10px 12px; border: 1px solid var(--msg-border); border-radius: 12px; background: var(--msg-card); color: var(--msg-text); text-decoration: none; }
    .lk-unified-messages .lk-message-file:hover { border-color: #C19771; }
    .lk-unified-messages .lk-message-file > svg { width: 22px; height: 22px; flex: 0 0 22px; color: var(--msg-maroon-2); }
    .lk-unified-messages .lk-message-file-copy { display: grid; min-width: 0; flex: 1; gap: 2px; }
    .lk-unified-messages .lk-message-file-copy strong { overflow: hidden; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-message-file-copy small { color: var(--msg-muted); font-size: 9px; font-weight: 700; }
    .lk-unified-messages .lk-message-file-download { font-size: 16px; font-weight: 900; }
    .lk-unified-messages .lk-chat-empty-state { display: flex; flex: 1; align-items: center; justify-content: center; flex-direction: column; padding: 42px 24px; text-align: center; color: var(--msg-muted); }
    .lk-unified-messages .lk-chat-empty-state svg { width: 58px; height: 58px; margin-bottom: 14px; color: #C19771; }
    .lk-unified-messages .lk-chat-empty-state h3 { margin: 0; color: var(--msg-text); font-size: 16px; font-weight: 900; }
    .lk-unified-messages .lk-chat-empty-state p { margin: 6px 0 0; color: var(--msg-muted); font-size: 12px; }
    .lk-unified-messages .lk-compose-form { display: grid; width: min(100%, 420px); gap: 10px; margin-top: 18px; }
    .lk-unified-messages .lk-compose-form select { min-height: 42px; padding: 0 12px; border: 1px solid var(--msg-border); border-radius: 12px; background: var(--msg-card); color: var(--msg-text); }
    .lk-unified-messages .lk-compose-form textarea { padding: 12px; border: 1px solid var(--msg-border); border-radius: 12px; background: var(--msg-card); color: var(--msg-text); font-size: 12px; resize: vertical; }
    .lk-unified-messages .lk-status-message { padding: 12px 14px; border: 1px solid #B9DEC8; border-radius: 12px; background: #EFFAF2; color: #256F4A; font-size: 12px; font-weight: 700; }
    @media (max-width: 1023px) { .lk-unified-messages .lk-messages-shell { grid-template-columns: minmax(210px,30%) minmax(0,1fr); grid-template-rows: minmax(0,1fr); } .lk-unified-messages .lk-messages-sidebar { border-right: 1px solid var(--msg-border); border-bottom: 0; } }
    @media (max-width: 767px) {
        .lk-unified-messages .lk-messages-shell { height: calc(100dvh - 260px); min-height: 260px; grid-template-columns: minmax(0,1fr); grid-template-rows: minmax(0,1fr); border-radius: 18px; }
        .lk-unified-messages.is-mobile-chat-view .lk-messages-sidebar,.lk-unified-messages.is-mobile-list-view .lk-chat-area { display: none; }
        .lk-unified-messages.is-mobile-chat-view .lk-chat-area,.lk-unified-messages.is-mobile-list-view .lk-messages-sidebar { display: flex; }
        .lk-unified-messages .lk-chat-head { align-items: center; flex-direction: row; gap: 8px; padding: 10px; }
        .lk-unified-messages .lk-chat-back { display: inline-flex; flex: 0 0 auto; }
        .lk-unified-messages .lk-chat-user { flex: 1; gap: 8px; }
        .lk-unified-messages .lk-chat-head .lk-chat-avatar { width: 36px; height: 36px; flex-basis: 36px; }
        .lk-unified-messages .lk-chat-user-copy strong { font-size: 12px; }
        .lk-unified-messages .lk-chat-status { font-size: 10px; }
        .lk-unified-messages .lk-chat-form { display: grid; grid-template-columns: minmax(0,1fr) auto auto; align-items: center; gap: 8px; padding: 10px; }
        .lk-unified-messages .lk-chat-input { grid-column: 1/-1; width: 100%; min-height: 42px; }
        .lk-unified-messages .lk-chat-form > span:has([data-message-count]) { grid-column: 1; grid-row: 2; font-size: 10px; white-space: nowrap; }
        .lk-unified-messages .lk-attachment-selection { grid-column: 2; grid-row: 2; }
        .lk-unified-messages .lk-chat-send { grid-column: 3; grid-row: 2; width: auto; min-width: 88px; min-height: 42px; padding-inline: 12px; }
        .lk-unified-messages .lk-message-row { max-width: 94%; }
    }
    body:has(.lk-unified-messages) { overflow: hidden; }
    body:has(.lk-unified-messages) .ad-footer,body:has(.lk-unified-messages) .sl-footer { display: none; }
    html.dark .lk-unified-messages .lk-messages-shell,html.dark .lk-unified-messages .lk-messages-sidebar,html.dark .lk-unified-messages .lk-chat-area,html.dark .lk-unified-messages .lk-messages-sidebar-head,html.dark .lk-unified-messages .lk-chat-head,html.dark .lk-unified-messages .lk-chat-form { background: #211B17; border-color: #3B2E27; color: #F5EFE8; }
    html.dark .lk-unified-messages .lk-chat-stream { background: linear-gradient(180deg,#161210,#1E1A17); }
    html.dark .lk-unified-messages .lk-conversation-item { border-color: #3B2E27; }
    html.dark .lk-unified-messages .lk-conversation-item:hover,html.dark .lk-unified-messages .lk-conversation-item.is-active { background: #2D1414; }
    html.dark .lk-unified-messages .lk-conversation-name,html.dark .lk-unified-messages .lk-chat-user-copy strong,html.dark .lk-unified-messages .lk-chat-empty-state h3 { color: #F5EFE8; }
    html.dark .lk-unified-messages .lk-conversation-preview,html.dark .lk-unified-messages .lk-conversation-time,html.dark .lk-unified-messages .lk-message-time,html.dark .lk-unified-messages .lk-chat-empty-state p { color: #C8B7AD; }
    html.dark .lk-unified-messages .lk-message-row.is-mine .lk-message-time,html.dark .lk-unified-messages .lk-message-row.is-mine .lk-message-receipt { color: #F5DDD2; }
    html.dark .lk-unified-messages .lk-message-bubble,html.dark .lk-unified-messages .lk-chat-input,html.dark .lk-unified-messages .lk-chat-day span,html.dark .lk-unified-messages .lk-compose-form select,html.dark .lk-unified-messages .lk-compose-form textarea { background: #211B17; border-color: #3B2E27; color: #F5EFE8; }
</style>
@endonce

@if(session('status'))<div class="lk-status-message">{{ session('status') }}</div>@endif
@if($errors->any())<div class="lk-status-message" style="border-color:#E8B4AE;background:#FFF1EF;color:#8D2D23">{{ $errors->first() }}</div>@endif

<div class="lk-unified-messages {{ ($activeConversation && $activeOther) || $messageConversations->isEmpty() ? 'is-mobile-chat-view' : 'is-mobile-list-view' }}" data-current-user-id="{{ auth()->id() }}" data-mobile-unified-messages>
    <section class="lk-messages-shell">
        <aside class="lk-messages-sidebar">
            <div class="lk-messages-sidebar-head"><h2>Conversations ({{ $messageConversations->count() }})</h2></div>
            <label class="lk-conversation-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 4.5 4.5"></path></svg>
                <input type="search" placeholder="Search by name…" aria-label="Search conversations by name" data-conversation-search>
            </label>
            <div class="lk-conversation-list">
                <div class="lk-messages-search-empty" data-conversation-search-empty hidden>No conversations match that name.</div>
                @forelse($messageConversations as $conversation)
                    @php
                        $other = $conversation->participants->first(fn ($participant) => (int) $participant->id !== (int) auth()->id());
                        $name = $other?->name ?: 'Conversation';
                        $avatar = $other ? $conversationService->avatarUrlFor($other) : asset('images/defaults/profile-avatar.svg');
                        $isActive = (int) $conversation->id === (int) ($activeConversation?->id);
                        $messagePreview = $conversationService->messagePreviewFor($conversation->latestMessage);
                    @endphp
                    <a href="{{ url()->current().'?conversation='.$conversation->id }}" class="lk-conversation-item {{ $isActive ? 'is-active' : '' }}" data-conversation-name="{{ $name }}" data-conversation-presence-id="{{ $conversation->id }}" data-presence-url="{{ route('messages.receipt', ['conversation' => $conversation->id]) }}">
                        @php
                            $initiallyOnline = $isActive && $activeOtherOnline;
                        @endphp
                        <span class="lk-conversation-avatar-wrap"><img class="lk-chat-avatar" src="{{ $avatar }}" alt="{{ $name }}" loading="{{ $isActive ? 'eager' : 'lazy' }}" decoding="async" fetchpriority="{{ $isActive ? 'high' : 'low' }}"><span class="lk-conversation-presence-dot {{ $initiallyOnline ? 'is-online' : '' }}" data-conversation-presence-dot role="img" aria-label="{{ $initiallyOnline ? 'Online' : 'Offline' }}" title="{{ $initiallyOnline ? 'Online' : 'Offline' }}"></span></span>
                        <div class="lk-conversation-copy">
                            <div class="lk-conversation-top"><strong class="lk-conversation-name">{{ $name }}</strong><span class="lk-conversation-time">{{ $conversation->latestMessage?->sent_at?->diffForHumans() ?? $conversation->updated_at?->diffForHumans() }}</span></div>
                            <p class="lk-conversation-preview">{{ $messagePreview }}</p>
                            @if(($conversation->unread_count ?? 0) > 0)<span class="lk-conversation-unread" data-conversation-unread aria-label="{{ $conversation->unread_count }} unread messages">{{ $conversation->unread_count }}</span>@endif
                        </div>
                    </a>
                @empty
                    <div class="lk-messages-empty">No conversations yet.</div>
                @endforelse
            </div>
        </aside>

        <main class="lk-chat-area">
            @if($activeConversation && $activeOther)
                @php
                    $activeMessages = $activeConversation->messages ?? collect();
                @endphp
                <div class="lk-chat-head">
                    <button type="button" class="lk-chat-back" data-mobile-conversation-list aria-label="Back to conversations" title="Back to conversations"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg><span>Chats</span></button>
                    <div class="lk-chat-user"><img class="lk-chat-avatar" src="{{ $activeOtherAvatar }}" alt="{{ $activeOtherName }}" decoding="async" fetchpriority="high"><div class="lk-chat-user-copy"><strong>{{ $activeOtherName }}</strong><span class="lk-chat-status {{ $activeOtherOnline ? '' : 'is-offline' }}" data-message-presence-status data-presence="{{ $activeOtherOnline ? 'online' : 'offline' }}"><span class="lk-chat-status-dot"></span><span data-message-presence-label>{{ $activeOtherOnline ? 'Online' : 'Offline' }}</span></span></div></div>
                </div>
                <p class="lk-chat-feedback" data-message-feedback role="status" aria-live="polite" hidden></p>
                @if(!empty($messageContext))
                    <div class="lk-message-context" aria-label="{{ $messageContext['heading'] }}">
                        <strong>{{ $messageContext['heading'] }}</strong>
                        @if(isset($messageContext['tracking']))<span> · {{ $messageContext['tracking'] }} · {{ $messageContext['stage'] }}</span>@endif
                        @if(isset($messageContext['order_number']))<span> · {{ $messageContext['order_number'] }} · {{ $messageContext['status'] }}</span>@endif
                        @if(!empty($messageContext['seller']))<span> · Seller: {{ $messageContext['seller'] }}</span>@endif
                        @if(!empty($messageContext['buyer']))<span> · Recipient: {{ $messageContext['buyer'] }}</span>@endif
                        @if(!empty($messageContext['rider']))<span> · Assigned rider: {{ $messageContext['rider'] }}</span>@endif
                        @if(!empty($messageContext['items']))<div>Items: {{ implode(', ', $messageContext['items']) }}</div>@endif
                    </div>
                @endif
                <div class="lk-chat-stream" data-message-thread data-conversation-id="{{ $activeConversation->id }}" data-current-user-id="{{ auth()->id() }}" data-other-user-id="{{ $activeOther->id }}" data-other-avatar="{{ $activeOtherAvatar }}" data-other-name="{{ $activeOtherName }}" data-other-last-read-at="{{ $activeOtherReadAt?->toIso8601String() }}" data-read-url="{{ route('messages.read', ['conversation' => $activeConversation->id]) }}" data-read-url-template="{{ route('messages.read', ['conversation' => '__conversation__']) }}" data-receipt-url="{{ route('messages.receipt', ['conversation' => $activeConversation->id]) }}" data-receipt-url-template="{{ route('messages.receipt', ['conversation' => '__conversation__']) }}" @if($messageAttachmentsReady ?? $sellerMessageAttachments ?? false) data-attachments-enabled="true" @endif @if($activeStreamUrl) data-stream-url="{{ $activeStreamUrl }}" @endif>
                    <div class="lk-chat-day"><span>Conversation</span></div>
                    @forelse($activeMessages as $message)
                        @php
                            $fromMe = (int) $message->sender_user_id === (int) auth()->id();
                        @endphp
                        @php
                            $messageSentAt = $message->sent_at ?? $message->created_at;
                            $wasSeen = $fromMe && $activeOtherReadAt && $messageSentAt && $messageSentAt->lte($activeOtherReadAt);
                        @endphp
                        <div class="lk-message-row {{ $fromMe ? 'is-mine' : '' }}" data-message-id="{{ $message->id }}" data-message-sender-id="{{ $message->sender_user_id }}" @if($messageSentAt) data-message-sent-at="{{ $messageSentAt->toIso8601String() }}" @endif>
                            @unless($fromMe)<img class="lk-message-small-avatar" src="{{ $activeOtherAvatar }}" alt="{{ $activeOtherName }}" loading="lazy" decoding="async">@endunless
                            <div>@if(filled($message->body) && !(filled($message->attachment_path) && in_array(trim((string)$message->body), ['[Photo]', '[File]'], true)))<div class="lk-message-bubble">{{ $message->body }}</div>@endif @if(($messageAttachmentsReady ?? $sellerMessageAttachments ?? false) && filled($message->attachment_path))@include('components.shared.message-attachment',['message'=>$message])@endif<div class="lk-message-meta"><span class="lk-message-time">{{ $messageSentAt?->diffForHumans() }}</span>@if($fromMe)<span class="lk-message-receipt" data-message-receipt data-message-sent-at="{{ $messageSentAt?->toIso8601String() }}" data-receipt-state="{{ $wasSeen ? 'seen' : 'sent' }}"><span data-message-receipt-text>{{ $wasSeen ? 'Seen' : 'Sent' }}</span>@if($wasSeen)<img class="lk-message-seen-avatar" data-message-receipt-avatar src="{{ $activeOtherAvatar }}" alt="" title="Seen by {{ $activeOtherName }}">@endif</span>@endif</div></div>
                        </div>
                    @empty
                        <div class="lk-messages-empty">No messages yet. Send the first message.</div>
                    @endforelse
                </div>
                <form method="POST" enctype="multipart/form-data" action="{{ $sendRoute }}" class="lk-chat-form" data-live-message-form>@csrf<input type="hidden" name="recipient_user_id" value="{{ $activeOther->id }}"><input type="hidden" name="conversation_id" value="{{ $activeConversation->id }}"><input class="lk-chat-input" name="body" maxlength="1000" data-message-body autocomplete="off" placeholder="Type your message to {{ $activeOtherName }}..."><span class="text-xs text-stone-500"><span data-message-count>0</span>/1000</span>@if($messageAttachmentsReady ?? $sellerMessageAttachments ?? false)<span class="lk-attachment-selection"><label class="lk-message-attachment" title="Attach up to 5 files or images" aria-label="Attach up to 5 files or images"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/><path d="M15 3v4h4"/></svg><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.mp4,.mov" data-message-attachment-input><span class="sr-only">Attach up to 5 files or images</span></label><img data-attachment-preview hidden alt="Selected image preview"><span data-attachment-filename hidden></span></span>@endif<button class="lk-chat-send" type="submit">Send</button></form>
            @else
                <div class="lk-chat-empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>
                    <h3>New conversation</h3>
                    <p>Choose a recipient to start a conversation, or select a thread from the list.</p>
                    <form method="POST" enctype="multipart/form-data" action="{{ $sendRoute }}" class="lk-compose-form">@csrf<select name="recipient_user_id" required><option value="">Select recipient</option>@foreach($contacts ?? [] as $contact)<option value="{{ $contact->id }}">{{ $contact->name }} ({{ str($contact->account_type)->headline() }})</option>@endforeach</select><textarea name="body" maxlength="1000" data-message-body rows="4" placeholder="Type your message">{{ old('body') }}</textarea><span class="text-xs text-stone-500"><span data-message-count>0</span>/1000</span>@if($messageAttachmentsReady ?? $sellerMessageAttachments ?? false)<span class="lk-attachment-selection"><label class="lk-message-attachment" title="Attach up to 5 files or images" aria-label="Attach up to 5 files or images"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/><path d="M15 3v4h4"/></svg><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.mp4,.mov" data-message-attachment-input><span class="sr-only">Attach up to 5 files or images</span></label><img data-attachment-preview hidden alt="Selected image preview"><span data-attachment-filename hidden></span></span>@endif<button class="lk-chat-send" type="submit">Send Message</button></form>
                </div>
            @endif
        </main>
    </section>
</div>

<script>
document.querySelectorAll('[data-message-thread]').forEach((thread) => { thread.scrollTop = thread.scrollHeight; });
document.querySelectorAll('[data-mobile-unified-messages] [data-mobile-conversation-list]').forEach((button) => button.addEventListener('click', () => {
    const page = button.closest('[data-mobile-unified-messages]');
    page?.classList.remove('is-mobile-chat-view');
    page?.classList.add('is-mobile-list-view');
    page?.querySelector('.lk-conversation-item.is-active')?.focus();
}));
document.querySelectorAll('[data-message-body]').forEach((input) => {
    const counter = input.closest('form')?.querySelector('[data-message-count]');
    const updateCount = () => { if (counter) counter.textContent = String([...input.value].length); };
    input.addEventListener('input', updateCount);
    updateCount();
});
document.querySelectorAll('[data-message-attachment-input]').forEach((input) => input.addEventListener('change', () => {
    const preview = input.closest('form')?.querySelector('[data-attachment-preview]');
    const filename = input.closest('form')?.querySelector('[data-attachment-filename]');
    const files = Array.from(input.files || []);
    if (preview?.dataset.previewUrl) URL.revokeObjectURL(preview.dataset.previewUrl);
    if (!files.length) {
        if (preview) {
            delete preview.dataset.previewUrl;
            preview.hidden = true;
            preview.removeAttribute('src');
        }
        if (filename) {
            filename.hidden = true;
            filename.textContent = '';
        }
        return;
    }
    if (files.length > 5) {
        if (preview) {
            delete preview.dataset.previewUrl;
            preview.hidden = true;
            preview.removeAttribute('src');
        }
        if (filename) {
            filename.textContent = 'Choose up to 5 files or images';
            filename.hidden = false;
        }
        return;
    }
    const firstImage = files.find((file) => file.type.startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(file.name));
    if (firstImage && preview) {
        preview.dataset.previewUrl = URL.createObjectURL(firstImage);
        preview.src = preview.dataset.previewUrl;
        preview.hidden = false;
    } else {
        if (preview) {
            delete preview.dataset.previewUrl;
            preview.hidden = true;
            preview.removeAttribute('src');
        }
    }
    if (filename) {
        filename.textContent = files.length === 1 ? files[0].name : `${files.length} attachments selected`;
        filename.hidden = false;
    }
}));
</script>
