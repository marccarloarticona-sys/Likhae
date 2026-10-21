@extends('layouts.seller.app')
@section('title','Messages')
@section('active','messages')
@section('subtitle','Contact buyers, riders, logistics, and platform staff.')
@section('content')
<div class="sl-page lk-workspace-messages-page">
    @include('components.shared.workspace-messages-heading', ['description' => 'Keep conversations with buyers and your team in one polished LIKHAE inbox.'])
    @unless($messageAttachmentsReady)<div class="sl-card mb-4 text-sm">File attachments are temporarily unavailable.</div>@endunless
    @include('components.shared.conversation-center', [
        'sendRoute' => route('seller.messages.send'),
        'streamRoute' => route('seller.messages.stream'),
        'messageAttachmentsReady' => $messageAttachmentsReady,
    ])
</div>
@endsection
