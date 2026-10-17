@extends('layouts.admin')
@section('title','System Settings')
@section('active','system-settings')
@section('subtitle','Current database status, storage capacity, and Admin preferences.')
@push('head')@vite(['resources/css/shared/workspace-settings.css', 'resources/css/admin/system-settings.css'])@endpush

@php
    $statusClass = fn ($status) => match (strtoupper((string) $status)) {
        'HEALTHY', 'VALID' => 'is-clear',
        'WARNING', 'DEGRADED', 'EXPIRING', 'STALE', 'PARTIAL' => 'is-pending',
        'CRITICAL', 'DOWN', 'EXPIRED', 'ERROR', 'ALERT', 'EMERGENCY' => 'is-failed',
        'NOT CONFIGURED', 'DISABLED' => 'is-not-configured',
        default => 'is-unknown',
    };
    $showTime = function ($value) {
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->timezone(config('app.timezone'))->format('M j, Y g:i:s A T');
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return 'Not available';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y g:i:s A T');
        } catch (\Throwable) {
            return 'Not available';
        }
    };
    $formatBytes = function ($bytes) {
        if ($bytes === null || !is_numeric($bytes) || $bytes < 0) return 'Unknown';
        if ($bytes < 1024) return number_format((int) $bytes).' B';
        $size = (float) $bytes;
        foreach (['KB', 'MB', 'GB', 'TB', 'PB'] as $unit) {
            $size /= 1024;
            if ($size < 1024 || $unit === 'PB') return number_format($size, 2).' '.$unit;
        }
        return 'Unknown';
    };
    $storageRows = array_merge(array_values($storage['categories']), [
        [
            'label' => 'Application logs',
            'bytes' => $storage['logs']['bytes'],
            'files' => $storage['logs']['files'],
            'last_updated' => $storage['logs']['last_updated'],
            'status' => $storage['logs']['status'],
        ],
        [
            'label' => 'Cache / temporary storage',
            'bytes' => $storage['cache_temporary']['bytes'],
            'files' => $storage['cache_temporary']['files'],
            'last_updated' => $storage['cache_temporary']['last_updated'],
            'status' => $storage['cache_temporary']['status'],
        ],
    ]);
    $storageRows = array_values(array_filter($storageRows, fn ($row) => ($row['status'] ?? null) === 'HEALTHY' && $row['bytes'] !== null && $row['files'] !== null));
@endphp

