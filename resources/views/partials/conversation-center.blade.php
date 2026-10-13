@php
    $messageConversations = collect($conversations ?? []);
    $selectedConversationId = (int) request('conversation', 0);
    $activeConversation = $messageConversations->firstWhere('id', $selectedConversationId) ?: $messageConversations->first();
    $activeOther = $activeConversation?->participants?->first(fn ($participant) => (int) $participant->id !== (int) auth()->id());
    $activeOtherName = $activeOther?->name ?: 'Conversation';
    $activeOtherAvatar = 'https://ui-avatars.com/api/?name='.urlencode($activeOtherName).'&background=561C17&color=fff';
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
    .lk-unified-messages .lk-messages-sidebar-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px; border-bottom: 1px solid var(--msg-border); }
    .lk-unified-messages .lk-messages-sidebar-head h2 { margin: 0; color: var(--msg-text); font-size: 14px; font-weight: 900; }
    .lk-unified-messages .lk-conversation-list { min-height: 0; flex: 1; overflow-y: auto; overscroll-behavior: contain; }
    .lk-unified-messages .lk-conversation-item { display: flex; align-items: flex-start; gap: 12px; padding: 15px 18px; border-bottom: 1px solid #EFE1D5; color: var(--msg-text); text-decoration: none; transition: background 160ms ease; }
    .lk-unified-messages .lk-conversation-item:hover { background: #F6EFE7; }
    .lk-unified-messages .lk-conversation-item.is-active { background: #F3E4DE; box-shadow: inset 4px 0 var(--msg-maroon); }
    .lk-unified-messages .lk-chat-avatar { width: 42px; height: 42px; flex: 0 0 42px; border: 2px solid #fff; border-radius: 999px; background: var(--msg-maroon); object-fit: cover; box-shadow: 0 8px 18px rgba(86,28,23,.12); }
    .lk-unified-messages .lk-conversation-copy { min-width: 0; flex: 1; }
    .lk-unified-messages .lk-conversation-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .lk-unified-messages .lk-conversation-name { overflow: hidden; color: var(--msg-text); font-size: 12px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-conversation-time { color: var(--msg-muted-2); font-size: 10px; font-weight: 600; white-space: nowrap; }
    .lk-unified-messages .lk-conversation-preview { overflow: hidden; margin: 4px 0 0; color: var(--msg-muted); font-size: 11px; line-height: 1.45; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-messages-empty { padding: 42px 20px; color: var(--msg-muted); font-size: 12px; text-align: center; }
    .lk-unified-messages .lk-chat-area { display: flex; min-width: 0; min-height: 0; flex-direction: column; overflow: hidden; background: var(--msg-soft); }
    .lk-unified-messages .lk-chat-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 18px; border-bottom: 1px solid var(--msg-border); }
    .lk-unified-messages .lk-chat-user { display: flex; min-width: 0; align-items: center; gap: 12px; }
    .lk-unified-messages .lk-chat-user-copy { min-width: 0; }
    .lk-unified-messages .lk-chat-user-copy strong { display: block; overflow: hidden; color: var(--msg-text); font-size: 14px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .lk-unified-messages .lk-chat-status { display: inline-flex; align-items: center; gap: 6px; margin-top: 3px; color: #256F4A; font-size: 11px; font-weight: 700; }
    .lk-unified-messages .lk-chat-status-dot { width: 8px; height: 8px; border-radius: 999px; background: #256F4A; }
    .lk-unified-messages .lk-chat-status.is-error { color: #9A5B11; }
    .lk-unified-messages .lk-chat-status-dot.is-error { background: #C88418; }
    .lk-unified-messages .lk-chat-stream { min-height: 0; flex: 1; overflow-y: auto; overscroll-behavior: contain; scrollbar-gutter: stable; padding: 20px; background: radial-gradient(circle at 12% 12%,rgba(193,151,113,.10),transparent 28%),linear-gradient(180deg,#FBF7F2,#F6EFE7); }
    .lk-unified-messages .lk-chat-day { display: flex; justify-content: center; margin-bottom: 18px; }
    .lk-unified-messages .lk-chat-day span { display: inline-flex; align-items: center; min-height: 26px; padding: 0 12px; border: 1px solid var(--msg-border); border-radius: 999px; background: rgba(255,253,249,.86); color: var(--msg-muted); font-size: 10px; font-weight: 800; }
    .lk-unified-messages .lk-message-row { display: flex; max-width: 82%; align-items: flex-start; gap: 10px; margin-top: 14px; }
    .lk-unified-messages .lk-message-row.is-mine { justify-content: flex-end; margin-left: auto; }
    .lk-unified-messages .lk-message-small-avatar { width: 30px; height: 30px; flex: 0 0 30px; border-radius: 999px; object-fit: cover; }
    .lk-unified-messages .lk-message-bubble { padding: 13px 14px; border: 1px solid var(--msg-border); border-radius: 18px; border-top-left-radius: 6px; background: var(--msg-card); color: var(--msg-text); box-shadow: 0 8px 20px rgba(86,28,23,.055); font-size: 12px; line-height: 1.65; }
    .lk-unified-messages .lk-message-row.is-mine .lk-message-bubble { border-color: var(--msg-maroon); border-top-left-radius: 18px; border-top-right-radius: 6px; background: linear-gradient(135deg,var(--msg-maroon),var(--msg-maroon-2)); color: #fff; }
    .lk-unified-messages .lk-message-time { display: block; margin-top: 6px; color: var(--msg-muted-2); font-size: 10px; font-weight: 600; }
    .lk-unified-messages .lk-message-row.is-mine .lk-message-time { color: #E8C8B2; text-align: right; }
    .lk-unified-messages .lk-chat-form { display: flex; align-items: center; gap: 10px; padding: 14px; border-top: 1px solid var(--msg-border); }
    .lk-unified-messages .lk-chat-input { min-width: 0; flex: 1; min-height: 42px; padding: 0 15px; border: 1px solid var(--msg-border); border-radius: 14px; background: var(--msg-card); color: var(--msg-text); font-size: 12px; outline: none; }
    .lk-unified-messages .lk-chat-input:focus { border-color: #C19771; box-shadow: 0 0 0 4px rgba(86,28,23,.08); }
    .lk-unified-messages .lk-chat-send { min-height: 42px; padding: 0 16px; border: 0; border-radius: 14px; background: var(--msg-maroon); color: #fff; font-size: 12px; font-weight: 800; cursor: pointer; }
    .lk-unified-messages .lk-chat-empty-state { display: flex; flex: 1; align-items: center; justify-content: center; flex-direction: column; padding: 42px 24px; text-align: center; color: var(--msg-muted); }
    .lk-unified-messages .lk-chat-empty-state svg { width: 58px; height: 58px; margin-bottom: 14px; color: #C19771; }
    .lk-unified-messages .lk-chat-empty-state h3 { margin: 0; color: var(--msg-text); font-size: 16px; font-weight: 900; }
    .lk-unified-messages .lk-chat-empty-state p { margin: 6px 0 0; color: var(--msg-muted); font-size: 12px; }
    .lk-unified-messages .lk-compose-form { display: grid; width: min(100%, 420px); gap: 10px; margin-top: 18px; }
    .lk-unified-messages .lk-compose-form select { min-height: 42px; padding: 0 12px; border: 1px solid var(--msg-border); border-radius: 12px; background: var(--msg-card); color: var(--msg-text); }
    .lk-unified-messages .lk-compose-form textarea { padding: 12px; border: 1px solid var(--msg-border); border-radius: 12px; background: var(--msg-card); color: var(--msg-text); font-size: 12px; resize: vertical; }
    .lk-unified-messages .lk-status-message { padding: 12px 14px; border: 1px solid #B9DEC8; border-radius: 12px; background: #EFFAF2; color: #256F4A; font-size: 12px; font-weight: 700; }
    @media (max-width: 1023px) { .lk-unified-messages .lk-messages-shell { grid-template-columns: minmax(0,1fr); grid-template-rows: minmax(118px,30%) minmax(0,1fr); } }
    @media (max-width: 640px) { .lk-unified-messages .lk-chat-head,.lk-unified-messages .lk-chat-form { align-items: stretch; flex-direction: column; } .lk-unified-messages .lk-chat-send { width: 100%; } .lk-unified-messages .lk-message-row { max-width: 94%; } }
    body:has(.lk-unified-messages) { overflow: hidden; }
    body:has(.lk-unified-messages) .ad-footer,body:has(.lk-unified-messages) .sl-footer { display: none; }
    html.dark .lk-unified-messages .lk-messages-shell,html.dark .lk-unified-messages .lk-messages-sidebar,html.dark .lk-unified-messages .lk-chat-area,html.dark .lk-unified-messages .lk-messages-sidebar-head,html.dark .lk-unified-messages .lk-chat-head,html.dark .lk-unified-messages .lk-chat-form { background: #211B17; border-color: #3B2E27; color: #F5EFE8; }
    html.dark .lk-unified-messages .lk-chat-stream { background: linear-gradient(180deg,#161210,#1E1A17); }
    html.dark .lk-unified-messages .lk-conversation-item { border-color: #3B2E27; }
    html.dark .lk-unified-messages .lk-conversation-item:hover,html.dark .lk-unified-messages .lk-conversation-item.is-active { background: #2D1414; }
    html.dark .lk-unified-messages .lk-conversation-name,html.dark .lk-unified-messages .lk-chat-user-copy strong,html.dark .lk-unified-messages .lk-chat-empty-state h3 { color: #F5EFE8; }
    html.dark .lk-unified-messages .lk-conversation-preview,html.dark .lk-unified-messages .lk-conversation-time,html.dark .lk-unified-messages .lk-message-time,html.dark .lk-unified-messages .lk-chat-empty-state p { color: #C8B7AD; }
    html.dark .lk-unified-messages .lk-message-bubble,html.dark .lk-unified-messages .lk-chat-input,html.dark .lk-unified-messages .lk-chat-day span,html.dark .lk-unified-messages .lk-compose-form select,html.dark .lk-unified-messages .lk-compose-form textarea { background: #211B17; border-color: #3B2E27; color: #F5EFE8; }
</style>
@endonce

@if(session('status'))<div class="lk-status-message">{{ session('status') }}</div>@endif
@if($errors->any())<div class="lk-status-message" style="border-color:#E8B4AE;background:#FFF1EF;color:#8D2D23">{{ $errors->first() }}</div>@endif

<div class="lk-unified-messages" data-current-user-id="{{ auth()->id() }}">
    <section class="lk-messages-shell">
        <aside class="lk-messages-sidebar">
            <div class="lk-messages-sidebar-head"><h2>Conversations ({{ $messageConversations->count() }})</h2></div>
            <div class="lk-conversation-list">
                @forelse($messageConversations as $conversation)
                    @php
                        $other = $conversation->participants->first(fn ($participant) => (int) $participant->id !== (int) auth()->id());
                        $name = $other?->name ?: 'Conversation';
                        $avatar = 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=561C17&color=fff';
                        $isActive = (int) $conversation->id === (int) ($activeConversation?->id);
                    @endphp
                    <a href="{{ url()->current().'?conversation='.$conversation->id }}" class="lk-conversation-item {{ $isActive ? 'is-active' : '' }}">
                        <img class="lk-chat-avatar" src="{{ $avatar }}" alt="{{ $name }}">
                        <div class="lk-conversation-copy">
                            <div class="lk-conversation-top"><strong class="lk-conversation-name">{{ $name }}</strong><span class="lk-conversation-time">{{ $conversation->updated_at?->diffForHumans() }}</span></div>
                            <p class="lk-conversation-preview">{{ $conversation->latestMessage?->body ?: 'Start a conversation.' }}</p>
                        </div>
                    </a>
                @empty
                    <div class="lk-messages-empty">No conversations yet.</div>
                @endforelse
            </div>
        </aside>

        <main class="lk-chat-area">
            @if($activeConversation && $activeOther)
                @php($activeMessages = $activeConversation->messages ?? collect())
                <div class="lk-chat-head">
                    <div class="lk-chat-user"><img class="lk-chat-avatar" src="{{ $activeOtherAvatar }}" alt="{{ $activeOtherName }}"><div class="lk-chat-user-copy"><strong>{{ $activeOtherName }}</strong><span class="lk-chat-status" data-message-connection-status><span class="lk-chat-status-dot"></span>Live conversation</span></div></div>
                </div>
                <div class="lk-chat-stream" data-message-thread data-conversation-id="{{ $activeConversation->id }}" data-current-user-id="{{ auth()->id() }}" data-other-avatar="{{ $activeOtherAvatar }}" data-other-name="{{ $activeOtherName }}" @if($activeStreamUrl) data-stream-url="{{ $activeStreamUrl }}" @endif>
                    <div class="lk-chat-day"><span>Conversation</span></div>
                    @forelse($activeMessages as $message)
                        @php($fromMe = (int) $message->sender_user_id === (int) auth()->id())
                        <div class="lk-message-row {{ $fromMe ? 'is-mine' : '' }}" data-message-id="{{ $message->id }}">
                            @unless($fromMe)<img class="lk-message-small-avatar" src="{{ $activeOtherAvatar }}" alt="{{ $activeOtherName }}">@endunless
                            <div><div class="lk-message-bubble">{{ $message->body }}</div><span class="lk-message-time">{{ ($message->sent_at ?? $message->created_at)?->diffForHumans() }}</span></div>
                        </div>
                    @empty
                        <div class="lk-messages-empty">No messages yet. Send the first message.</div>
                    @endforelse
                </div>
                <form method="POST" action="{{ $sendRoute }}" class="lk-chat-form" data-live-message-form>@csrf<input type="hidden" name="recipient_user_id" value="{{ $activeOther->id }}"><input type="hidden" name="conversation_id" value="{{ $activeConversation->id }}"><input class="lk-chat-input" name="body" required maxlength="5000" autocomplete="off" placeholder="Type your message to {{ $activeOtherName }}..."><button class="lk-chat-send" type="submit">Send</button></form>
            @else
                <div class="lk-chat-empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>
                    <h3>No conversation selected</h3>
                    <p>Choose a recipient to start a conversation.</p>
                    <form method="POST" action="{{ $sendRoute }}" class="lk-compose-form">@csrf<select name="recipient_user_id" required><option value="">Select recipient</option>@foreach($contacts ?? [] as $contact)<option value="{{ $contact->id }}">{{ $contact->name }} — {{ str($contact->account_type)->headline() }}</option>@endforeach</select><textarea name="body" required maxlength="5000" rows="4" placeholder="Type your message"></textarea><button class="lk-chat-send" type="submit">Send Message</button></form>
                </div>
            @endif
        </main>
    </section>
</div>

<script>
document.querySelectorAll('[data-message-thread]').forEach((thread) => { thread.scrollTop = thread.scrollHeight; });
</script>
