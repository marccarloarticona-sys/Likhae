<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AuditLog;
use App\Models\Admin\PlatformSetting;
use App\Models\User;
use App\Services\Monitoring\LaravelLogReader;
use App\Services\Monitoring\StorageCapacityMonitor;
use App\Services\Monitoring\SystemHealthMonitor;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SystemSettingsMonitoringController extends Controller
{
    public function show(Request $request, SystemHealthMonitor $health, LaravelLogReader $logs, StorageCapacityMonitor $storageMonitor, ProductModerationService $moderation): View
    {
        $storage = $storageMonitor->snapshot([], false, false);

        $settingsConfig = $this->settingsConfig();
        $preferences = array_merge($this->preferenceDefaults($settingsConfig), (array) $request->user()->notification_preferences);
        $logResult = $logs->read(100);
        $errors = array_values(array_filter($logResult['entries'], fn (array $entry): bool => $this->isSystemError($entry)));
        $auditAvailable = true;
        $auditPreview = [];

        try {
            if (! Schema::hasTable('audit_logs')) {
                $auditAvailable = false;
            } else {
                $auditPreview = $this->auditQuery()->with('actor')->limit(5)->get();
            }
        } catch (Throwable) {
            $auditAvailable = false;
        }

        return view('Admin.system-settings.index', [
            'settingsConfig' => $settingsConfig,
            'preferences' => $preferences,
            'health' => $health->overview(),
            'realtimeTransport' => $this->realtimeTransportSnapshot(),
            'productModerationHealth' => $moderation->healthSnapshot(),
            'storage' => $storage,
            'storageRefreshStatus' => $request->hasSession() ? $request->session()->get('storage_refresh_status') : null,
            'recentErrors' => array_slice($errors, 0, 5),
            'errorSummary' => $this->errorSummary($logResult),
            'logAvailable' => $logResult['available'],
            'logCheckedAt' => $logResult['checked_at'],
            'auditAvailable' => $auditAvailable,
            'auditPreview' => $auditPreview,
        ]);
    }

    public function realtimeTransportStatus(): JsonResponse
    {
        return response()->json($this->realtimeTransportSnapshot())
            ->header('Cache-Control', 'private, no-store');
    }

    private function realtimeTransportSnapshot(): array
    {
        $connection = strtolower((string) config('broadcasting.default', ''));
        $reverb = (array) config('broadcasting.connections.reverb', []);
        $options = (array) ($reverb['options'] ?? []);
        $configured = $connection === 'reverb'
            && filled($reverb['key'] ?? null)
            && filled($reverb['secret'] ?? null)
            && filled($reverb['app_id'] ?? null)
            && filled($options['host'] ?? null)
            && (int) ($options['port'] ?? 0) > 0;

        return [
            'configured' => $configured,
            'broadcast_connection' => $connection !== '' ? $connection : 'not configured',
            'broadcast_unavailable' => Cache::get('reverb:broadcast-unavailable', false) === true,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function refreshStorage(StorageCapacityMonitor $storageMonitor): RedirectResponse
    {
        $storageMonitor->snapshot([], true, false);

        return redirect()->route('admin.system-settings')
            ->with('storage_refresh_status', 'Storage measurements were refreshed.');
    }

    public function updateProductModeration(Request $request, ProductModerationService $moderation): RedirectResponse
    {
        abort_unless(Schema::hasTable('platform_settings'), 503, 'Platform settings storage is not available.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = $request->boolean('enabled');
        $previous = $moderation->featureEnabled();
        $setting = PlatformSetting::put('product_moderation_enabled', $enabled, 'boolean', $request->user()->id);

        if ($previous !== $enabled && Schema::hasTable('audit_logs')) {
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event' => 'PRODUCT_MODERATION_AI_TOGGLED',
                'auditable_type' => PlatformSetting::class,
                'auditable_id' => $setting->id,
                'old_values' => ['enabled' => $previous],
                'new_values' => ['enabled' => $enabled],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        $message = 'Product Moderation AI '.($enabled ? 'enabled' : 'disabled').'.';
        if ($enabled && ! $moderation->hasConfiguredCredentials()) {
            $message .= ' Scans will remain unavailable until provider credentials are configured.';
        }

        return back()->with('status', $message);
    }

    public function errors(Request $request, LaravelLogReader $reader): View
    {
        $filters = $this->logFilters($request, true);
        $result = $reader->read();
        $entries = array_values(array_filter($result['entries'], fn (array $entry): bool => $this->isSystemError($entry)));
        $entries = $this->filterEntries($entries, $filters);
        $detail = null;

        if (isset($filters['entry'])) {
            foreach ($result['entries'] as $entry) {
                if (hash_equals($entry['id'], $filters['entry'])) {
                    $detail = $entry;
                    break;
                }
            }
            if ($detail !== null) {
                $this->recordViewerAccess($request, 'ADMIN_SYSTEM_ERROR_DETAILS_VIEWED', ['source' => 'laravel_errors', 'severity' => $detail['severity']]);
            }
        }
        $this->recordViewerAccess($request, 'ADMIN_SYSTEM_ERRORS_VIEWED', ['source' => 'laravel_errors', 'filters' => $this->filterNames($filters)]);

        return view('Admin.system-settings.errors', [
            'entries' => $this->paginate($entries, $request, 25),
            'filters' => $filters,
            'detail' => $detail,
            'result' => $result,
            'errorSummary' => $this->errorSummary($result),
        ]);
    }

    public function logs(Request $request, LaravelLogReader $reader): View
    {
        $filters = $this->logFilters($request, false);
        $result = $reader->read();
        $entries = $this->filterEntries($result['entries'], $filters);
        $this->recordViewerAccess($request, 'ADMIN_SYSTEM_LOG_VIEWER_ACCESSED', [
            'source' => 'laravel_logs',
            'filters' => $this->filterNames($filters),
        ]);

        return view('Admin.system-settings.logs', [
            'entries' => $this->paginate($entries, $request, 25),
            'filters' => $filters,
            'result' => $result,
        ]);
    }

    public function auditLogs(Request $request): View
    {
        $filters = $request->validate([
            'actor' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:120'],
            'entity' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $available = true;
        $entries = new Paginator([], 0, 25, Paginator::resolveCurrentPage(), ['path' => $request->url(), 'query' => $request->query()]);

        try {
            if (! Schema::hasTable('audit_logs')) {
                $available = false;
            } else {
                $query = $this->auditQuery()->with('actor')
                    ->when($filters['actor'] ?? null, fn (Builder $builder, string $actor) => $builder->where('actor_user_id', $actor))
                    ->when($filters['action'] ?? null, fn (Builder $builder, string $action) => $builder->where('event', $action))
                    ->when($filters['entity'] ?? null, fn (Builder $builder, string $entity) => $builder->where('auditable_type', $entity))
                    ->when($filters['from'] ?? null, fn (Builder $builder, string $date) => $builder->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                    ->when($filters['to'] ?? null, fn (Builder $builder, string $date) => $builder->where('created_at', '<=', Carbon::parse($date)->endOfDay()))
                    ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                        $search = trim(mb_substr(strip_tags($search), 0, 100));
                        if ($search === '') {
                            return;
                        }
                        $like = '%'.$search.'%';
                        $builder->where(function (Builder $nested) use ($like): void {
                            $nested->where('event', 'like', $like)
                                ->orWhere('auditable_type', 'like', $like)
                                ->orWhere('auditable_id', 'like', $like)
                                ->orWhereHas('actor', fn (Builder $actor) => $actor->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
                        });
                    })
                    ->latest('created_at')->latest('id');

                $entries = $query->paginate(25)->withQueryString();
            }
        } catch (Throwable) {
            $available = false;
        }

        $actors = [];
        $actions = [];
        $entities = [];
        if ($available) {
            try {
                $actors = User::query()->where('account_type', User::TYPE_ADMIN)->orderBy('first_name')->orderBy('last_name')->limit(200)->get(['id', 'first_name', 'last_name']);
                $actions = $this->auditQuery()->select('event')->distinct()->orderBy('event')->limit(100)->pluck('event');
                $entities = $this->auditQuery()->whereNotNull('auditable_type')->select('auditable_type')->distinct()->orderBy('auditable_type')->limit(100)->pluck('auditable_type');
            } catch (Throwable) {
                // Keep the read-only history usable if optional filter choices are unavailable.
            }
        }

        $checkedAt = now();

        return view('Admin.system-settings.audit-logs', compact('entries', 'filters', 'available', 'actors', 'actions', 'entities', 'checkedAt'));
    }

    private function settingsConfig(): array
    {
        return [
            'view' => 'admin.system-settings.index', 'center' => 'Admin Center', 'account_route' => 'admin.account',
            'update_route' => 'admin.system-settings.update', 'workspace' => 'admin', 'show_ai' => false,
            'sound_key' => 'admin_notification_sounds',
            'description' => 'Adjust your Admin Center appearance and notification sound preference.',
            'notifications' => [],
            'show_language' => false,
        ];
    }

    private function preferenceDefaults(array $config): array
    {
        $defaults = ['theme' => 'system', 'language' => 'en', $config['sound_key'] => true];
        foreach ($config['notifications'] as $notification) {
            $defaults[$notification[0]] = ($notification[3] ?? true) === true;
        }

        return $defaults;
    }

    private function auditQuery(): Builder
    {
        return AuditLog::query()->whereHas('actor', fn (Builder $query) => $query->where('account_type', User::TYPE_ADMIN));
    }

    private function logFilters(Request $request, bool $errorsOnly): array
    {
        $levels = $errorsOnly
            ? ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING']
            : ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];
        $filters = $request->validate([
            'severity' => ['nullable', Rule::in($levels)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'regex:/^[1-5][0-9]{2}$/'],
            'q' => ['nullable', 'string', 'max:100'],
            'entry' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        if (isset($filters['q'])) {
            $filters['q'] = trim(mb_substr(strip_tags($filters['q']), 0, 100));
        }

        return $filters;
    }

    private function filterEntries(array $entries, array $filters): array
    {
        return array_values(array_filter($entries, function (array $entry) use ($filters): bool {
            if (isset($filters['severity']) && $entry['severity'] !== $filters['severity']) {
                return false;
            }
            if (isset($filters['source']) && $entry['source'] !== $filters['source']) {
                return false;
            }
            if (isset($filters['status']) && (string) $entry['http_status'] !== (string) $filters['status']) {
                return false;
            }
            if (isset($filters['from']) && $entry['timestamp']->lt(Carbon::parse($filters['from'])->startOfDay())) {
                return false;
            }
            if (isset($filters['to']) && $entry['timestamp']->gt(Carbon::parse($filters['to'])->endOfDay())) {
                return false;
            }
            if (isset($filters['q']) && $filters['q'] !== '' && stripos($entry['summary'].' '.$entry['source'].' '.$entry['severity'], $filters['q']) === false) {
                return false;
            }

            return true;
        }));
    }

    private function paginate(array $items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, Paginator::resolveCurrentPage());

        return new Paginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function isSystemError(array $entry): bool
    {
        return in_array($entry['severity'], ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING'], true);
    }

    private function errorSummary(array $result): array
    {
        $counts = ['1 hour' => null, '24 hours' => null, '7 days' => null];
        $now = now();
        if ($result['available'] && $result['entries'] !== []) {
            $counts = ['1 hour' => 0, '24 hours' => 0, '7 days' => 0];
        }
        foreach ($result['entries'] as $entry) {
            if (! $this->isSystemError($entry)) {
                continue;
            }
            if ($entry['timestamp']->greaterThanOrEqualTo($now->copy()->subHour())) {
                $counts['1 hour']++;
            }
            if ($entry['timestamp']->greaterThanOrEqualTo($now->copy()->subDay())) {
                $counts['24 hours']++;
            }
            if ($entry['timestamp']->greaterThanOrEqualTo($now->copy()->subDays(7))) {
                $counts['7 days']++;
            }
        }

        return [
            'status' => ! $result['available'] || $result['entries'] === [] ? 'UNKNOWN' : 'PARTIAL',
            'counts' => $counts,
            'coverage_start' => $result['coverage_start'],
            'checked_at' => $result['checked_at'],
            'message' => ! $result['available']
                ? ($result['reason'] ?? 'Application logs are unavailable.')
                : ($result['entries'] === [] ? 'No parseable records are available; counts cannot be confirmed.' : 'Counts reflect only the bounded log tail currently available.'),
        ];
    }

    private function filterNames(array $filters): array
    {
        return array_values(array_intersect(array_keys($filters), ['severity', 'from', 'to', 'source', 'status', 'q']));
    }

    private function recordViewerAccess(Request $request, string $event, array $metadata): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }
            AuditLog::query()->create([
                'actor_user_id' => $request->user()?->id,
                'event' => $event,
                'auditable_type' => null,
                'auditable_id' => null,
                'new_values' => $metadata,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Monitoring remains read-only if access tracking is unavailable.
        }
    }
}
