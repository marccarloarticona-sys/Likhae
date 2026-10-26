@extends('layouts.seller.app')
@section('title','Messages')
@section('active','messages')
@section('subtitle','Contact buyers, riders, logistics, and platform staff.')
@section('content')
<div class="sl-page lk-workspace-messages-page">
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
        <div class="mb-3 flex justify-end"><a href="{{ route('seller.messages.faqs') }}" class="sl-btn sl-btn-ghost">Manage suggested answers</a></div>
        @unless($messageAttachmentsReady)<div class="sl-card mb-4 text-sm">File attachments are temporarily unavailable.</div>@endunless
        @include('components.shared.conversation-center', [
            'sendRoute' => route('seller.messages.send'),
            'streamRoute' => route('seller.messages.stream'),
            'messageAttachmentsReady' => $messageAttachmentsReady,
        ])
    @endif
</div>
@endsection
