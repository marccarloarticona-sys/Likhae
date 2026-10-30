@extends('layouts.seller.app')
@section('title','Messages')
@section('active','messages')
@section('subtitle','Contact buyers, riders, logistics, and platform staff.')
@section('content')
<div class="sl-page lk-workspace-messages-page lk-seller-messages-page">
    @include('components.shared.workspace-messages-heading', ['description' => 'Keep conversations with buyers and your team in one polished LIKHAE inbox.'])
    @include('components.shared.support-messages-nav', [
        'section' => $section,
        'overviewUrl' => route('seller.messages', ['section' => 'overview']),
        'chatsUrl' => route('seller.messages', ['section' => 'chats']),
    ])
    @if($section === 'overview')
        @if($supportOverviewReady)
            @include('components.shared.support-overview', [
                'supportOverview' => $supportOverview,
                'messagesRouteName' => 'seller.messages',
                'scopeLabel' => 'Seller',
                'faqUrl' => route('seller.messages.faqs'),
                'supportRequestRouteNames' => [
                    'acknowledge' => 'seller.messages.support-requests.acknowledge',
                    'accept' => 'seller.messages.support-requests.accept',
                    'resolve' => 'seller.messages.support-requests.resolve',
                ],
            ])
        @else
            <section class="sl-card p-4">Support Overview is temporarily unavailable. Your existing conversations are available in Live Chats.</section>
        @endif
    @else
        @unless($messageAttachmentsReady)<div class="sl-card mb-4 text-sm">File attachments are temporarily unavailable.</div>@endunless
        @include('components.shared.conversation-center', [
            'sendRoute' => route('seller.messages.send'),
            'streamRoute' => route('seller.messages.stream'),
            'messageAttachmentsReady' => $messageAttachmentsReady,
        ])
    @endif
</div>
<style>
    @media (min-width: 768px) {
        .lk-seller-messages-page:has(.lk-unified-messages) {
            height: calc(100dvh - 122px);
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .lk-seller-messages-page > .lk-support-tabs { flex: 0 0 auto; margin: 0; }
        .lk-seller-messages-page:has(.lk-unified-messages) .lk-unified-messages {
            min-height: 0;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .lk-seller-messages-page:has(.lk-unified-messages) .lk-messages-shell {
            height: 100%;
            min-height: 0;
            flex: 1 1 auto;
        }
    }
    @media (max-width: 767px) {
        .lk-seller-messages-page:has(.lk-unified-messages) {
            height: calc(100dvh - 106px);
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .lk-seller-messages-page > .lk-support-tabs { flex: 0 0 auto; margin: 0; }
        .lk-seller-messages-page:has(.lk-unified-messages) .lk-unified-messages {
            min-height: 0;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .lk-seller-messages-page:has(.lk-unified-messages) .lk-messages-shell {
            height: 100%;
            min-height: 0;
            flex: 1 1 auto;
        }
    }
    body:has(.lk-seller-messages-page .lk-unified-messages) .likhae-workspace-ai-widget[data-workspace="seller"] { bottom: 92px; }
    @media (max-width: 600px) {
        body:has(.lk-seller-messages-page .lk-unified-messages) .likhae-workspace-ai-widget[data-workspace="seller"] { right: 14px; bottom: 78px; }
    }
</style>
@endsection
