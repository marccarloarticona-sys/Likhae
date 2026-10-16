@extends('layouts.seller')
@section('title','Messages')
@section('active','messages')
@section('subtitle','Contact buyers, riders, logistics, and platform staff.')
@section('content')<div class="sl-page"><div class="sl-page-toolbar"><div><span class="sl-eyebrow">Communication</span><h2>Messages</h2><p>Live messages use Reverb with a lightweight fallback when disconnected.</p></div></div>@unless($messageAttachmentsReady)<div class="sl-card mb-4 text-sm">Image attachments will be available after the prepared message attachment migration is deployed.</div>@endunless @include('partials.conversation-center',['sendRoute'=>route('seller.messages.send'),'streamRoute'=>route('seller.messages.stream'),'sellerMessageAttachments'=>$messageAttachmentsReady,'sellerMessageMaxLength'=>1000])</div>@endsection
