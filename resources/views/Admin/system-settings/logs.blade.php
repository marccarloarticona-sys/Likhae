@extends('layouts.admin')
@section('title','System Logs')
@section('active','system-settings')
@section('subtitle','Read-only, sanitized view of configured Laravel application logs.')
@push('head')@vite('resources/css/admin/system-settings.css')@endpush
@php
    $showTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y g:i:s A T') : 'Not available';
    $badge = fn ($severity) => in_array($severity, ['ERROR','CRITICAL','ALERT','EMERGENCY'], true) ? 'is-failed' : ($severity === 'WARNING' ? 'is-pending' : 'is-unknown');
@endphp
@section('content')
<div class="ad-ops-page">
    <section class="ad-page-head ad-ops-page-head"><div><span class="ad-overline">Laravel logging infrastructure</span><h1>System Logs</h1><p>Records are read from configured local single-file or daily log channels with bounded bytes and entry counts.</p></div><a class="ad-btn ad-btn-secondary" href="{{ route('admin.system-settings') }}">Back to System Settings</a></section>
    <form class="ad-card ad-card-body ad-ops-filters" method="GET" action="{{ route('admin.system-settings.logs') }}">
        <label>Severity<select name="severity"><option value="">All severities</option>@foreach(['EMERGENCY','ALERT','CRITICAL','ERROR','WARNING','NOTICE','INFO','DEBUG'] as $level)<option value="{{ $level }}" @selected(($filters['severity'] ?? '') === $level)>{{ $level }}</option>@endforeach</select></label>
        <label>From<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <label>Source<input type="text" name="source" maxlength="64" value="{{ $filters['source'] ?? '' }}" placeholder="Configured channel"></label>
        <label>HTTP status<input type="text" name="status" inputmode="numeric" maxlength="3" value="{{ $filters['status'] ?? '' }}" placeholder="When available"></label>
        <label class="ad-ops-filter-search">Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search safe summaries"></label>
        <div class="ad-ops-filter-actions"><button type="submit" class="ad-btn ad-btn-primary">Apply filters</button><a href="{{ route('admin.system-settings.logs') }}">Clear</a></div>
    </form>
    @if(!$result['available'])
        <div class="ad-note ad-ops-empty" role="status">{{ $result['reason'] ?? 'No compatible Laravel log source is available.' }} Central, stderr, and remote-only channels are not read by this viewer.</div>
    @elseif($entries->isEmpty())
        <div class="ad-note ad-ops-empty" role="status">The configured files are readable but contain no parseable records matching these filters.</div>
    @else
        <div class="ad-table-wrap ad-ops-table-wrap"><table class="ad-table ad-ops-table"><thead><tr><th>Occurred at</th><th>Severity</th><th>Safe summary</th><th>Channel</th><th>HTTP status</th></tr></thead><tbody>
        @foreach($entries as $entry)<tr><td>{{ $showTime($entry['timestamp']) }}</td><td><span class="ad-status ad-ops-status {{ $badge($entry['severity']) }}">{{ $entry['severity'] }}</span></td><td>{{ $entry['summary'] }}</td><td>{{ $entry['source'] }}</td><td>{{ $entry['http_status'] ?? '—' }}</td></tr>@endforeach
        </tbody></table></div>
        <div class="ad-pagination ad-ops-pagination">{{ $entries->links() }}</div>
    @endif
    <p class="ad-ops-footnote">Last checked {{ $showTime($result['checked_at']) }} · Files: {{ $result['sources'] ? implode(', ', $result['sources']) : 'None' }} · Bytes inspected: {{ number_format($result['bytes_read']) }} · {{ $result['partial'] ? 'Partial bounded tail.' : 'Bounded read.' }} Raw stack traces, contexts, filesystem paths, email addresses, and detected credentials are omitted or redacted.</p>
</div>
@endsection
