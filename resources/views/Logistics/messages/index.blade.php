@extends('Logistics.app')
@section('title','Messages - LIKHAE Logistics')
@section('content')
<div class="lk-workspace-messages-page">
    @include('partials.workspace-messages-heading', ['description' => 'Keep conversations with buyers, sellers, riders, and platform staff in one polished LIKHAE inbox.'])
    @include('partials.conversation-center', [
        'sendRoute' => route('logistics.messages.send'),
        'streamRoute' => route('logistics.messages.stream'),
        'messageAttachmentsReady' => $messageAttachmentsReady ?? false,
    ])
</div>
@endsection
