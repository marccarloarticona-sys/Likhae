<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class DatabaseTableStorageMonitor
{
    public function snapshot(array $filters = [], bool $refresh = false): array
    {
        $search = $this->normalizeSearch($filters['table_search'] ?? '');
        $requestedSort = $filters['table_sort'] ?? 'size';
        $sort = in_array($requestedSort, ['size', 'rows'], true) ? $requestedSort : 'size';
        $page = max(1, min(100000, (int) ($filters['table_page'] ?? 1)));
        $pageSize = max(10, min(100, (int) config('system_monitoring.storage_table_page_size', 25)));
        $cacheKey = rtrim((string) config('system_monitoring.storage_snapshot_cache_key', 'likhae:system-health:storage:v1'), ':')
            .':database:'.hash('sha256', json_encode([$search, $sort, $page], JSON_THROW_ON_ERROR));

        if (! $refresh) {
            try {
                $cached = Cache::store()->get($cacheKey);
                if (is_array($cached)) {
                    return $this->withFreshness($cached, true);
                }
            } catch (Throwable) {
                // A cache failure falls through to a bounded metadata query.
            }
        }

        $result = $this->measure($search, $sort, $page, $pageSize);
        $ttl = max(30, min(1800, (int) config('system_monitoring.storage_snapshot_ttl_seconds', 300)));
        try {
            Cache::store()->put($cacheKey, $result, now()->addSeconds($ttl));
        } catch (Throwable) {
            // Monitoring results remain available for this response when cache is unavailable.
        }

        return $this->withFreshness($result, false);
    }

    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return 'Unknown';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $size = (float) $bytes;
        foreach ($units as $unit) {
            $size /= 1024;
            if ($size < 1024 || $unit === 'PB') {
                return number_format($size, 2).' '.$unit;
            }
        }

        return 'Unknown';
    }

    private function measure(string $search, string $sort, int $page, int $pageSize): array
    {
        $driver = strtolower((string) config('database.default', ''));
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->unavailable('NOT CONFIGURED', 'Table size metadata is supported for the configured MySQL-compatible database only.');
        }

        $connectionConfig = config('database.connections.'.$driver);
        if (! is_array($connectionConfig)) {
            return $this->unavailable('UNKNOWN', 'Configured database metadata connection could not be prepared.');
        }

        $timeout = max(1, min(5, (int) config('system_monitoring.storage_table_metadata_timeout_seconds', 2)));
        $options = (array) ($connectionConfig['options'] ?? []);
        $options[PDO::ATTR_TIMEOUT] = $timeout;
        if (defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $options[constant('PDO::MYSQL_ATTR_READ_TIMEOUT')] = $timeout;
        }
        $connectionConfig['options'] = $options;
        $connectionConfig['name'] = 'likhae_storage_metadata_probe';

        try {
            $connection = DB::build($connectionConfig);
        } catch (Throwable) {
            return $this->unavailable('UNKNOWN', 'Database table metadata could not be connected safely.');
        }

        try {
            $schema = (string) $connection->getDatabaseName();
            if ($schema === '') {
                return $this->unavailable('UNKNOWN', 'The configured database schema could not be identified.');
            }

            $summary = $connection->select(
                "SELECT COUNT(*) AS table_count, COALESCE(SUM(DATA_LENGTH), 0) AS data_bytes, COALESCE(SUM(INDEX_LENGTH), 0) AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
                [$schema],
            )[0] ?? null;
            if (! is_object($summary)) {
                return $this->unavailable('UNKNOWN', 'Database table metadata returned an invalid summary.');
            }

            $tableCount = $this->safeInteger($summary->table_count ?? null);
            $dataBytes = $this->safeInteger($summary->data_bytes ?? null);
            $indexBytes = $this->safeInteger($summary->index_bytes ?? null);
            if ($tableCount === null || $dataBytes === null || $indexBytes === null) {
                return $this->unavailable('UNKNOWN', 'Database table metadata returned invalid numeric values.');
            }

            $where = "TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'";
            $bindings = [$schema];
            if ($search !== '') {
                $where .= ' AND TABLE_NAME LIKE ?';
                $bindings[] = '%'.$search.'%';
            }

            $matchingCount = $connection->select(
                'SELECT COUNT(*) AS matching_count FROM information_schema.TABLES WHERE '.$where,
                $bindings,
            )[0]->matching_count ?? null;
            $matchingCount = $this->safeInteger($matchingCount);
            if ($matchingCount === null) {
                return $this->unavailable('UNKNOWN', 'Database table metadata returned an invalid matching table count.');
            }

            $lastPage = max(1, (int) ceil($matchingCount / $pageSize));
            $page = min($page, $lastPage);
            $order = $sort === 'rows'
                ? 'COALESCE(TABLE_ROWS, 0) DESC, TABLE_NAME ASC'
                : '(COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) DESC, TABLE_NAME ASC';
            $tables = $connection->select(
                'SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE '.$where.' ORDER BY '.$order.' LIMIT ? OFFSET ?',
                [...$bindings, $pageSize, ($page - 1) * $pageSize],
            );
            $topTables = $connection->select(
                "SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) DESC, TABLE_NAME ASC LIMIT 10",
                [$schema],
            );

            return [
                'status' => 'HEALTHY',
                'message' => 'MySQL information_schema metadata is available. InnoDB row and size values are estimates.',
                'driver' => $driver === 'mariadb' ? 'MariaDB' : 'MySQL',
                'estimated_database_bytes' => $this->safeAdd($dataBytes, $indexBytes),
                'estimated_data_bytes' => $dataBytes,
                'estimated_index_bytes' => $indexBytes,
                'table_count' => $tableCount,
                'matching_count' => $matchingCount,
                'tables' => $this->normalizeRows($tables),
                'top_tables' => $this->normalizeRows($topTables),
                'sort' => $sort,
                'search' => $search,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $pageSize,
                'source' => 'information_schema.TABLES',
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable) {
            return $this->unavailable('UNKNOWN', 'Database table metadata could not be read with the configured permissions.');
        } finally {
            if (isset($connection)) {
                $connection->disconnect();
            }
        }
    }

    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $name = $row->table_name ?? null;
            if (! is_string($name) || preg_match('/^[A-Za-z0-9_$-]{1,128}$/', $name) !== 1) {
                continue;
            }
            $dataBytes = $this->safeInteger($row->data_bytes ?? null);
            $indexBytes = $this->safeInteger($row->index_bytes ?? null);
            $rowCount = $this->safeInteger($row->estimated_rows ?? null);
            $totalBytes = $dataBytes !== null && $indexBytes !== null ? $this->safeAdd($dataBytes, $indexBytes) : null;
            $engine = $row->engine ?? null;
            $engine = is_string($engine) && preg_match('/^[A-Za-z0-9_ -]{1,32}$/', $engine) === 1 ? $engine : null;

            $normalized[] = [
                'table_name' => $name,
                'engine' => $engine,
                'estimated_rows' => $rowCount,
                'data_bytes' => $dataBytes,
                'index_bytes' => $indexBytes,
                'total_bytes' => $totalBytes,
                'data_human' => $this->formatBytes($dataBytes),
                'index_human' => $this->formatBytes($indexBytes),
                'total_human' => $this->formatBytes($totalBytes),
            ];
        }

        return $normalized;
    }

    private function safeInteger(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/', $value) !== 1)) {
            return null;
        }
        if ((float) $value > PHP_INT_MAX) {
            return null;
        }

        return (int) $value;
    }

    private function safeAdd(int $left, int $right): ?int
    {
        if ($right > PHP_INT_MAX - $left) {
            return null;
        }

        return $left + $right;
    }

    private function normalizeSearch(mixed $search): string
    {
        if (! is_string($search)) {
            return '';
        }

        return mb_substr(preg_replace('/[^A-Za-z0-9_$-]/', '', trim($search)) ?? '', 0, 80);
    }

    private function unavailable(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'driver' => (string) config('database.default', 'unknown'),
            'estimated_database_bytes' => null,
            'estimated_data_bytes' => null,
            'estimated_index_bytes' => null,
            'table_count' => null,
            'matching_count' => 0,
            'tables' => [],
            'top_tables' => [],
            'sort' => 'size',
            'search' => '',
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => max(10, min(100, (int) config('system_monitoring.storage_table_page_size', 25))),
            'source' => 'information_schema.TABLES',
            'checked_at' => now()->toIso8601String(),
        ];
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
}
