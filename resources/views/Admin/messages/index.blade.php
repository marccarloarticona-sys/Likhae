@extends('layouts.admin')
@section('title','Messages & Announcements')
@section('subtitle','Database-backed platform communication.')
@section('active','messages')
@section('content')
<div style="display:grid;gap:16px"><div class="ad-page-head"><div><span class="ad-overline">Communication</span><h2>Messages and Announcements</h2></div></div>
<div class="ad-card ad-card-body">@include('partials.conversation-center',['sendRoute'=>route('admin.messages.send'),'streamRoute'=>route('admin.messages.stream')])</div>
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px"><section class="ad-card ad-card-body"><h2>Post Announcement</h2><form method="POST" action="{{ route('admin.announcements.store') }}" style="display:grid;gap:12px;margin-top:12px">@csrf<label class="ad-field"><span>Title</span><input name="title" required maxlength="255"></label><label class="ad-field"><span>Message</span><textarea name="body" rows="5" required></textarea></label><label class="ad-field"><span>Publish at</span><input type="datetime-local" name="published_at"></label><label class="ad-field"><span>Expires at</span><input type="datetime-local" name="expires_at"></label><button class="ad-btn ad-btn-primary">Save Announcement</button></form></section><section class="ad-card ad-card-body"><h2>Announcement History</h2><div style="display:grid;gap:10px;margin-top:12px">@forelse($announcements as $announcement)<article style="border:1px solid var(--ad-border);padding:12px"><strong>{{ $announcement->title }}</strong><p>{{ $announcement->body }}</p><small>{{ $announcement->created_at?->format('M d, Y H:i') }}</small></article>@empty<p>No announcements yet.</p>@endforelse</div>{{ $announcements->links() }}</section></div></div>
@endsection
