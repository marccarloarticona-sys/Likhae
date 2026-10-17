<?php

namespace App\Services\Monitoring;

use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class StorageCapacityMonitor
{
    public function __construct(
        private readonly DatabaseTableStorageMonitor $databaseTables,
        private readonly LaravelLogReader $logs,
    ) {}

    public function snapshot(array $filters = [], bool $refresh = false, bool $includeDatabaseMetadata = true): array
    {
        $files = $this->fileSnapshot($refresh);

        $snapshot = [
            'checked_at' => $files['checked_at'],
            'cached' => $files['cached'],
            'age_seconds' => $files['age_seconds'],
            'server_disk' => $files['server_disk'],
            'uploads' => $files['uploads'],
            'private_documents' => $files['private_documents'],
            'categories' => $files['categories'],
            'logs' => $files['logs'],
            'cache_temporary' => $files['cache_temporary'],
        ];

        if ($includeDatabaseMetadata) {
            $snapshot['database'] = $this->databaseTables->snapshot($filters, $refresh);
        }

        return $snapshot;
    }

    public function formatBytes(?int $bytes): string
    {
        return $this->databaseTables->formatBytes($bytes);
    }

    private function fileSnapshot(bool $refresh): array
    {
        $key = (string) config('system_monitoring.storage_snapshot_cache_key', 'likhae:system-health:storage:v1').':files';
        if (! $refresh) {
            try {
                $cached = Cache::store()->get($key);
                if (is_array($cached)) {
                    return $this->withFreshness($cached, true);
                }
            } catch (Throwable) {
                // Continue with bounded read-only measurements when cache is unavailable.
            }
        }

        try {
            $measurement = $this->measureFiles();
        } catch (Throwable) {
            $measurement = $this->unavailableFiles();
        }
        $measurement['checked_at'] = now()->toIso8601String();
        $ttl = max(30, min(1800, (int) config('system_monitoring.storage_snapshot_ttl_seconds', 300)));
        try {
            Cache::store()->put($key, $measurement, now()->addSeconds($ttl));
        } catch (Throwable) {
            // Current response still carries the measurement if its cache cannot be written.
        }

        return $this->withFreshness($measurement, false);
    }

    private function measureFiles(): array
    {
        $serverDisk = $this->serverDisk();
        $budget = [
            'entries' => 0,
            'max_entries' => max(1, min(50000, (int) config('system_monitoring.storage_scan_max_entries', 10000))),
            'deadline' => hrtime(true) + max(1, min(5, (int) config('system_monitoring.storage_scan_timeout_seconds', 2))) * 1_000_000_000,
            'timed_out' => false,
        ];
        $seenFiles = [];
        $categories = [];

        foreach ((array) config('system_monitoring.storage_file_categories', []) as $key => $category) {
            $categories[$key] = $this->scanDiskCategory($category, $budget, $seenFiles);
        }

        $uploadsComplete = $categories !== [];
        $uploadBytes = 0;
        $uploadFiles = 0;
        foreach ($categories as $category) {
            if ($category['status'] !== 'HEALTHY' || $category['bytes'] === null || $category['files'] === null) {
                $uploadsComplete = false;

                continue;
            }
            $uploadBytes += $category['bytes'];
            $uploadFiles += $category['files'];
        }

        $privateIds = ['compliance_documents', 'registration_documents'];
        $privateMeasured = collect($privateIds)->map(fn (string $id) => $categories[$id] ?? null)->filter();
        $privateComplete = $privateMeasured->count() === count($privateIds)
            && $privateMeasured->every(fn (array $category): bool => $category['status'] === 'HEALTHY');

        $logs = $this->logs->storageInventory();
        [$cachePaths, $unsupportedCache] = $this->configuredCacheTemporaryPaths();
        $cacheTemporary = $this->scanStoragePaths($cachePaths, $budget, $seenFiles);
        if ($unsupportedCache) {
            $cacheTemporary['status'] = 'UNKNOWN';
            $cacheTemporary['bytes'] = null;
            $cacheTemporary['files'] = null;
            $cacheTemporary['message'] = 'The configured cache backend is not a measurable local filesystem store.';
        }

        return [
            'server_disk' => $serverDisk,
            'uploads' => [
                'status' => $uploadsComplete ? 'HEALTHY' : 'UNKNOWN',
                'bytes' => $uploadsComplete ? $uploadBytes : null,
                'files' => $uploadsComplete ? $uploadFiles : null,
                'message' => $uploadsComplete
                    ? 'All configured upload categories were measured without following symbolic links.'
                    : 'One or more configured upload categories could not be completely measured.',
            ],
            'private_documents' => [
                'status' => $privateComplete ? 'HEALTHY' : 'UNKNOWN',
                'bytes' => $privateComplete ? $privateMeasured->sum('bytes') : null,
                'files' => $privateComplete ? $privateMeasured->sum('files') : null,
                'message' => $privateComplete ? 'Compliance and registration document directories were measured.' : 'Private document usage is incomplete or unavailable.',
            ],
            'categories' => $categories,
            'logs' => $logs,
            'cache_temporary' => $cacheTemporary,
        ];
    }

    private function serverDisk(): array
    {
        $path = storage_path();
        $total = $this->safeBytes($this->diskTotalSpace($path));
        $available = $this->safeBytes($this->diskFreeSpace($path));
        if ($total === null || $available === null || $total <= 0 || $available > $total) {
            return [
                'status' => 'UNKNOWN', 'total_bytes' => null, 'used_bytes' => null,
                'available_bytes' => null, 'usage_percent' => null,
                'source' => 'Filesystem volume; provider quota may differ.',
            ];
        }

        $used = $total - $available;
        $percentage = round(($used / $total) * 100, 2);
        $warning = max(1, min(99, (int) config('system_monitoring.storage_warning_percent', 80)));
        $critical = max($warning + 1, min(100, (int) config('system_monitoring.storage_critical_percent', 90)));
        $status = $percentage >= $critical ? 'CRITICAL' : ($percentage >= $warning ? 'WARNING' : 'HEALTHY');

        return [
            'status' => $status,
            'total_bytes' => $total,
            'used_bytes' => $used,
            'available_bytes' => $available,
            'usage_percent' => $percentage,
            'warning_percent' => $warning,
            'critical_percent' => $critical,
            'source' => 'Filesystem volume; provider quota may differ.',
        ];
    }

    protected function diskTotalSpace(string $path): int|float|false
    {
        return @disk_total_space($path);
    }

    protected function diskFreeSpace(string $path): int|float|false
    {
        return @disk_free_space($path);
    }

    private function scanDiskCategory(mixed $category, array &$budget, array &$seenFiles): array
    {
        if (! is_array($category) || ! is_string($category['disk'] ?? null) || ! is_string($category['path'] ?? null)) {
            return $this->unknownCategory('Storage category is not configured safely.');
        }
        $diskName = $category['disk'];
        $disk = config('filesystems.disks.'.$diskName);
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ! is_string($disk['root'] ?? null)) {
            return $this->unknownCategory('Configured storage is not a measurable local disk.');
        }

        $relative = $this->safeRelativePath($category['path']);
        if ($relative === null) {
            return $this->unknownCategory('Storage category path is not safe to inspect.');
        }

        $measurement = $this->scanDirectory((string) $disk['root'], $relative, $budget, $seenFiles);
        $measurement['label'] = (string) ($category['label'] ?? 'Storage category');

        return $measurement;
    }

    private function configuredCacheTemporaryPaths(): array
    {
        $paths = [];
        $unsupported = false;
        $cacheName = (string) config('cache.default', '');
        $cache = config('cache.stores.'.$cacheName);
        $cacheDriver = is_array($cache) ? ($cache['driver'] ?? $cacheName) : $cacheName;
        if ($cacheDriver === 'file' && is_array($cache) && is_string($cache['path'] ?? null)) {
            $paths[] = $cache['path'];
        } elseif (! in_array($cacheDriver, ['array'], true)) {
            $unsupported = true;
        }

        if (config('session.driver') === 'file' && is_string(config('session.files'))) {
            $paths[] = (string) config('session.files');
        }
        if (is_string(config('view.compiled')) && config('view.compiled') !== '') {
            $paths[] = (string) config('view.compiled');
        }
        foreach ((array) config('system_monitoring.storage_cache_paths', []) as $path) {
            if (is_string($path)) {
                $path = str_replace(['/', chr(92)], DIRECTORY_SEPARATOR, $path);
                if (str_starts_with(strtolower($path), strtolower('storage'.DIRECTORY_SEPARATOR))) {
                    $path = storage_path(substr($path, strlen('storage'.DIRECTORY_SEPARATOR)));
                } elseif (! str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:/', $path) !== 1) {
                    $path = storage_path($path);
                }
                $paths[] = $path;
            }
        }

        $storageRoot = realpath(storage_path());
        $relativePaths = [];
        foreach (array_unique($paths) as $path) {
            if (! is_string($path) || $storageRoot === false) {
                $unsupported = true;

                continue;
            }
            $normalizedPath = rtrim(str_replace(['/', chr(92)], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
            $prefix = rtrim($storageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if (! str_starts_with(strtolower($normalizedPath), strtolower($prefix))) {
                $unsupported = true;

                continue;
            }
            $relative = substr($normalizedPath, strlen($prefix));
            $safeRelative = $this->safeRelativePath($relative);
            if ($safeRelative !== null) {
                $relativePaths[] = $safeRelative;
            }
        }

        return [array_values(array_unique($relativePaths)), $unsupported];
    }

    private function scanStoragePaths(array $paths, array &$budget, array &$seenFiles): array
    {
        $totalBytes = 0;
        $totalFiles = 0;
        $lastUpdated = null;
        $allMeasured = $paths !== [];
        foreach ($paths as $relative) {
            if (! is_string($relative)) {
                $allMeasured = false;

                continue;
            }
            $part = $this->scanDirectory(storage_path(), $relative, $budget, $seenFiles);
            if ($part['status'] !== 'HEALTHY') {
                $allMeasured = false;

                continue;
            }
            $totalBytes += $part['bytes'];
            $totalFiles += $part['files'];
            if ($part['last_updated'] !== null && ($lastUpdated === null || $part['last_updated']->greaterThan($lastUpdated))) {
                $lastUpdated = $part['last_updated'];
            }
        }

        return [
            'status' => $allMeasured ? 'HEALTHY' : 'UNKNOWN',
            'bytes' => $allMeasured ? $totalBytes : null,
            'files' => $allMeasured ? $totalFiles : null,
            'last_updated' => $lastUpdated,
            'message' => $allMeasured ? 'Configured cache and temporary directories were measured.' : 'One or more cache or temporary directories could not be completely measured.',
        ];
    }

    private function scanDirectory(string $rootPath, string $relative, array &$budget, array &$seenFiles): array
    {
        $unknown = fn (string $message) => [
            'status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'last_updated' => null, 'message' => $message,
        ];
        if ($budget['timed_out'] || hrtime(true) >= $budget['deadline'] || $budget['entries'] >= $budget['max_entries']) {
            $budget['timed_out'] = true;

            return $unknown('Measurement exceeded its configured directory or time bound.');
        }

        $root = realpath($rootPath);
        if ($root === false || is_link($rootPath) || ! is_dir($root) || ! is_readable($root)) {
            return $unknown('Configured storage root is missing or unreadable.');
        }
        $targetPath = rtrim($rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
        if ($this->containsSymlink($rootPath, $relative)) {
            return $unknown('Symbolic-link paths are excluded from storage measurements.');
        }
        $target = realpath($targetPath);
        if ($target === false || ! is_dir($target) || ! is_readable($target) || ! $this->within($target, $root)) {
            return $unknown('Configured storage category is missing or unreadable.');
        }

        $bytes = 0;
        $files = 0;
        $lastUpdated = null;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                if (hrtime(true) >= $budget['deadline'] || $budget['entries'] >= $budget['max_entries']) {
                    $budget['timed_out'] = true;

                    return $unknown('Measurement exceeded its configured directory or time bound.');
                }
                $budget['entries']++;
                if ($entry->isLink() || ! $entry->isFile()) {
                    continue;
                }
                $real = realpath($entry->getPathname());
                if ($real === false || is_link($entry->getPathname()) || ! $this->within($real, $root)) {
                    continue;
                }
                if (isset($seenFiles[$real])) {
                    continue;
                }
                $size = @filesize($real);
                $modified = @filemtime($real);
                if (! is_int($size) || $size < 0 || ! is_int($modified)) {
                    return $unknown('One or more file metadata values could not be read.');
                }
                $seenFiles[$real] = true;
                $bytes += $size;
                $files++;
                $modifiedAt = Carbon::createFromTimestamp($modified);
                if ($lastUpdated === null || $modifiedAt->greaterThan($lastUpdated)) {
                    $lastUpdated = $modifiedAt;
                }
            }
        } catch (Throwable) {
            return $unknown('Directory contents could not be read safely.');
        }

        return [
            'status' => 'HEALTHY', 'bytes' => $bytes, 'files' => $files,
            'last_updated' => $lastUpdated, 'message' => 'Directory metadata measured within configured bounds.',
        ];
    }

    private function safeRelativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));
        if ($segments === [] || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function containsSymlink(string $rootPath, string $relative): bool
    {
        $current = rtrim($rootPath, DIRECTORY_SEPARATOR);
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    private function within(string $path, string $root): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $path === $root || str_starts_with($path, $prefix);
    }

    private function safeBytes(mixed $value): ?int
    {
        if ((! is_int($value) && ! is_float($value)) || $value < 0 || $value > PHP_INT_MAX) {
            return null;
        }

        return (int) $value;
    }

    private function unknownCategory(string $message): array
    {
        return ['status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'last_updated' => null, 'message' => $message];
    }

    private function withFreshness(array $result, bool $cached): array
    {
        try {
            $checkedAt = Carbon::parse($result['checked_at'] ?? now());
            $result['checked_at'] = $checkedAt;
            $result['age_seconds'] = max(0, now()->getTimestamp() - $checkedAt->getTimestamp());
            $result['cached'] = $cached;
        } catch (Throwable) {
            $result['checked_at'] = null;
            $result['age_seconds'] = null;
            $result['cached'] = false;
        }

        return $result;
    }

    private function unavailableFiles(): array
    {
        return [
            'server_disk' => [
                'status' => 'UNKNOWN', 'total_bytes' => null, 'used_bytes' => null,
                'available_bytes' => null, 'usage_percent' => null,
                'source' => 'Filesystem volume; provider quota may differ.',
            ],
            'uploads' => ['status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'message' => 'Storage measurement could not be completed safely.'],
            'private_documents' => ['status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'message' => 'Private document usage could not be measured.'],
            'categories' => [],
            'logs' => ['status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'last_updated' => null, 'retention_days' => null, 'checked_at' => now(), 'message' => 'Log storage metadata could not be measured.'],
            'cache_temporary' => ['status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'last_updated' => null, 'message' => 'Cache and temporary storage could not be measured.'],
        ];
    }
}
