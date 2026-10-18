@extends('Logistics.app')
@section('title','Messages - LIKHAE Logistics')
@section('content')<div class="space-y-6"><header><p class="text-xs font-bold uppercase text-primary">Communication Center</p><h1 class="mt-2 text-2xl font-bold text-ink">Messages</h1><p class="mt-2 text-sm text-muted">Contact sellers, riders, buyers, and platform staff with live updates.</p></header>@include('partials.conversation-center',['sendRoute'=>route('logistics.messages.send'),'streamRoute'=>route('logistics.messages.stream'),'messageAttachmentsReady'=>$messageAttachmentsReady ?? false])</div>@endsection
