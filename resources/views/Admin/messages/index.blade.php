@extends('layouts.admin.app')
@section('title','Messages')
@section('subtitle','Direct conversations between platform users and staff.')
@section('active','messages')
@section('content')
<div class="lk-workspace-messages-page">
    @include('components.shared.workspace-messages-heading', ['description' => 'Keep platform conversations in one polished LIKHAE inbox.'])
    @include('components.shared.conversation-center', [
        'sendRoute' => route('admin.messages.send'),
        'streamRoute' => route('admin.messages.stream'),
        'messageAttachmentsReady' => $messageAttachmentsReady ?? false,
    ])
</div>
@endsection
