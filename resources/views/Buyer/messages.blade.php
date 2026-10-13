@extends('layouts.buyer')

@section('title', 'Messages')
@section('active', 'messages')

@php
    $buyerProducts = collect($buyerProducts ?? []);
    $sellers = collect($conversationRows ?? []);
    $selectedSlug = request('seller');
    $dbActiveSeller = $dbActiveSeller ?? null;
    if (!$selectedSlug && $dbActiveSeller) {
        $activeName = $dbActiveSeller->store_name ?: $dbActiveSeller->business_name ?: $dbActiveSeller->name;
        $selectedSlug = \Illuminate\Support\Str::slug($activeName).'-'.$dbActiveSeller->id;
    }
    $activeSeller = $sellers->firstWhere('slug', $selectedSlug) ?: $sellers->first();
    if (!$activeSeller && $dbActiveSeller) {
        $activeName = $dbActiveSeller->store_name ?: $dbActiveSeller->business_name ?: $dbActiveSeller->name;
        $activeSeller = [
            'id' => $dbActiveSeller->id,
            'name' => $activeName,
            'slug' => \Illuminate\Support\Str::slug($activeName).'-'.$dbActiveSeller->id,
            'avatar' => 'https://ui-avatars.com/api/?name='.urlencode($activeName).'&background=561C17&color=fff',
            'last_message' => 'Start a conversation with this seller.',
            'time' => '',
            'unread' => 0,
        ];
        $sellers->prepend($activeSeller);
        $selectedSlug = $activeSeller['slug'];
    }
    $refProduct = request('product') ? $buyerProducts->firstWhere('slug', request('product')) : null;
    $chatMessages = collect($chatMessages ?? []);
@endphp

