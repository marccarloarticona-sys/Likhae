@extends('layouts.admin.app')
@section('title','Administrative Audit Logs')
@section('active','system-settings')
@section('subtitle','Read-only view of existing audit events attributed to Admin accounts.')
@push('head')@vite('resources/css/admin/system-settings.css')@endpush
@php $showTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y g:i:s A T') : 'Not available'; @endphp
@section('content')
<div class="ad-ops-page">
    <section class="ad-page-head ad-ops-page-head"><div><span class="ad-overline">Existing business audit records</span><h1>Administrative Audit Logs</h1><p>History is read from the existing audit table. This page does not edit or delete audit records. Checked at {{ $showTime($checkedAt) }}.</p></div><a class="ad-btn ad-btn-secondary" href="{{ route('admin.system-settings') }}">Back to System Settings</a></section>
    <form class="ad-card ad-card-body ad-ops-filters" method="GET" action="{{ route('admin.system-settings.audit-logs') }}">
        <label>Actor<select name="actor"><option value="">All Admin actors</option>@foreach($actors as $actor)<option value="{{ $actor->id }}" @selected((string)($filters['actor'] ?? '') === (string)$actor->id)>{{ $actor->name }}</option>@endforeach</select></label>
        <label>Action<select name="action"><option value="">All actions</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ str($action)->replace(['.', '_'], ' ')->headline() }}</option>@endforeach</select></label>
        <label>Entity type<select name="entity"><option value="">All entity types</option>@foreach($entities as $entity)<option value="{{ $entity }}" @selected(($filters['entity'] ?? '') === $entity)>{{ class_basename($entity) }}</option>@endforeach</select></label>
        <label>From<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <label class="ad-ops-filter-search">Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Action, actor, or entity"></label>
        <div class="ad-ops-filter-actions"><button type="submit" class="ad-btn ad-btn-primary">Apply filters</button><a href="{{ route('admin.system-settings.audit-logs') }}">Clear</a></div>
    </form>
    @if(!$available)
        <div class="ad-note ad-ops-empty" role="status">The existing audit history could not be read. No records are fabricated and no table was created.</div>
    @elseif($entries->isEmpty())
        <div class="ad-note ad-ops-empty" role="status">No recorded Admin actor events match these filters.</div>
    @else
        <div class="ad-table-wrap ad-ops-table-wrap"><table class="ad-table ad-ops-table"><thead><tr><th>Timestamp</th><th>Actor</th><th>Actor role</th><th>Action</th><th>Entity type</th><th>Reference</th><th>Outcome</th></tr></thead><tbody>
        @foreach($entries as $entry)<tr><td>{{ $showTime($entry->created_at) }}</td><td>{{ $entry->actor?->name ?? 'System / removed account' }}</td><td>{{ $entry->actor ? 'Admin' : '—' }}</td><td>{{ str($entry->event)->replace(['.', '_'], ' ')->headline() }}</td><td>{{ $entry->auditable_type ? class_basename($entry->auditable_type) : '—' }}</td><td>{{ $entry->auditable_id ?? '—' }}</td><td>Recorded event</td></tr>@endforeach
        </tbody></table></div>
        <div class="ad-pagination ad-ops-pagination">{{ $entries->links() }}</div>
    @endif
    <p class="ad-ops-footnote">Descriptions are limited to the recorded action and safe entity reference. Audit payloads, protected documents, IP addresses, and user-agent strings are withheld. The existing PDF export remains available from the Admin audit export route.</p>
</div>
@endsection
