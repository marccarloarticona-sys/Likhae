@extends('layouts.admin')
@section('title', str($type)->headline())
@section('content')
<div class="ad-page"><div class="ad-page-header"><div><p class="ad-eyebrow">Onboarding</p><h1>{{ str($type)->headline() }}</h1></div></div>
<form method="GET" class="ad-card ad-card-body ad-filter-bar"><select name="status" onchange="this.form.submit()">@foreach(['pending','approved','rejected','suspended'] as $s)<option @selected($status===$s) value="{{ $s }}">{{ str($s)->headline() }}</option>@endforeach</select></form>
<div class="ad-card ad-card-body">@forelse($records as $record)<div style="padding:18px 0;border-bottom:1px solid #e7e5e4"><strong>{{ $record->name }}</strong><div>{{ $record->owner?->name }} - {{ $record->owner?->email }}</div><p>{{ $record->description ?? $record->contact_phone }}</p>
<div class="ad-inline-actions"><a class="ad-btn ad-btn-secondary" href="{{ $type==='sellers'?route('admin.sellers.permit',$record):route('admin.couriers.document',$record) }}">Private document</a>
@if($record->status==='pending')<form method="POST" action="{{ route('admin.'.$type.'.approve',$record) }}">@csrf<button class="ad-btn ad-btn-primary">Approve</button></form><form method="POST" action="{{ route('admin.'.$type.'.reject',$record) }}">@csrf<input name="reason" maxlength="500" placeholder="Required rejection reason" required><button class="ad-btn ad-btn-danger-soft">Reject</button></form>@endif
@if($record->status==='approved')<form method="POST" action="{{ route('admin.'.$type.'.suspend',$record) }}">@csrf<button class="ad-btn ad-btn-danger-soft">Suspend</button></form>@endif
@if($record->status==='suspended')<form method="POST" action="{{ route('admin.'.$type.'.reinstate',$record) }}">@csrf<button class="ad-btn ad-btn-primary">Reinstate</button></form>@endif</div></div>@empty<p>No {{ $status }} applications.</p>@endforelse
{{ $records->links() }}</div></div>
@endsection