@section('content')
<div class="ad-ops-page">
    <section class="ad-page-head ad-ops-page-head" aria-labelledby="system-health-title">
        <div>
            <span class="ad-overline">Operational monitoring</span>
            <h1 id="system-health-title">System Health Overview</h1>
            <p>Current database, application runtime, product safety, and storage capacity checks.</p>
        </div>
        <div class="ad-ops-heading-actions">
            <span class="ad-ops-last-checked">Last checked {{ $showTime($health['checked_at']) }}</span>
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.system-settings') }}" aria-label="Refresh current system health checks">Refresh status</a>
        </div>
    </section>

    <section class="ad-ops-grid ad-ops-health-grid" aria-label="System health and capacity">
        <article class="ad-card ad-ops-monitor-card">
            <header><div><span>Infrastructure</span><h2>Database</h2></div><strong class="ad-status ad-ops-status {{ $statusClass($health['database']['status']) }}">{{ $health['database']['status'] }}</strong></header>
            <dl>
                <div><dt>Driver</dt><dd>{{ $health['database']['driver'] }}</dd></div>
                <div><dt>Response time</dt><dd>{{ $health['database']['latency_ms'] === null ? 'Unknown' : number_format($health['database']['latency_ms'], 2).' ms' }}</dd></div>
            </dl>
            <p class="ad-ops-card-note">{{ $health['database']['message'] }}</p>
        </article>

        <article class="ad-card ad-ops-monitor-card">
            <header><div><span>Runtime</span><h2>Application</h2></div><strong class="ad-status ad-ops-status {{ $statusClass($health['application']['status']) }}">{{ $health['application']['status'] }}</strong></header>
            <dl>
                <div><dt>Environment</dt><dd>{{ $health['application']['environment'] }}</dd></div>
                <div><dt>Debug mode</dt><dd>{{ $health['application']['debug'] ? 'Enabled' : 'Disabled' }}</dd></div>
                <div><dt>PHP</dt><dd>{{ $health['application']['php'] }}</dd></div>
                <div><dt>Laravel</dt><dd>{{ $health['application']['laravel'] }}</dd></div>
            </dl>
            <p class="ad-ops-card-note">Runtime values come from the active application configuration. This check does not establish every application workflow.</p>
        </article>

        <article class="ad-card ad-ops-monitor-card">
            <header><div><span>Product safety</span><h2>Product Moderation AI</h2></div><strong class="ad-status ad-ops-status {{ $statusClass($productModerationHealth['status']) }}">{{ $productModerationHealth['status'] }}</strong></header>
            @if(session('status'))<p class="ad-ops-ai-feedback" role="status">{{ session('status') }}</p>@endif
            @if($errors->has('enabled'))<p class="ad-ops-ai-feedback is-error" role="alert">{{ $errors->first('enabled') }}</p>@endif
            <form method="POST" action="{{ route('admin.system-settings.product-moderation.update') }}" class="ad-ops-ai-toggle-form">
                @csrf
                @method('PATCH')
                <input type="hidden" name="enabled" value="{{ $productModerationHealth['enabled'] ? '0' : '1' }}">
                <div>
                    <strong>AI scanning is {{ $productModerationHealth['enabled'] ? 'on' : 'off' }}</strong>
                    <span>{{ $productModerationHealth['enabled'] ? ($productModerationHealth['configured'] ? 'New eligible listings are checked automatically.' : 'Enabled, but waiting for provider credentials.') : 'Automatic AI scans are paused.' }}</span>
                </div>
                <button type="submit" class="ad-ops-ai-toggle {{ $productModerationHealth['enabled'] ? 'is-on' : '' }}" role="switch" aria-checked="{{ $productModerationHealth['enabled'] ? 'true' : 'false' }}" aria-label="Turn Product Moderation AI {{ $productModerationHealth['enabled'] ? 'off' : 'on' }}">
                    <span class="ad-ops-ai-toggle-knob" aria-hidden="true"></span>
                    <span class="ad-ops-ai-toggle-label">Turn {{ $productModerationHealth['enabled'] ? 'off' : 'on' }}</span>
                </button>
            </form>
            @if($productModerationHealth['enabled'] && ! $productModerationHealth['configured'])
                <p class="ad-ops-ai-config-note">Scanning will start after provider credentials are configured.</p>
            @endif
            <dl>
                <div><dt>Provider</dt><dd>{{ $productModerationHealth['provider'] }}</dd></div>
                <div><dt>Primary model</dt><dd>{{ $productModerationHealth['primary_model'] }}</dd></div>
                <div><dt>Object model</dt><dd>{{ $productModerationHealth['object_model'] ?? 'Not configured' }}</dd></div>
                <div><dt>Primary credentials</dt><dd>{{ $productModerationHealth['primary_configured'] ? 'Configured' : 'Not configured' }}</dd></div>
                <div><dt>Backup credentials</dt><dd>{{ $productModerationHealth['fallback_configured'] ? 'Configured' : 'Not configured' }}</dd></div>
                <div><dt>Feature enabled</dt><dd>{{ $productModerationHealth['enabled'] ? 'Yes' : 'No' }}</dd></div>
                <div><dt>Scan storage</dt><dd>{{ $productModerationHealth['table_ready'] ? 'Ready' : 'Not ready' }}</dd></div>
                <div><dt>Last successful scan</dt><dd>{{ $showTime($productModerationHealth['last_successful_scan']) }}</dd></div>
                <div><dt>Failed/deferred scans, 24 hours</dt><dd>{{ $productModerationHealth['recent_failures_24h'] === null ? 'Unknown' : number_format($productModerationHealth['recent_failures_24h']) }}</dd></div>
                <div><dt>Quota status</dt><dd>{{ $productModerationHealth['quota_status'] }}</dd></div>
            </dl>
            <p class="ad-ops-card-note">Credentials are checked without displaying their values. Scan status uses the local scan ledger. Provider quota details are not available from this ledger.</p>
        </article>

        <article class="ad-card ad-ops-monitor-card ad-ops-storage-card" aria-labelledby="storage-capacity-title">
            <header class="ad-ops-storage-head">
                <div><span>Read-only capacity monitoring</span><h2 id="storage-capacity-title">Storage Usage &amp; Capacity</h2></div>
                <div class="ad-ops-storage-actions">
                    <strong class="ad-status ad-ops-status {{ $statusClass($storage['server_disk']['status']) }}">{{ $storage['server_disk']['status'] }}</strong>
                    <form method="POST" action="{{ route('admin.system-settings.storage.refresh') }}" class="ad-ops-inline-form">
                        @csrf
                        <button type="submit" class="ad-btn ad-btn-secondary">Refresh</button>
                    </form>
                </div>
            </header>
            <p class="ad-ops-footnote">
                Last checked {{ $showTime($storage['checked_at']) }}
                @if($storage['cached']) (cached, {{ number_format($storage['age_seconds'] / 60, 1) }} minutes old). @else (measured for this request). @endif
                Measurements are cached for up to five minutes.
            </p>
            @if($storageRefreshStatus)<div class="ad-note ad-ops-empty" role="status">{{ $storageRefreshStatus }}</div>@endif

            <dl>
                <div><dt>Total capacity</dt><dd>{{ $formatBytes($storage['server_disk']['total_bytes']) }}</dd></div>
                <div><dt>Used</dt><dd>{{ $formatBytes($storage['server_disk']['used_bytes']) }}</dd></div>
                <div><dt>Available</dt><dd>{{ $formatBytes($storage['server_disk']['available_bytes']) }}</dd></div>
                <div><dt>Usage</dt><dd>{{ $storage['server_disk']['usage_percent'] === null ? 'Unavailable' : number_format($storage['server_disk']['usage_percent'], 2).'% (warning at '.$storage['server_disk']['warning_percent'].'%, critical at '.$storage['server_disk']['critical_percent'].'%)' }}</dd></div>
            </dl>
            <p class="ad-ops-card-note">{{ $storage['server_disk']['source'] }} This volume reading may not reflect a hosting-provider quota.</p>

            <details class="ad-ops-storage-details">
                <summary><span>File Storage Breakdown</span><span>View measured directories</span></summary>
                <div class="ad-table-wrap ad-ops-table-wrap"><table class="ad-table ad-ops-table"><thead><tr><th>Storage category</th><th>Used</th><th>Files</th><th>Last updated</th><th>Status</th></tr></thead><tbody>
                @forelse($storageRows as $category)
                    <tr><td>{{ $category['label'] }}</td><td>{{ $formatBytes($category['bytes']) }}</td><td>{{ number_format($category['files']) }}</td><td>{{ $showTime($category['last_updated']) }}</td><td><span class="ad-status ad-ops-status {{ $statusClass($category['status']) }}">{{ $category['status'] }}</span></td></tr>
                @empty
                    <tr><td colspan="5">No configured storage directories could be measured.</td></tr>
                @endforelse
                </tbody></table></div>
                <p class="ad-ops-footnote">Directory sizes are not added to server disk usage; categories may share the same volume.</p>
            </details>
        </article>
    </section>

    <section class="ad-card ad-ops-section">
        <header class="ad-card-head ad-ops-section-head"><div><span>Application log source</span><h2>Recent System Errors</h2></div><div class="ad-ops-heading-actions"><a href="{{ route('admin.system-settings.errors') }}">View all errors</a><a href="{{ route('admin.system-settings.logs') }}">Open log viewer</a></div></header>
        <p class="ad-ops-footnote"><strong>{{ $errorSummary['status'] }}</strong> — {{ $errorSummary['message'] }} Checked at {{ $showTime($logCheckedAt) }}. Coverage starts {{ $showTime($errorSummary['coverage_start']) }}. Observed errors: last hour {{ $errorSummary['counts']['1 hour'] ?? 'Unknown' }}, 24 hours {{ $errorSummary['counts']['24 hours'] ?? 'Unknown' }}, 7 days {{ $errorSummary['counts']['7 days'] ?? 'Unknown' }}. These are bounded-tail counts, not proof of zero errors.</p>
        @if(!$logAvailable)
            <div class="ad-note ad-ops-empty" role="status">{{ $errorSummary['message'] }}</div>
        @elseif($recentErrors === [])
            <div class="ad-note ad-ops-empty" role="status">No error or warning entries were present in the readable log tail.</div>
        @else
            <div class="ad-table-wrap ad-ops-table-wrap"><table class="ad-table ad-ops-table"><thead><tr><th>Occurred at</th><th>Severity</th><th>Safe summary</th><th>Source</th></tr></thead><tbody>
            @foreach($recentErrors as $entry)<tr><td>{{ $showTime($entry['timestamp']) }}</td><td><span class="ad-status ad-ops-status {{ $statusClass($entry['severity']) }}">{{ $entry['severity'] }}</span></td><td>{{ $entry['summary'] }}</td><td>{{ $entry['source'] }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
    </section>

    <section class="ad-card ad-ops-section">
        <header class="ad-card-head ad-ops-section-head"><div><span>Existing business audit records</span><h2>Administrative Audit Logs</h2></div><a href="{{ route('admin.system-settings.audit-logs') }}">Open audit history</a></header>
        @if(!$auditAvailable)
            <div class="ad-note ad-ops-empty" role="status">Audit history is unavailable because the existing audit source could not be read.</div>
        @elseif($auditPreview->isEmpty())
            <div class="ad-note ad-ops-empty" role="status">No recorded Admin actor events are available.</div>
        @else
            <div class="ad-table-wrap ad-ops-table-wrap"><table class="ad-table ad-ops-table"><thead><tr><th>Timestamp</th><th>Actor</th><th>Action</th><th>Entity</th></tr></thead><tbody>
            @foreach($auditPreview as $audit)<tr><td>{{ $showTime($audit->created_at) }}</td><td>{{ $audit->actor?->name ?? 'System / removed account' }}</td><td>{{ str($audit->event)->replace(['.', '_'], ' ')->headline() }}</td><td>{{ $audit->auditable_type ? class_basename($audit->auditable_type) : '—' }}{{ $audit->auditable_id ? ' #'.$audit->auditable_id : '' }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
        <p class="ad-ops-footnote">This viewer uses the existing append-only audit records and filters to events attributed to Admin accounts. It does not display audit payloads, IP addresses, or user-agent values.</p>
    </section>

    <section class="ad-card ad-ops-section ws-personal-preferences">
        <header class="ad-card-head ad-ops-section-head"><div><span>Personal workspace experience</span><h2>Admin Preferences</h2></div></header>
        <x-workspace-settings :config="$settingsConfig" :preferences="$preferences" :show-heading="false" />
    </section>
</div>
@endsection