@push('head')
<style>
    :root {
        --lk-bg: #FBF7F2;
        --lk-bg-soft: #F6EFE7;
        --lk-bg-alt: #EFE7DE;
        --lk-card: #FFFDF9;

        --lk-border: #EADCCC;
        --lk-border-strong: #DBCEC1;

        --lk-maroon: #561C17;
        --lk-maroon-2: #642920;
        --lk-maroon-dark: #3E130F;

        --lk-text: #3B211B;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-shadow-soft: 0 8px 24px rgba(86, 28, 23, 0.055);
        --lk-shadow-card: 0 16px 40px rgba(86, 28, 23, 0.09);
    }

    .lk-messages-page {
        display: grid;
        min-height: 0;
        gap: 22px;
    }

    .lk-messages-shell {
        display: grid;
        height: max(280px, min(720px, calc(100dvh - 205px)));
        min-height: 0;
        overflow: hidden;

        border: 1px solid var(--lk-border);
        border-radius: 24px;

        background: var(--lk-card);
        box-shadow: var(--lk-shadow-soft);
    }

    @media (min-width: 1024px) {
        .lk-messages-shell {
            grid-template-columns: 330px minmax(0, 1fr);
        }
    }

    .lk-messages-sidebar {
        display: flex;
        min-width: 0;
        min-height: 0;
        flex-direction: column;

        border-bottom: 1px solid var(--lk-border);
        background:
            radial-gradient(circle at 0% 0%, rgba(193, 151, 113, 0.16), transparent 32%),
            linear-gradient(180deg, #FFFDF9, #F8F0E8);
    }

    @media (min-width: 1024px) {
        .lk-messages-sidebar {
            border-right: 1px solid var(--lk-border);
            border-bottom: 0;
        }
    }

    .lk-messages-sidebar-head,
    .lk-chat-head,
    .lk-chat-form {
        flex: 0 0 auto;
        border-color: var(--lk-border);
        background: rgba(255, 253, 249, 0.9);
    }

    .lk-messages-sidebar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;

        padding: 18px 18px;
        border-bottom: 1px solid var(--lk-border);
    }

    .lk-messages-sidebar-head h2 {
        margin: 0;
        color: var(--lk-text);
        font-size: 14px;
        font-weight: 900;
        letter-spacing: -0.025em;
    }

    .lk-conversation-list {
        min-height: 0;
        flex: 1;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .lk-conversation-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;

        padding: 15px 18px;

        border-bottom: 1px solid #EFE1D5;
        color: var(--lk-text);
        text-decoration: none;

        transition: background 160ms ease, border-color 160ms ease;
    }

    .lk-conversation-item:hover {
        background: #F6EFE7;
    }

    .lk-conversation-item.is-active {
        background: #F3E4DE;
        box-shadow: inset 4px 0 var(--lk-maroon);
    }

    .lk-chat-avatar {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;

        border: 2px solid #FFFFFF;
        border-radius: 999px;

        background: var(--lk-maroon);
        object-fit: cover;

        box-shadow: 0 8px 18px rgba(86, 28, 23, 0.12);
    }

    .lk-conversation-copy {
        min-width: 0;
        flex: 1;
    }

    .lk-conversation-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .lk-conversation-name {
        overflow: hidden;
        color: var(--lk-text);
        font-size: 12px;
        font-weight: 900;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-conversation-time {
        color: var(--lk-muted-2);
        font-size: 10px;
        font-weight: 600;
        white-space: nowrap;
    }

    .lk-conversation-preview {
        overflow: hidden;
        margin: 4px 0 0;

        color: var(--lk-muted);
        font-size: 11px;
        line-height: 1.45;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-messages-empty {
        padding: 42px 20px;
        color: var(--lk-muted);
        font-size: 12px;
        text-align: center;
    }

    .lk-chat-area {
        display: flex;
        min-width: 0;
        min-height: 0;
        flex-direction: column;
        overflow: hidden;
        background: var(--lk-bg-soft);
    }

    .lk-chat-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;

        padding: 16px 18px;
        border-bottom: 1px solid var(--lk-border);
    }

    .lk-chat-user {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 12px;
    }

    .lk-chat-user-copy {
        min-width: 0;
    }

    .lk-chat-user-copy strong {
        display: block;
        overflow: hidden;

        color: var(--lk-text);

        font-size: 14px;
        font-weight: 900;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-chat-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;

        margin-top: 3px;

        color: #256F4A;

        font-size: 11px;
        font-weight: 700;
    }

    .lk-chat-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #256F4A;
    }

    .lk-chat-store-btn {
        min-height: 36px !important;
        padding: 0 13px !important;
        border-radius: 11px !important;
        font-size: 11px !important;
    }

    .lk-chat-stream {
        min-height: 0;
        flex: 1;
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;

        padding: 20px;

        background:
            radial-gradient(circle at 12% 12%, rgba(193, 151, 113, 0.10), transparent 28%),
            linear-gradient(180deg, #FBF7F2, #F6EFE7);
    }

    .lk-chat-day {
        display: flex;
        justify-content: center;
        margin-bottom: 18px;
    }

    .lk-chat-day span {
        display: inline-flex;
        align-items: center;
        min-height: 26px;
        padding: 0 12px;

        border: 1px solid var(--lk-border);
        border-radius: 999px;

        background: rgba(255, 253, 249, 0.86);
        color: var(--lk-muted);

        font-size: 10px;
        font-weight: 800;
    }

    .lk-message-row {
        display: flex;
        max-width: 82%;
        align-items: flex-start;
        gap: 10px;
        margin-top: 14px;
    }

    .lk-message-row.is-buyer {
        justify-content: flex-end;
        margin-left: auto;
    }

    .lk-message-small-avatar {
        width: 30px;
        height: 30px;
        flex: 0 0 30px;

        border-radius: 999px;
        object-fit: cover;
    }

    .lk-message-bubble {
        padding: 13px 14px;

        border: 1px solid var(--lk-border);
        border-radius: 18px;
        border-top-left-radius: 6px;

        background: var(--lk-card);
        color: var(--lk-text);

        box-shadow: 0 8px 20px rgba(86, 28, 23, 0.055);

        font-size: 12px;
        line-height: 1.65;
    }

    .lk-message-row.is-buyer .lk-message-bubble {
        border-color: var(--lk-maroon);
        border-top-left-radius: 18px;
        border-top-right-radius: 6px;

        background: linear-gradient(135deg, var(--lk-maroon), var(--lk-maroon-2));
        color: #FFFFFF;
    }

    .lk-message-time {
        display: block;
        margin-top: 6px;

        color: var(--lk-muted-2);

        font-size: 10px;
        font-weight: 600;
    }

    .lk-message-row.is-buyer .lk-message-time {
        color: #E8C8B2;
        text-align: right;
    }

    .lk-product-inquiry-card {
        display: grid;
        gap: 10px;

        padding: 14px;

        border-radius: 18px;
        border-top-right-radius: 6px;

        background: linear-gradient(135deg, var(--lk-maroon), var(--lk-maroon-2));
        color: #FFFFFF;

        box-shadow: 0 10px 24px rgba(86, 28, 23, 0.16);

        font-size: 12px;
        line-height: 1.6;
    }

    .lk-product-inquiry-preview {
        display: flex;
        align-items: center;
        gap: 10px;

        padding: 9px;

        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 14px;

        background: rgba(62, 19, 15, 0.55);
    }

    .lk-product-inquiry-preview img {
        width: 46px;
        height: 46px;
        flex: 0 0 46px;

        border-radius: 12px;
        object-fit: cover;
    }

    .lk-product-inquiry-preview div {
        min-width: 0;
    }

    .lk-product-inquiry-preview strong {
        display: block;
        overflow: hidden;

        color: #FFFFFF;

        font-size: 12px;
        font-weight: 900;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-product-inquiry-preview span {
        color: #E8C8B2;
        font-size: 11px;
    }

    .lk-chat-form {
        display: flex;
        align-items: center;
        gap: 10px;

        padding: 14px;
        border-top: 1px solid var(--lk-border);
    }

    .lk-chat-input {
        min-width: 0;
        flex: 1;

        min-height: 42px;
        padding: 0 15px;

        border: 1px solid var(--lk-border);
        border-radius: 14px;

        background: var(--lk-card);
        color: var(--lk-text);

        font-size: 12px;
        outline: none;

        transition: border-color 160ms ease, box-shadow 160ms ease;
    }

    .lk-chat-input:focus {
        border-color: var(--lk-tan);
        box-shadow: 0 0 0 4px rgba(86, 28, 23, 0.08);
    }

    .lk-chat-input::placeholder {
        color: var(--lk-muted-2);
    }

    .lk-chat-send {
        min-height: 42px !important;
        padding: 0 16px !important;
        border-radius: 14px !important;
        font-size: 12px !important;
    }

    .lk-chat-send svg {
        width: 16px;
        height: 16px;
    }

    .lk-chat-empty-state {
        display: flex;
        flex: 1;
        align-items: center;
        justify-content: center;
        flex-direction: column;

        padding: 42px 24px;
        text-align: center;
        color: var(--lk-muted);
    }

    .lk-chat-empty-state svg {
        width: 58px;
        height: 58px;
        margin-bottom: 14px;
        color: var(--lk-tan);
    }

    .lk-chat-empty-state h3 {
        margin: 0;
        color: var(--lk-text);
        font-size: 16px;
        font-weight: 900;
    }

    .lk-chat-empty-state p {
        margin: 6px 0 0;
        color: var(--lk-muted);
        font-size: 12px;
    }

    @media (max-width: 640px) {
        .lk-chat-head,
        .lk-chat-form {
            align-items: stretch;
            flex-direction: column;
        }

        .lk-chat-store-btn,
        .lk-chat-send {
            width: 100% !important;
        }

        .lk-message-row {
            max-width: 94%;
        }
    }

    @media (max-width: 1023px) {
        .lk-messages-shell {
            grid-template-columns: minmax(0, 1fr);
            grid-template-rows: minmax(118px, 30%) minmax(0, 1fr);
        }
    }

    body:has(.lk-messages-page) {
        overflow: hidden;
    }

    body:has(.lk-messages-page) .lk-footer {
        display: none;
    }

    html.dark .lk-messages-shell,
    html.dark .lk-messages-sidebar,
    html.dark .lk-chat-area,
    html.dark .lk-messages-sidebar-head,
    html.dark .lk-chat-head,
    html.dark .lk-chat-form {
        background: #211B17 !important;
        border-color: #3B2E27 !important;
        color: #F5EFE8 !important;
    }

    html.dark .lk-chat-stream {
        background: linear-gradient(180deg, #161210, #1E1A17) !important;
    }

    html.dark .lk-conversation-item {
        border-color: #3B2E27 !important;
    }

    html.dark .lk-conversation-item:hover,
    html.dark .lk-conversation-item.is-active {
        background: #2D1414 !important;
    }

    html.dark .lk-conversation-name,
    html.dark .lk-chat-user-copy strong,
    html.dark .lk-chat-empty-state h3 {
        color: #F5EFE8 !important;
    }

    html.dark .lk-conversation-preview,
    html.dark .lk-conversation-time,
    html.dark .lk-message-time,
    html.dark .lk-chat-empty-state p {
        color: #C8B7AD !important;
    }

    html.dark .lk-message-bubble,
    html.dark .lk-chat-input,
    html.dark .lk-chat-day span {
        background: #211B17 !important;
        border-color: #3B2E27 !important;
        color: #F5EFE8 !important;
    }
</style>
@endpush

@section('content')
<div class="lk-page lk-messages-page">
    <div class="lk-page-title">
        <div>
            <span class="lk-kicker">
                Communication
            </span>

            <h1>
                Messages
            </h1>

            <p>
                Keep conversations with sellers in one polished LIKHAE inbox.
            </p>
        </div>
    </div>

    <section class="lk-messages-shell">
        <aside class="lk-messages-sidebar">
            <div class="lk-messages-sidebar-head">
                <h2>
                    Conversations ({{ $sellers->count() }})
                </h2>
            </div>

            <div class="lk-conversation-list">
                @forelse($sellers as $item)
                    <a
                        href="{{ route('buyer.messages', ['seller' => $item['slug']]) }}"
                        class="lk-conversation-item {{ $selectedSlug === $item['slug'] ? 'is-active' : '' }}"
                    >
                        <img
                            class="lk-chat-avatar"
                            src="{{ $item['avatar'] }}"
                            alt="{{ $item['name'] }}"
                        >

                        <div class="lk-conversation-copy">
                            <div class="lk-conversation-top">
                                <strong class="lk-conversation-name">
                                    {{ $item['name'] }}
                                </strong>

                                <span class="lk-conversation-time">
                                    {{ $item['time'] }}
                                </span>
                            </div>

                            <p class="lk-conversation-preview">
                                {{ $item['last_message'] }}
                            </p>
                        </div>
                    </a>
                @empty
                    <div class="lk-messages-empty">
                        No conversations yet
                    </div>
                @endforelse
            </div>
        </aside>

        <main class="lk-chat-area">
            @if($activeSeller)
                <div class="lk-chat-head">
                    <div class="lk-chat-user">
                        <img
                            class="lk-chat-avatar"
                            src="{{ $activeSeller['avatar'] }}"
                            alt="{{ $activeSeller['name'] }}"
                        >

                        <div class="lk-chat-user-copy">
                            <strong>
                                {{ $activeSeller['name'] }}
                            </strong>

                            <span class="lk-chat-status">
                                <span class="lk-chat-status-dot"></span>
                                {{ ($activeSeller['is_support'] ?? false) ? 'LIKHAE Support' : 'Verified Seller · Online' }}
                            </span>
                        </div>
                    </div>

                    @if($activeSeller['is_seller'] ?? false)
                        <a
                            href="{{ route('buyer.shop', ['seller' => $activeSeller['store_key']]) }}"
                            class="lk-btn lk-btn-light lk-chat-store-btn"
                        >
                            View Store
                        </a>
                    @endif
                </div>

                <div
                    id="chatMessages"
                    class="lk-chat-stream"
                    data-message-thread
                    data-conversation-id="{{ $activeSeller['conversation_id'] ?? '' }}"
                    data-stream-url="{{ route('buyer.messages.stream', ['seller_id' => $activeSeller['id']]) }}"
                    data-current-user-id="{{ auth()->id() }}"
                    data-seller-avatar="{{ $activeSeller['avatar'] }}"
                    data-seller-name="{{ $activeSeller['name'] }}"
                >
                    <div class="lk-chat-day"><span>Conversation</span></div>
                    @forelse($chatMessages as $message)
                        @php $fromBuyer = $message->sender_user_id === auth()->id(); @endphp
                        <div class="lk-message-row {{ $fromBuyer ? 'is-buyer' : '' }}" data-message-id="{{ $message->id }}">
                            @unless($fromBuyer)
                                <img class="lk-message-small-avatar" src="{{ $activeSeller['avatar'] }}" alt="{{ $activeSeller['name'] }}">
                            @endunless
                            <div>
                                <div class="lk-message-bubble">{{ $message->body }}</div>
                                <span class="lk-message-time">{{ ($message->sent_at ?? $message->created_at)?->diffForHumans() }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="lk-messages-empty">No messages yet. Send the first message to {{ $activeSeller['name'] }}.</div>
                    @endforelse
                    @if($refProduct)
                        <div class="lk-product-inquiry-card">
                            <p><strong>Product inquiry:</strong> {{ data_get($refProduct, 'name') }}</p>
                        </div>
                    @endif
                </div>

                <form
                    id="chatForm"
                    class="lk-chat-form"
                    data-chat-form
                    method="POST"
                    action="{{ route('buyer.messages.send') }}"
                >
                    @csrf
                    <input type="hidden" name="recipient_id" value="{{ $activeSeller['id'] }}">
                    <input type="hidden" name="conversation_id" value="{{ $activeSeller['conversation_id'] ?? '' }}">
                    <input
                        type="text"
                        name="body"
                        required
                        maxlength="2000"
                        placeholder="Type your message to {{ $activeSeller['name'] }}..."
                        class="lk-chat-input"
                        data-chat-input
                    >

                    <button
                        type="submit"
                        class="lk-btn lk-btn-red lk-chat-send"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="m22 2-7 20-4-9-9-4Z"/>
                            <path d="M22 2 11 13"/>
                        </svg>

                        <span>
                            Send
                        </span>
                    </button>
                </form>
            @else
                <div class="lk-chat-empty-state">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <path d="M4 5h16v11H8l-4 4z"/>
                        <path d="M8 9h8"/>
                        <path d="M8 13h5"/>
                    </svg>

                    <h3>
                        No conversation selected
                    </h3>

                    <p>
                        Select a conversation from the left to start chatting.
                    </p>
                </div>
            @endif
        </main>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('chatMessages');
    const form = document.getElementById('chatForm');
    const input = form?.querySelector('[data-chat-input]');
    if (!list) return;

    const initialRows = [...list.querySelectorAll('[data-message-id]')];
    let previousSignature = null;
    initialRows.forEach((row) => {
        const side = row.classList.contains('is-buyer') ? 'buyer' : 'other';
        const body = row.querySelector('.lk-message-bubble')?.textContent?.trim() || '';
        const signature = `${side}|${body}`;
        if (body && signature === previousSignature) row.remove();
        else previousSignature = signature;
    });
    const seen = new Set([...list.querySelectorAll('[data-message-id]')].map((row) => row.dataset.messageId));
    let lastId = Math.max(0, ...[...seen].map((id) => Number(id) || 0));

    const scrollBottom = () => { list.scrollTop = list.scrollHeight; };
    const hasMessage = (id) => seen.has(id) || [...list.querySelectorAll('[data-message-id]')]
        .some((row) => row.dataset.messageId === id);
    const reconcilePending = (message) => {
        const body = String(message.body || '').trim();
        const senderId = String(message.sender_id || message.sender_user_id || list.dataset.currentUserId || '');
        const pending = [...list.querySelectorAll('[data-message-pending="true"]')].find((row) =>
            row.querySelector('.lk-message-bubble')?.textContent?.trim() === body
            && String(row.dataset.messageSenderId || '') === senderId
        );
        if (!pending || !message.id) return false;

        pending.dataset.messageId = String(message.id);
        pending.dataset.messagePending = 'false';
        pending.querySelector('.lk-message-time').textContent = message.time || 'Just now';
        seen.add(String(message.id));
        lastId = Math.max(lastId, Number(message.id) || 0);
        return true;
    };
    const removePending = (id) => list.querySelector(`[data-message-id="${id}"]`)?.remove();
    const appendMessage = (message) => {
        const id = String(message.id || '');
        if (!message.pending && reconcilePending(message)) return;
        if (!id || hasMessage(id)) return;
        seen.add(id);
        lastId = Math.max(lastId, Number(id) || 0);
        list.querySelector('.lk-messages-empty')?.remove();

        const row = document.createElement('div');
        row.className = `lk-message-row${message.from_me ? ' is-buyer' : ''}`;
        row.dataset.messageId = id;
        row.dataset.messageSenderId = String(message.sender_id || message.sender_user_id || list.dataset.currentUserId || '');
        if (message.pending) row.dataset.messagePending = 'true';

        if (!message.from_me) {
            const avatar = document.createElement('img');
            avatar.className = 'lk-message-small-avatar';
            avatar.src = list.dataset.sellerAvatar || '';
            avatar.alt = list.dataset.sellerName || 'Seller';
            row.appendChild(avatar);
        }

        const wrapper = document.createElement('div');
        const bubble = document.createElement('div');
        bubble.className = 'lk-message-bubble';
        bubble.textContent = message.body || '';
        const time = document.createElement('span');
        time.className = 'lk-message-time';
        time.textContent = message.pending ? 'Sending…' : (message.time || 'Just now');
        wrapper.appendChild(bubble);
        wrapper.appendChild(time);
        row.appendChild(wrapper);
        list.appendChild(row);
        scrollBottom();
    };

    scrollBottom();

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = input?.value.trim();
        if (!body) return;

        const payload = new FormData(form);
        const pendingId = `pending-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        appendMessage({
            id: pendingId,
            sender_id: list.dataset.currentUserId,
            body,
            from_me: true,
            pending: true,
        });
        input.value = '';
        input.focus();
        try {
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 10000);
            const response = await fetch(form.action, {
                method: 'POST',
                body: payload,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                cache: 'no-store',
                signal: controller.signal,
            });
            window.clearTimeout(timeout);

            if (!response.ok) throw new Error('Message failed');
            const data = await response.json();
            appendMessage(data.message);
            window.lkSubscribeMessageThread?.(list, data.message);
        } catch (error) {
            removePending(pendingId);
            window.lkBuyerToast?.('Message could not be sent. Please try again.');
        }
    });
});
</script>
@endsection
