@extends('layouts.logistics.app')
@section('title','Messages - LIKHAE Logistics')
@section('content')
<div class="lk-workspace-messages-page">
    @include('components.shared.workspace-messages-heading', ['description' => 'Keep conversations with buyers, sellers, riders, and platform staff in one polished LIKHAE inbox.'])
    @include('components.shared.conversation-center', [
        'sendRoute' => route('logistics.messages.send'),
        'streamRoute' => route('logistics.messages.stream'),
        'messageAttachmentsReady' => $messageAttachmentsReady ?? false,
    ])
</div>
@endsection
