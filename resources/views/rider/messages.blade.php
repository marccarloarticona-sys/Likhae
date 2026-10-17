@extends('Rider.app')
@section('title','Rider Messages - LIKHAE')
@section('active','messages')
@section('content')<div class="space-y-6"><header><p class="text-xs font-bold uppercase text-primary">Rider Communication</p><h1 class="mt-2 text-2xl font-bold text-ink">Messages</h1><p class="mt-2 text-sm text-muted">Contact logistics, sellers, buyers, and platform staff with live updates.</p></header>@include('partials.conversation-center',['sendRoute'=>route('rider.messages.send'),'streamRoute'=>route('rider.messages.stream'),'messageAttachmentsReady'=>$messageAttachmentsReady ?? false])</div>@endsection
