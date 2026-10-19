@extends('Rider.app')
@section('title','Rider Messages - LIKHAE')
@section('active','messages')
@section('content')
<div class="lk-workspace-messages-page">
    @include('partials.workspace-messages-heading', ['description' => 'Keep conversations with buyers, sellers, logistics, and platform staff in one polished LIKHAE inbox.'])
    @include('partials.conversation-center', [
        'sendRoute' => route('rider.messages.send'),
        'streamRoute' => route('rider.messages.stream'),
        'messageAttachmentsReady' => $messageAttachmentsReady ?? false,
    ])
</div>
@endsection
