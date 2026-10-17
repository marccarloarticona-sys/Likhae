@extends('layouts.admin')
@section('title','Messages')
@section('subtitle','Direct conversations between platform users and staff.')
@section('active','messages')
@section('content')
<div style="display:grid;gap:16px"><div class="ad-page-head"><div><span class="ad-overline">Communication</span><h2>Messages</h2><p>View and respond to existing direct conversations.</p></div></div>
<div class="ad-card ad-card-body">@include('partials.conversation-center',['sendRoute'=>route('admin.messages.send'),'streamRoute'=>route('admin.messages.stream'),'messageAttachmentsReady'=>$messageAttachmentsReady ?? false])</div></div>
@endsection
