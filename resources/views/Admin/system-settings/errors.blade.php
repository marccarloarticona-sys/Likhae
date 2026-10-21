@extends('layouts.admin.app')
@section('title','System Errors')
@section('active','system-settings')
@section('subtitle','Sanitized application error records from configured Laravel log files.')
@push('head')@vite('resources/css/admin/system-settings.css')@endpush
@php
    $showTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y g:i:s A T') : 'Not available';
    $badge = fn ($severity) => in_array($severity, ['ERROR','CRITICAL','ALERT','EMERGENCY'], true) ? 'is-failed' : ($severity === 'WARNING' ? 'is-pending' : 'is-unknown');
@endphp
@section('content')
<div class="ad-ops-page">
    <section class="ad-page-head ad-ops-page-head"><div><span class="ad-overline">Application log source</span><h1>Recent System Errors</h1><p>Only bounded, parsed log summaries are shown. Raw context and stack traces are not sent to the browser.</p></div><a class="ad-btn ad-btn-secondary" href="{{ route('admin.system-settings') }}">Back to System Settings</a></section>
    <div class="ad-note ad-ops-summary"><strong>{{ $errorSummary['status'] }}</strong><span>{{ $errorSummary['message'] }}</span><span>Checked at {{ $showTime($result['checked_at']) }}. Coverage starts {{ $showTime($errorSummary['coverage_start']) }}. Observed in available tail: 1 hour {{ $errorSummary['counts']['1 hour'] ?? 'Unknown' }}, 24 hours {{ $errorSummary['counts']['24 hours'] ?? 'Unknown' }}, 7 days {{ $errorSummary['counts']['7 days'] ?? 'Unknown' }}.</span></div>
    <form class="ad-card ad-card-body ad-ops-filters" method="GET" action="{{ route('admin.system-settings.errors') }}">
        <label>Severity<select name="severity"><option value="">All severities</option>@foreach(['EMERGENCY','ALERT','CRITICAL','ERROR','WARNING'] as $level)<option value="{{ $level }}" @selected(($filters['severity'] ?? '') === $level)>{{ $level }}</option>@endforeach</select></label>
        <label>From<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <label>Source<input type="text" name="source" maxlength="64" value="{{ $filters['source'] ?? '' }}" placeholder="Configured channel"></label>
        <label>HTTP status<input type="text" name="status" inputmode="numeric" maxlength="3" value="{{ $filters['status'] ?? '' }}" placeholder="When available"></label>
        <label class="ad-ops-filter-search">Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search safe summaries"></label>
        <div class="ad-ops-filter-actions"><button type="submit" class="ad-btn ad-btn-primary">Apply filters</button><a href="{{ route('admin.system-settings.errors') }}">Clear</a></div>
    </form>

    @if($detail)
        <article class="ad-card ad-ops-detail" aria-labelledby="error-detail-title">
            <header><div><span>Sanitized record</span><h2 id="error-detail-title">Error details</h2></div><a href="{{ route('admin.system-settings.errors', collect($filters)->except('entry')->all()) }}">Close details</a></header>
            <dl><div><dt>Occurred at</dt><dd>{{ $showTime($detail['timestamp']) }}</dd></div><div><dt>Severity</dt><dd>{{ $detail['severity'] }}</dd></div><div><dt>Source</dt><dd>{{ $detail['source'] }}</dd></div><div><dt>Exception class</dt><dd>{{ $detail['exception_class'] ?? 'Not available in safe summary' }}</dd></div><div><dt>HTTP status</dt><dd>{{ $detail['http_status'] ?? 'Not available' }}</dd></div><div><dt>Route / request path</dt><dd>{{ $detail['route'] ?? 'Not available' }}</dd></div><div><dt>Request ID</dt><dd>{{ $detail['request_id'] ?? 'Not available' }}</dd></div><div class="is-full"><dt>Safe summary</dt><dd>{{ $detail['summary'] }}</dd></div><div class="is-full"><dt>Diagnostic context</dt><dd>Raw exception traces and log context are withheld from this viewer.</dd></div></dl>
        </article>
    @elseif(isset($filters['entry']))
        <div class="ad-note ad-ops-empty" role="status">That record is no longer present in the bounded readable log tail.</div>
    @endif

    @if(!$result['available'])
        <div class="ad-note ad-ops-empty" role="status">{{ $result['reason'] ?? 'No compatible Laravel log source is available.' }}</div>
    @elseif($entries->isEmpty())
        <div class="ad-note ad-ops-empty" role="status">No entries match these filters in the available log tail. Summary coverage remains {{ $errorSummary['status'] }}.</div>
    @else
        <div class="ad-table-wrap ad-ops-table-wrap"><table class="ad-table ad-ops-table"><thead><tr><th>Occurred at</th><th>Severity</th><th>Safe error summary</th><th>Exception class</th><th>HTTP status</th><th>Source</th><th>Details</th></tr></thead><tbody>
        @foreach($entries as $entry)<tr><td>{{ $showTime($entry['timestamp']) }}</td><td><span class="ad-status ad-ops-status {{ $badge($entry['severity']) }}">{{ $entry['severity'] }}</span></td><td>{{ $entry['summary'] }}</td><td>{{ $entry['exception_class'] ?? '—' }}</td><td>{{ $entry['http_status'] ?? '—' }}</td><td>{{ $entry['source'] }}</td><td><a href="{{ route('admin.system-settings.errors', array_merge($filters, ['entry' => $entry['id']])) }}">Open</a></td></tr>@endforeach
        </tbody></table></div>
        <div class="ad-pagination ad-ops-pagination">{{ $entries->links() }}</div>
    @endif
    <p class="ad-ops-footnote">Configured sources: {{ $result['sources'] ? implode(', ', $result['sources']) : 'None' }}. Bytes inspected: {{ number_format($result['bytes_read']) }}. {{ $result['partial'] ? 'The inspected tail is partial and cannot establish zero errors or full retention coverage.' : 'The available source was read within configured bounds.' }}</p>
</div>
@endsection
