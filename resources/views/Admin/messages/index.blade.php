@extends('layouts.admin.app')
@section('title','Messages')
@section('subtitle','Direct conversations between platform users and staff.')
@section('active','messages')
@section('content')
<div class="lk-workspace-messages-page">
    @include('components.shared.workspace-messages-heading', ['description' => 'Keep platform conversations in one polished LIKHAE inbox.'])
    @include('components.shared.support-messages-nav', [
        'section' => $section,
        'overviewUrl' => route('admin.messages', ['section' => 'overview']),
        'chatsUrl' => route('admin.messages', ['section' => 'chats']),
    ])
    @if($section === 'overview')
        @if($supportOverviewReady)
            @include('components.shared.support-overview', [
                'supportOverview' => $supportOverview,
                'messagesRouteName' => 'admin.messages',
                'scopeLabel' => 'Admin',
                'faqUrl' => route('admin.support-faqs'),
                'supportRequestRouteNames' => [
                    'acknowledge' => 'admin.messages.support-requests.acknowledge',
                    'accept' => 'admin.messages.support-requests.accept',
                    'resolve' => 'admin.messages.support-requests.resolve',
                ],
            ])
        @else
            <section class="ad-card p-4">Support Overview is temporarily unavailable. Your existing conversations are available in Live Chats.</section>
        @endif
    @else
        <div class="mb-3 flex justify-end"><a href="{{ route('admin.support-faqs') }}" class="ad-btn ad-btn-secondary">Manage support FAQs</a></div>
        @include('components.shared.conversation-center', [
            'sendRoute' => route('admin.messages.send'),
            'streamRoute' => route('admin.messages.stream'),
            'messageAttachmentsReady' => $messageAttachmentsReady ?? false,
        ])
    @endif
</div>
@endsection
