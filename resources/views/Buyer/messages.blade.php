@extends('layouts.buyer.app')

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
    $activeSeller = filled($selectedSlug) ? $sellers->firstWhere('slug', $selectedSlug) : null;
    if (!$activeSeller && $dbActiveSeller) {
        $activeName = $dbActiveSeller->store_name ?: $dbActiveSeller->business_name ?: $dbActiveSeller->name;
        $activeSeller = [
            'id' => $dbActiveSeller->id,
            'name' => $activeName,
            'slug' => \Illuminate\Support\Str::slug($activeName).'-'.$dbActiveSeller->id,
            'avatar' => asset('images/defaults/profile-avatar.svg'),
            'last_message' => 'Start a conversation with this seller.',
            'time' => '',
            'unread' => 0,
        ];
        $sellers->prepend($activeSeller);
        $selectedSlug = $activeSeller['slug'];
    }
    $refProduct = request('product') ? $buyerProducts->firstWhere('slug', request('product')) : null;
    $chatMessages = collect($chatMessages ?? []);
    $activeOtherReadAt = filled($activeSeller['other_last_read_at'] ?? null)
        ? \Illuminate\Support\Carbon::parse($activeSeller['other_last_read_at'])
        : null;
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

    .lk-conversation-avatar-wrap {
        position: relative;
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
    }

    .lk-conversation-avatar-wrap .lk-chat-avatar {
        display: block;
    }

    .lk-conversation-presence-dot {
        position: absolute;
        right: 0;
        bottom: 0;
        width: 12px;
        height: 12px;
        border: 2px solid #FFFDF9;
        border-radius: 999px;
        background: #A99E98;
    }

    .lk-conversation-presence-dot.is-online {
        background: #2EAA60;
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

    .lk-chat-back {
        display: none;
        align-items: center;
        justify-content: center;
        gap: 5px;
        min-height: 38px;
        padding: 0 9px;
        border: 1px solid var(--lk-border);
        border-radius: 11px;
        background: var(--lk-card);
        color: var(--lk-maroon);
        font: 700 11px/1 Poppins, system-ui, sans-serif;
        cursor: pointer;
    }

    .lk-chat-back svg {
        width: 16px;
        height: 16px;
        flex: 0 0 16px;
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

    .lk-chat-status.is-offline {
        color: #7B716B;
    }

    .lk-chat-status.is-offline .lk-chat-status-dot {
        background: #A99E98;
    }

    .lk-chat-feedback {
        margin: 0;
        padding: 7px 18px;
        color: var(--lk-muted);
        font-size: 11px;
    }

    .lk-chat-feedback.is-error {
        color: #9A5B11;
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
        white-space: pre-wrap;
        overflow-wrap: anywhere;
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
        color: #D6A58D;
        text-align: right;
    }

    .lk-message-meta {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 7px;
        margin-top: 6px;
    }

    .lk-message-meta .lk-message-time {
        margin-top: 0;
    }

    .lk-message-row.is-buyer .lk-message-meta {
        justify-content: flex-end;
    }

    .lk-message-receipt {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        color: var(--lk-muted-2);
        font-size: 10px;
        font-weight: 700;
        line-height: 1;
    }

    .lk-message-row.is-buyer .lk-message-receipt {
        color: #D6A58D;
    }

    .lk-message-seen-avatar {
        width: 16px;
        height: 16px;
        border: 1px solid rgba(255, 255, 255, 0.9);
        border-radius: 999px;
        object-fit: cover;
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

    .lk-message-attachment { position: relative; display: inline-flex; width: 42px; height: 42px; flex: 0 0 42px; align-items: center; justify-content: center; border: 1px solid var(--lk-border); border-radius: 13px; background: var(--lk-card); color: var(--lk-maroon); cursor: pointer; }
    .lk-message-attachment svg { width: 20px; height: 20px; }
    .lk-message-attachment input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
    .lk-message-preview { display: block; max-width: 76px; max-height: 54px; border-radius: 8px; object-fit: cover; }
    .lk-message-preview[hidden] { display: none; }
    .lk-attachment-selection { display: inline-flex; min-width: 0; align-items: center; gap: 7px; }
    [data-attachment-filename] { max-width: 150px; overflow: hidden; color: var(--lk-muted); font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
    [data-attachment-filename][hidden] { display: none; }
    .lk-message-image-attachment { display: block; max-width: min(280px,75vw); max-height: 300px; margin-top: 8px; border-radius: 12px; object-fit: contain; }
    .lk-message-file { display: flex; max-width: min(320px,75vw); align-items: center; gap: 10px; margin-top: 8px; padding: 10px 12px; border: 1px solid var(--lk-border); border-radius: 12px; background: var(--lk-card); color: var(--lk-text); text-decoration: none; }
    .lk-message-file > svg { width: 22px; height: 22px; flex: 0 0 22px; color: var(--lk-maroon); }
    .lk-message-file-copy { display: grid; min-width: 0; flex: 1; gap: 2px; }
    .lk-message-file-copy strong { overflow: hidden; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
    .lk-message-file-copy small { color: var(--lk-muted); font-size: 9px; font-weight: 700; }
    .lk-message-file-download { font-size: 16px; font-weight: 900; }
    .lk-product-context { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid var(--lk-border); background: var(--lk-card); color: var(--lk-text); font-size: 11px; }
    .lk-product-context img { width: 48px; height: 48px; flex: 0 0 48px; border-radius: 10px; object-fit: cover; }

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

    @media (max-width: 1023px) {
        .lk-messages-shell {
            grid-template-columns: minmax(220px, 30%) minmax(0, 1fr);
            grid-template-rows: minmax(0, 1fr);
        }

        .lk-messages-sidebar {
            border-right: 1px solid var(--lk-border);
            border-bottom: 0;
        }
    }

    body:has(.lk-messages-page) {
        height: 100dvh;
        min-height: 0;
        overflow: hidden;
    }

    body:has(.lk-messages-page) .lk-shell {
        height: 100dvh;
        min-height: 0;
        overflow: hidden;
    }

    body:has(.lk-messages-page) .lk-main {
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

    .lk-messages-page {
        flex: 1;
        grid-template-rows: auto minmax(0, 1fr);
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }

    .lk-messages-shell {
        flex: 1;
        height: auto;
    }

    body:has(.lk-messages-page) .lk-footer {
        display: none;
    }

    @media (max-width: 767px) {
        body:has(.lk-messages-page) .lk-main {
            padding: 10px;
        }

        .lk-messages-page {
            gap: 10px;
        }

        .lk-page-title .lk-kicker,
        .lk-page-title p {
            display: none;
        }

        .lk-page-title h1 {
            font-size: 32px;
            line-height: 1;
        }

        .lk-messages-shell {
            grid-template-columns: minmax(0, 1fr);
            grid-template-rows: minmax(0, 1fr);
            border-radius: 18px;
        }

        .lk-messages-page.is-mobile-chat-view .lk-messages-sidebar,
        .lk-messages-page.is-mobile-list-view .lk-chat-area {
            display: none;
        }

        .lk-messages-page.is-mobile-chat-view .lk-chat-area,
        .lk-messages-page.is-mobile-list-view .lk-messages-sidebar {
            display: flex;
        }

        .lk-chat-head {
            align-items: center;
            flex-direction: row;
            gap: 8px;
            padding: 10px;
        }

        .lk-chat-back {
            display: inline-flex;
            flex: 0 0 auto;
        }

        .lk-chat-user {
            flex: 1;
            gap: 8px;
        }

        .lk-chat-head .lk-chat-avatar {
            width: 36px;
            height: 36px;
            flex-basis: 36px;
        }

        .lk-chat-user-copy strong {
            font-size: 12px;
        }

        .lk-chat-status {
            font-size: 10px;
        }

        .lk-chat-store-btn {
            display: none !important;
        }

        .lk-chat-stream {
            padding: 12px;
        }

        .lk-message-row {
            max-width: 94%;
        }

        .lk-chat-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 8px;
            padding: 10px;
        }

        .lk-chat-input {
            grid-column: 1 / -1;
            width: 100%;
            min-height: 42px;
        }

        .lk-chat-form > span:has([data-message-count]) {
            grid-column: 1;
            grid-row: 2;
            font-size: 10px;
            white-space: nowrap;
        }

        .lk-attachment-selection {
            grid-column: 2;
            grid-row: 2;
        }

        .lk-chat-send {
            grid-column: 3;
            grid-row: 2;
            width: auto !important;
            min-width: 88px;
            min-height: 42px !important;
            padding-inline: 12px !important;
        }
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

    html.dark .lk-message-row.is-buyer .lk-message-time,
    html.dark .lk-message-row.is-buyer .lk-message-receipt {
        color: #F5DDD2 !important;
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
<div class="lk-page lk-messages-page {{ ($activeSeller || $sellers->isEmpty()) ? 'is-mobile-chat-view' : 'is-mobile-list-view' }}" data-mobile-messages-page>
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

            <label class="lk-conversation-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 4.5 4.5"></path></svg>
                <input type="search" placeholder="Search by name…" aria-label="Search conversations by name" data-conversation-search>
            </label>
            <div class="lk-conversation-list">
                <div class="lk-messages-search-empty" data-conversation-search-empty hidden>No conversations match that name.</div>
                @forelse($sellers as $item)
                    <a
                        href="{{ route('buyer.messages', ['seller' => $item['slug']]) }}"
                        class="lk-conversation-item {{ $selectedSlug === $item['slug'] ? 'is-active' : '' }}"
                        data-conversation-name="{{ $item['name'] }}"
                        data-conversation-presence-id="{{ $item['conversation_id'] }}"
                        data-presence-url="{{ route('messages.receipt', ['conversation' => $item['conversation_id']]) }}"
                    >
                        <span class="lk-conversation-avatar-wrap">
                            <img class="lk-chat-avatar" src="{{ $item['avatar'] }}" alt="{{ $item['name'] }}" loading="{{ $selectedSlug === $item['slug'] ? 'eager' : 'lazy' }}" decoding="async" fetchpriority="{{ $selectedSlug === $item['slug'] ? 'high' : 'low' }}">
                            @php
                                $initiallyOnline = $item['slug'] === ($activeSeller['slug'] ?? null)
                                    && (bool) ($activeRecipientOnline ?? false);
                            @endphp
                            <span class="lk-conversation-presence-dot {{ $initiallyOnline ? 'is-online' : '' }}" data-conversation-presence-dot role="img" aria-label="{{ $initiallyOnline ? 'Online' : 'Offline' }}" title="{{ $initiallyOnline ? 'Online' : 'Offline' }}"></span>
                        </span>

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
                    <button type="button" class="lk-chat-back" data-mobile-conversation-list aria-label="Back to conversations" title="Back to conversations">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        <span>Chats</span>
                    </button>
                    <div class="lk-chat-user">
                        <img
                            class="lk-chat-avatar"
                            src="{{ $activeSeller['avatar'] }}"
                            alt="{{ $activeSeller['name'] }}"
                            decoding="async"
                            fetchpriority="high"
                        >

                        <div class="lk-chat-user-copy">
                            <strong>
                                {{ $activeSeller['name'] }}
                            </strong>

                            <span class="lk-chat-status {{ ($activeRecipientOnline ?? false) ? '' : 'is-offline' }}" data-message-presence-status data-presence="{{ ($activeRecipientOnline ?? false) ? 'online' : 'offline' }}">
                                <span class="lk-chat-status-dot"></span>
                                <span data-message-presence-label>{{ ($activeRecipientOnline ?? false) ? 'Online' : 'Offline' }}</span>
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

                <p class="lk-chat-feedback" data-message-feedback role="status" aria-live="polite" hidden></p>

                @if($refProduct)
                    <div class="lk-product-context" aria-label="Product context">
                        <img src="{{ data_get($refProduct, 'primary_image.url') ?: asset('images/product-placeholder.svg') }}" alt="">
                        <span><strong>Product inquiry:</strong> {{ data_get($refProduct, 'name') }} <a class="underline" href="{{ route('buyer.product-details', data_get($refProduct, 'slug')) }}">View product</a></span>
                    </div>
                @elseif(request()->filled('product'))
                    <div class="lk-product-context" role="status">This product is no longer available for inquiry.</div>
                @endif

                @if(!empty($messageContext))
                    <div class="lk-product-context" aria-label="{{ $messageContext['heading'] }}">
                        <span><strong>{{ $messageContext['heading'] }}:</strong> {{ $messageContext['order_number'] ?? '' }} {{ isset($messageContext['status']) ? '· '.$messageContext['status'] : '' }}
                            @if(!empty($messageContext['seller'])) · {{ $messageContext['seller'] }} @endif
                            @if(!empty($messageContext['items']))<br>Items: {{ implode(', ', $messageContext['items']) }}@endif
                        </span>
                    </div>
                @endif

                <div
                    id="chatMessages"
                    class="lk-chat-stream"
                    data-message-thread
                    data-conversation-id="{{ $activeSeller['conversation_id'] ?? '' }}"
                    data-stream-url="{{ route('buyer.messages.stream', ['conversation' => $activeSeller['conversation_id'] ?? 0]) }}"
                    data-current-user-id="{{ auth()->id() }}"
                    data-other-user-id="{{ $activeSeller['id'] }}"
                    data-seller-avatar="{{ $activeSeller['avatar'] }}"
                    data-seller-name="{{ $activeSeller['name'] }}"
                    data-other-avatar="{{ $activeSeller['avatar'] }}"
                    data-other-name="{{ $activeSeller['name'] }}"
                    data-other-last-read-at="{{ $activeOtherReadAt?->toIso8601String() }}"
                    data-read-url-template="{{ route('messages.read', ['conversation' => '__conversation__']) }}"
                    data-receipt-url-template="{{ route('messages.receipt', ['conversation' => '__conversation__']) }}"
                    @if($activeSeller['conversation_id'] ?? false)
                        data-read-url="{{ route('messages.read', ['conversation' => $activeSeller['conversation_id']]) }}"
                        data-receipt-url="{{ route('messages.receipt', ['conversation' => $activeSeller['conversation_id']]) }}"
                    @endif
                    @if($messageAttachmentsReady ?? false) data-attachments-enabled="true" @endif
                >
                    <div class="lk-chat-day"><span>Conversation</span></div>
                    @forelse($chatMessages as $message)
                        @php
                            $fromBuyer = (int) $message->sender_user_id === (int) auth()->id();
                            $messageSentAt = $message->sent_at ?? $message->created_at;
                            $wasSeen = $fromBuyer && $activeOtherReadAt && $messageSentAt && $messageSentAt->lte($activeOtherReadAt);
                            $messageBody = (string) $message->body;
                            $showMessageBody = filled($messageBody)
                                && !(filled($message->attachment_path) && in_array(trim($messageBody), ['[Photo]', '[File]'], true));
                        @endphp
                        <div class="lk-message-row {{ $fromBuyer ? 'is-buyer' : '' }}" data-message-id="{{ $message->id }}" data-message-sender-id="{{ $message->sender_user_id }}" @if($messageSentAt) data-message-sent-at="{{ $messageSentAt->toIso8601String() }}" @endif>
                            @unless($fromBuyer)
                                <img class="lk-message-small-avatar" src="{{ $activeSeller['avatar'] }}" alt="{{ $activeSeller['name'] }}" loading="lazy" decoding="async" fetchpriority="low">
                            @endunless
                            <div>
                                @if($showMessageBody)<div class="lk-message-bubble">{{ $messageBody }}</div>@endif
                                @if(($messageAttachmentsReady ?? false) && filled($message->attachment_path))
                                    @include('components.shared.message-attachment',['message'=>$message])
                                @endif
                                <div class="lk-message-meta">
                                    <span class="lk-message-time">{{ $messageSentAt?->diffForHumans() }}</span>
                                    @if($fromBuyer)
                                        <span class="lk-message-receipt" data-message-receipt data-message-sent-at="{{ $messageSentAt?->toIso8601String() }}" data-receipt-state="{{ $wasSeen ? 'seen' : 'sent' }}">
                                            <span data-message-receipt-text>{{ $wasSeen ? 'Seen' : 'Sent' }}</span>
                                            @if($wasSeen)<img class="lk-message-seen-avatar" data-message-receipt-avatar src="{{ $activeSeller['avatar'] }}" alt="" title="Seen by {{ $activeSeller['name'] }}">@endif
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="lk-messages-empty">No messages yet. Send the first message to {{ $activeSeller['name'] }}.</div>
                    @endforelse
                </div>

                <form
                    id="chatForm"
                    class="lk-chat-form"
                    enctype="multipart/form-data"
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
                        maxlength="1000"
                        placeholder="Type your message to {{ $activeSeller['name'] }}..."
                        class="lk-chat-input"
                        data-chat-input
                    >

                    <span class="text-xs text-stone-500"><span data-message-count>0</span>/1000</span>
                    @if($messageAttachmentsReady ?? false)
                        <span class="lk-attachment-selection"><label class="lk-message-attachment" title="Attach up to 5 files or images" aria-label="Attach up to 5 files or images"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/><path d="M15 3v4h4"/></svg><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.mp4,.mov" data-message-attachment-input><span class="sr-only">Attach up to 5 files or images</span></label><img class="lk-message-preview" data-attachment-preview hidden alt="Selected image preview"><span data-attachment-filename hidden></span></span>
                    @endif

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
                        New conversation
                    </h3>

                    <p>
                        Select a conversation from the list, or visit a shop to start a new conversation.
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

    const messagesPage = document.querySelector('[data-mobile-messages-page]');
    list.closest('.lk-chat-area')?.querySelector('[data-mobile-conversation-list]')?.addEventListener('click', () => {
        messagesPage?.classList.remove('is-mobile-chat-view');
        messagesPage?.classList.add('is-mobile-list-view');
        messagesPage?.querySelector('.lk-conversation-item.is-active')?.focus();
    });

    const seen = new Set([...list.querySelectorAll('[data-message-id]')].map((row) => row.dataset.messageId));
    let lastId = Math.max(0, ...[...seen].map((id) => Number(id) || 0));

    const scrollBottom = () => { list.scrollTop = list.scrollHeight; };
    const hasMessage = (id) => seen.has(id) || [...list.querySelectorAll('[data-message-id]')]
        .some((row) => row.dataset.messageId === id);
    const setReceipt = (row, state) => {
        const meta = row.querySelector('.lk-message-meta');
        if (!meta) return;

        let receipt = meta.querySelector('[data-message-receipt]');
        if (!receipt) {
            receipt = document.createElement('span');
            receipt.className = 'lk-message-receipt';
            receipt.dataset.messageReceipt = 'true';
            const label = document.createElement('span');
            label.dataset.messageReceiptText = 'true';
            receipt.appendChild(label);
            meta.appendChild(receipt);
        }

        receipt.dataset.receiptState = state;
        receipt.dataset.messageSentAt = row.dataset.messageSentAt || '';
        const label = receipt.querySelector('[data-message-receipt-text]');
        if (label) label.textContent = state === 'sending' ? 'Sending' : 'Sent';
        receipt.setAttribute('aria-label', label?.textContent || 'Sent');
    };
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
        if (message.sent_at) pending.dataset.messageSentAt = message.sent_at;
        if (list.dataset.attachmentsEnabled === 'true' && message.attachment_url
            && !pending.querySelector('[data-message-attachment="true"]')) {
            const attachment = window.lkCreateMessageAttachment?.(message);
            if (attachment) pending.querySelector('.lk-message-bubble')?.after(attachment);
        }
        pending.querySelector('.lk-message-time').textContent = message.time || 'Just now';
        setReceipt(pending, 'sent');
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
        if (message.sent_at) row.dataset.messageSentAt = message.sent_at;
        else if (message.pending) row.dataset.messageSentAt = new Date().toISOString();
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
        const body = String(message.body || '').trim();
        bubble.textContent = body;
        bubble.hidden = !body || (Boolean(message.attachment_url) && ['[Photo]', '[File]'].includes(body));
        const time = document.createElement('span');
        time.className = 'lk-message-time';
        time.textContent = message.pending ? '' : (message.time || 'Just now');
        wrapper.appendChild(bubble);
        if (list.dataset.attachmentsEnabled === 'true' && message.attachment_url) {
            const attachment = window.lkCreateMessageAttachment?.(message);
            if (attachment) wrapper.appendChild(attachment);
        }
        const meta = document.createElement('div');
        meta.className = 'lk-message-meta';
        meta.appendChild(time);
        wrapper.appendChild(meta);
        row.appendChild(wrapper);
        list.appendChild(row);
        if (message.from_me) setReceipt(row, message.pending ? 'sending' : 'sent');
        scrollBottom();
    };

    scrollBottom();

    const counter = form?.querySelector('[data-message-count]');
    const updateCounter = () => { if (counter) counter.textContent = String(Array.from(input?.value || '').length); };
    input?.addEventListener('input', updateCounter);
    updateCounter();
    const attachmentInput = form?.querySelector('[data-message-attachment-input]');
    const preview = form?.querySelector('[data-attachment-preview]');
    const selectedFilename = form?.querySelector('[data-attachment-filename]');
    attachmentInput?.addEventListener('change', () => {
        const files = Array.from(attachmentInput.files || []);
        if (preview?.dataset.previewUrl) URL.revokeObjectURL(preview.dataset.previewUrl);
        if (!files.length) {
            if (preview) {
                delete preview.dataset.previewUrl;
                preview.removeAttribute('src');
                preview.hidden = true;
            }
            if (selectedFilename) {
                selectedFilename.hidden = true;
                selectedFilename.textContent = '';
            }
            return;
        }
        if (files.length > 5) {
            if (preview) {
                delete preview.dataset.previewUrl;
                preview.removeAttribute('src');
                preview.hidden = true;
            }
            if (selectedFilename) {
                selectedFilename.textContent = 'Choose up to 5 files or images';
                selectedFilename.hidden = false;
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
                preview.removeAttribute('src');
                preview.hidden = true;
            }
        }
        if (selectedFilename) {
            selectedFilename.textContent = files.length === 1 ? files[0].name : `${files.length} attachments selected`;
            selectedFilename.hidden = false;
        }
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = input?.value.trim();
        const submittedFiles = Array.from(attachmentInput?.files || []);
        if (submittedFiles.length > 5) {
            window.lkBuyerToast?.('Choose up to 5 files or images per message.');
            return;
        }
        if ((!body && !submittedFiles.length) || form.dataset.sending === 'true') return;
        form.dataset.sending = 'true';
        const sendButton = form.querySelector('button[type="submit"]');
        if (sendButton) sendButton.disabled = true;
        sendButton?.setAttribute('aria-busy', 'true');
        const pendingId = `pending-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        let timeout = null;
        let pendingAdded = false;
        try {
            const preparedFiles = window.lkOptimizeMessageAttachments
                ? await window.lkOptimizeMessageAttachments(submittedFiles)
                : submittedFiles;
            const payload = new FormData(form);
            payload.set('body', body || '');
            if (attachmentInput?.name) {
                payload.delete(attachmentInput.name);
                preparedFiles.forEach((file) => payload.append(attachmentInput.name, file, file.name));
            }

            appendMessage({
                id: pendingId,
                sender_id: list.dataset.currentUserId,
                body: body || '',
                from_me: true,
                pending: true,
            });
            pendingAdded = true;

            const controller = new AbortController();
            timeout = window.setTimeout(() => controller.abort(), 45000);
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

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validationMessage = data?.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(validationMessage || data?.message || 'Message could not be sent.');
            }
            const sentMessages = Array.isArray(data.messages)
                ? data.messages
                : (data.message ? [data.message] : []);
            if (!sentMessages.length) throw new Error('Message could not be sent.');
            sentMessages.forEach(appendMessage);
            window.lkSubscribeMessageThread?.(list, data.message);
            if (input?.value.trim() === body) {
                input.value = '';
                input.dispatchEvent(new Event('input', {bubbles: true}));
            }
            const currentFiles = Array.from(attachmentInput?.files || []);
            const sameSelection = currentFiles.length === submittedFiles.length && submittedFiles.every((file, index) =>
                file.name === currentFiles[index].name
                && file.size === currentFiles[index].size
                && file.lastModified === currentFiles[index].lastModified,
            );
            if (sameSelection) {
                attachmentInput.value = '';
                if (preview?.dataset.previewUrl) URL.revokeObjectURL(preview.dataset.previewUrl);
                if (preview) { delete preview.dataset.previewUrl; preview.removeAttribute('src'); preview.hidden = true; }
                if (selectedFilename) { selectedFilename.hidden = true; selectedFilename.textContent = ''; }
            }
            input?.focus();
        } catch (error) {
            if (pendingAdded) removePending(pendingId);
            window.lkBuyerToast?.(error?.message || 'Message could not be sent. Please try again.');
        } finally {
            if (timeout !== null) window.clearTimeout(timeout);
            form.dataset.sending = 'false';
            if (sendButton) sendButton.disabled = false;
            sendButton?.removeAttribute('aria-busy');
        }
    });
});
</script>
@endsection
