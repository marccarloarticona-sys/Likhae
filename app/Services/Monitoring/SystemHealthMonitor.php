<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class SystemHealthMonitor
{
    public function overview(): array
    {
        $checkedAt = now();

        return [
            'checked_at' => $checkedAt,
            'database' => $this->database(),
            'application' => $this->application(),
        ];
    }

    public function snapshot(): array
    {
        $checkedAt = now();
        $database = $this->database();
        $queue = $this->queue($checkedAt, $database['status'] === 'HEALTHY');
        $scheduler = $this->scheduler($checkedAt);

        return [
            'checked_at' => $checkedAt,
            'database' => $database,
            'domain' => $this->domain($checkedAt),
            'application' => $this->application(),
            'cache' => $this->cache($checkedAt, $scheduler),
            'storage' => $this->storage($checkedAt),
            'queue' => $queue,
            'scheduler' => $scheduler,
        ];
    }

    private function database(): array
    {
        $started = hrtime(true);
        $driver = (string) config('database.default', 'unknown');

        try {
            $this->probeDatabaseWithTimeout();

            return [
                'status' => 'HEALTHY',
                'driver' => $this->label($driver),
                'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
                'checked_at' => now(),
                'message' => 'A read-only connectivity query succeeded.',
            ];
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $isConnectionFailure = str_starts_with((string) $exception->getCode(), '08')
                || preg_match('/connection refused|connection timed out|could not connect|server has gone away|no connection to the server/i', $message) === 1;

            return [
                'status' => $isConnectionFailure ? 'DOWN' : 'UNKNOWN',
                'driver' => $this->label($driver),
                'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
                'checked_at' => now(),
                'message' => 'Database connectivity could not be verified.',
            ];
        }
    }

    private function probeDatabaseWithTimeout(): void
    {
        $driver = (string) config('database.default', '');
        $connectionConfig = config('database.connections.'.$driver);
        if (! is_array($connectionConfig)) {
            throw new \RuntimeException('Configured database connection is unavailable.');
        }

        $timeout = max(1, min(5, (int) config('system_monitoring.database_timeout_seconds', 2)));
        $options = (array) ($connectionConfig['options'] ?? []);
        $options[PDO::ATTR_TIMEOUT] = $timeout;
        if (in_array($driver, ['mysql', 'mariadb'], true) && defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $options[constant('PDO::MYSQL_ATTR_READ_TIMEOUT')] = $timeout;
        }
        $connectionConfig['options'] = $options;
        $connectionConfig['name'] = 'likhae_system_health_probe';
        $connection = DB::build($connectionConfig);

        try {
            $connection->select('SELECT 1');
        } finally {
            $connection->disconnect();
        }
    }

    private function domain($checkedAt): array
    {
        $applicationUrl = (string) config('app.url', '');
        $parts = parse_url($applicationUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $allowed = array_map('strtolower', (array) config('system_monitoring.allowed_domains', []));

        $result = [
            'domain' => $host !== '' && in_array($host, $allowed, true) ? $host : null,
            'dns' => ['status' => 'NOT CONFIGURED', 'latency_ms' => null, 'message' => 'No approved production domain is configured.'],
            'https' => ['status' => 'NOT CONFIGURED', 'http_status' => null, 'latency_ms' => null, 'message' => 'No approved HTTPS target is configured.'],
            'tls' => ['status' => 'UNKNOWN', 'expires_at' => null, 'days_remaining' => null],
            'checked_at' => $checkedAt,
        ];

        // Never make outbound probes from automated test environments.
        if (app()->environment('testing')) {
            $result['dns']['status'] = 'NOT CONFIGURED';
            $result['dns']['message'] = 'External probes are disabled in the test environment.';
            $result['https']['status'] = 'NOT CONFIGURED';
            $result['https']['message'] = 'External probes are disabled in the test environment.';

            return $result;
        }

        if ($host === '' || ! in_array($host, $allowed, true)) {
            return $result;
        }

        if (! function_exists('dns_get_record') || ! function_exists('stream_socket_client') || ! function_exists('openssl_x509_parse')) {
            $result['dns']['status'] = 'UNKNOWN';
            $result['dns']['message'] = 'Required DNS or TLS probe support is unavailable.';
            $result['https']['status'] = 'UNKNOWN';
            $result['https']['message'] = 'Required DNS or TLS probe support is unavailable.';

            return $result;
        }

        try {
            $dnsStarted = hrtime(true);
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $dnsLatency = round((hrtime(true) - $dnsStarted) / 1_000_000, 2);
            $result['dns']['latency_ms'] = $dnsLatency;
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $addresses[] = $ip;
                }
            }
            $addresses = array_values(array_unique($addresses));
            $publicAddresses = array_values(array_filter($addresses, fn (string $ip): bool => filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false));

            if ($publicAddresses === []) {
                $resolverUnavailable = $records === false;
                $result['dns']['status'] = $resolverUnavailable || $addresses !== [] ? 'UNKNOWN' : 'DOWN';
                $result['dns']['message'] = $resolverUnavailable
                    ? 'DNS resolution could not be verified by the configured resolver.'
                    : ($addresses === [] ? 'The approved domain returned no address records.'
                    : 'The approved domain resolved to an address that cannot be safely probed.');
                $result['https']['status'] = $resolverUnavailable ? 'UNKNOWN' : ($addresses === [] ? 'DOWN' : 'UNKNOWN');
                $result['https']['message'] = 'HTTPS was not probed because no safe public address was available.';

                return $result;
            }

            $result['dns']['status'] = 'HEALTHY';
            $result['dns']['message'] = 'The approved domain resolved to a public address.';
            $probe = $this->probeHttps($host, $publicAddresses[0]);
            $result['https'] = [
                'status' => $probe['status'],
                'http_status' => $probe['http_status'],
                'latency_ms' => $probe['latency_ms'],
                'message' => $probe['message'],
            ];
            $result['tls'] = $probe['tls'];
        } catch (Throwable) {
            $result['dns']['status'] = 'UNKNOWN';
            $result['dns']['message'] = 'The domain probe could not be completed safely.';
            $result['https']['status'] = 'UNKNOWN';
            $result['https']['message'] = 'The HTTPS response could not be verified.';
        }

        return $result;
    }

    private function probeHttps(string $host, string $ip): array
    {
        $timeout = max(1, min(5, (int) config('system_monitoring.connect_timeout_seconds', 2)));
        $readTimeout = max(1, min(5, (int) config('system_monitoring.read_timeout_seconds', 2)));
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'allow_self_signed' => false,
        ]]);
        $started = hrtime(true);
        $socket = @stream_socket_client(
            'tls://'.$address.':443',
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($socket)) {
            $diagnosticTls = $this->inspectCertificate($host, $ip, $timeout);
            $refused = in_array((int) $errorCode, [61, 111, 10061], true)
                || preg_match('/connection refused|actively refused/i', (string) $errorMessage) === 1;

            return [
                'status' => $diagnosticTls !== null ? 'DEGRADED' : ($refused ? 'DOWN' : 'UNKNOWN'), 'http_status' => null,
                'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
                'message' => $diagnosticTls !== null
                    ? 'The server presented a certificate, but its trust chain or hostname could not be verified.'
                    : ($refused ? 'The approved HTTPS endpoint refused the connection.' : 'A verified HTTPS connection could not be established; the probe result is inconclusive.'),
                'tls' => $diagnosticTls ?? ['status' => 'UNKNOWN', 'expires_at' => null, 'days_remaining' => null, 'urgency' => 'NORMAL', 'hostname_matches' => null, 'chain_valid' => false],
            ];
        }

        stream_set_timeout($socket, $readTimeout);
        $params = stream_context_get_params($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsedCertificate = $certificate ? @openssl_x509_parse($certificate) : false;
        $expires = is_array($parsedCertificate) ? ($parsedCertificate['validTo_time_t'] ?? null) : null;
        $daysRemaining = is_numeric($expires) ? (int) floor(((int) $expires - time()) / 86400) : null;
        $tlsThreshold = (int) config('system_monitoring.tls_warning_days', 30);
        $urgentThreshold = (int) config('system_monitoring.tls_urgent_days', 7);
        $tls = [
            'status' => $daysRemaining === null
                ? 'UNKNOWN'
                : ($daysRemaining < 0 ? 'EXPIRED' : ($daysRemaining <= $tlsThreshold ? 'EXPIRING' : 'VALID')),
            'urgency' => $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= $urgentThreshold ? 'URGENT' : 'NORMAL',
            'hostname_matches' => true,
            'chain_valid' => true,
            'expires_at' => is_numeric($expires) ? Carbon::createFromTimestamp((int) $expires) : null,
            'days_remaining' => $daysRemaining,
        ];

        fwrite($socket, "GET / HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\nUser-Agent: LIKHAE-System-Health/1.0\r\nAccept: */*\r\n\r\n");
        $statusLine = fgets($socket, 4096);
        $httpStatus = is_string($statusLine) && preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $statusLine, $matches)
            ? (int) $matches[1]
            : null;

        // Read only bounded response headers; redirects are deliberately never followed.
        $headerBytes = 0;
        $readDeadline = hrtime(true) + ($readTimeout * 1_000_000_000);
        while (is_resource($socket) && ! feof($socket) && $headerBytes < 16384) {
            $remaining = $readDeadline - hrtime(true);
            if ($remaining <= 0) {
                break;
            }
            $remainingSeconds = intdiv($remaining, 1_000_000_000);
            $remainingMicroseconds = intdiv($remaining % 1_000_000_000, 1000);
            stream_set_timeout($socket, $remainingSeconds, $remainingMicroseconds);
            $line = fgets($socket, 2048);
            if (! is_string($line)) {
                break;
            }
            $headerBytes += strlen($line);
            if (trim($line) === '') {
                break;
            }
        }
        fclose($socket);

        $tlsStatus = $tls['status'];
        $status = $httpStatus === null
            ? 'UNKNOWN'
            : (($httpStatus >= 500 || $httpStatus < 200) ? 'DEGRADED' : ($tlsStatus === 'VALID' ? 'HEALTHY' : 'DEGRADED'));

        return [
            'status' => $status,
            'http_status' => $httpStatus,
            'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            'message' => $httpStatus === null
                ? 'The HTTPS server did not return a readable HTTP status.'
                : 'TLS validation succeeded and the HTTPS server responded; redirects were not followed.',
            'tls' => $tls,
        ];
    }

    private function inspectCertificate(string $host, string $ip, int $timeout): ?array
    {
        // Diagnostic-only TLS handshake: no HTTP request is sent and it never upgrades health.
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'allow_self_signed' => true,
        ]]);
        $socket = @stream_socket_client('tls://'.$address.':443', $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (! is_resource($socket)) {
            return null;
        }

        $params = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate ? @openssl_x509_parse($certificate) : false;
        if (! is_array($parsed)) {
            return null;
        }

        $expires = $parsed['validTo_time_t'] ?? null;
        $daysRemaining = is_numeric($expires) ? (int) floor(((int) $expires - time()) / 86400) : null;
        $hostMatches = function_exists('openssl_x509_check_host') ? @openssl_x509_check_host($certificate, $host) : null;

        return [
            'status' => $daysRemaining !== null && $daysRemaining < 0 ? 'EXPIRED' : 'UNKNOWN',
            'expires_at' => is_numeric($expires) ? Carbon::createFromTimestamp((int) $expires) : null,
            'days_remaining' => $daysRemaining,
            'urgency' => 'NORMAL',
            'hostname_matches' => is_bool($hostMatches) ? $hostMatches : null,
            'chain_valid' => false,
        ];
    }

    private function application(): array
    {
        return [
            'status' => 'HEALTHY',
            'environment' => (string) config('app.env', 'unknown'),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'release' => config('system_monitoring.release') ?: null,
            'checked_at' => now(),
        ];
    }

    private function cache($checkedAt, array $scheduler): array
    {
        $driver = (string) config('cache.default', 'unknown');
        $recentHeartbeat = collect($scheduler['tasks'])->contains(function (array $task) use ($checkedAt): bool {
            $latest = collect([$task['last_success'] ?? null, $task['last_failure'] ?? null])
                ->filter(fn ($time) => $time instanceof Carbon)
                ->sortByDesc(fn (Carbon $time) => $time->getTimestamp())
                ->first();

            return $latest instanceof Carbon
                && isset($task['freshness_seconds'])
                && $checkedAt->getTimestamp() - $latest->getTimestamp() <= $task['freshness_seconds'];
        });

        try {
            // A read-only lookup confirms reads. A recent scheduler callback separately confirms a write.
            Cache::store()->get('__likhae_system_health_read_probe__');

            return [
                'status' => $recentHeartbeat ? 'HEALTHY' : 'UNKNOWN',
                'driver' => $this->label($driver),
                'read_confirmed' => true,
                'write_confirmed' => $recentHeartbeat,
                'message' => $recentHeartbeat
                    ? 'Cache read succeeded and a recent scheduler heartbeat confirms a cache write.'
                    : 'Cache read succeeded; no recent scheduler heartbeat confirms a write.',
                'checked_at' => $checkedAt,
            ];
        } catch (Throwable) {
            return [
                'status' => 'UNKNOWN',
                'driver' => $this->label($driver),
                'read_confirmed' => false,
                'write_confirmed' => false,
                'message' => 'Cache read could not be verified safely.',
                'checked_at' => $checkedAt,
            ];
        }
    }

    private function storage($checkedAt): array
    {
        $storagePath = storage_path();
        $appPath = storage_path('app');
        $logsPath = storage_path('logs');
        $freeBytes = @disk_free_space($storagePath);
        $pathsReadable = is_dir($appPath) && is_readable($appPath) && is_dir($logsPath) && is_readable($logsPath);
        $status = ! $pathsReadable
            ? 'DEGRADED'
            : (is_numeric($freeBytes)
                && (int) $freeBytes < (int) config('system_monitoring.storage_warning_free_bytes', 536870912)
                    ? 'DEGRADED'
                    : 'UNKNOWN');

        return [
            'status' => $status,
            'app_readable' => is_dir($appPath) && is_readable($appPath),
            'logs_readable' => is_dir($logsPath) && is_readable($logsPath),
            'free_bytes' => is_numeric($freeBytes) ? (int) $freeBytes : null,
            'write_tested' => false,
            'checked_at' => $checkedAt,
        ];
    }

    private function queue($checkedAt, bool $databaseAvailable): array
    {
        $connection = (string) config('queue.default', 'unknown');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $result = [
            'status' => 'NOT CONFIGURED',
            'connection' => $this->label($connection),
            'driver' => $this->label($driver),
            'pending' => null,
            'failed' => null,
            'pending_capped' => false,
            'failed_capped' => false,
            'recent_failed' => [],
            'worker_activity' => 'UNKNOWN',
            'checked_at' => $checkedAt,
            'message' => 'This queue driver does not provide verified worker activity metrics.',
        ];

        if (! $databaseAvailable) {
            $result['status'] = 'UNKNOWN';
            $result['message'] = 'Queue metrics could not be checked because the database probe failed.';

            return $result;
        }

        try {
            $failedDriver = (string) config('queue.failed.driver', 'null');
            if (in_array($failedDriver, ['database', 'database-uuids'], true)) {
                $failedDatabase = (string) (config('queue.failed.database') ?: config('database.default'));
                $failedTable = (string) config('queue.failed.table', 'failed_jobs');
                if (Schema::connection($failedDatabase)->hasTable($failedTable)) {
                    $failedIds = DB::connection($failedDatabase)->table($failedTable)->orderBy('id')->limit(1001)->pluck('id');
                    $result['failed'] = min($failedIds->count(), 1000);
                    $result['failed_capped'] = $failedIds->count() > 1000;
                    $failedRows = DB::connection($failedDatabase)->table($failedTable)
                        ->select('queue', 'failed_at')
                        ->selectRaw('SUBSTRING(payload, 1, 2048) AS payload_excerpt')
                        ->selectRaw('SUBSTRING(exception, 1, 2048) AS exception_excerpt')
                        ->orderByDesc('failed_at')->limit(5)->get();
                    $result['recent_failed'] = $failedRows->map(fn ($row): array => $this->safeFailedJob($row))->all();
                }
            }

            if ($driver !== 'database') {
                $result['status'] = $result['failed'] > 0 ? 'DEGRADED' : 'NOT CONFIGURED';
                $result['message'] = $driver === 'sync'
                    ? 'Jobs run inline; background worker backlog monitoring is not configured.'
                    : 'Backlog and worker metrics are not supported for this queue driver.';

                return $result;
            }

            $queueTable = (string) config("queue.connections.{$connection}.table", 'jobs');
            $databaseConnection = config("queue.connections.{$connection}.connection") ?: config('database.default');
            $schema = Schema::connection($databaseConnection);

            if (! $schema->hasTable($queueTable)) {
                $result['status'] = 'NOT CONFIGURED';
                $result['message'] = 'The configured database queue table is not available.';

                return $result;
            }

            $queueName = (string) config("queue.connections.{$connection}.queue", 'default');
            $pendingIds = DB::connection($databaseConnection)->table($queueTable)
                ->where('queue', $queueName)->orderBy('id')->limit(1001)->pluck('id');
            $result['pending'] = min($pendingIds->count(), 1000);
            $result['pending_capped'] = $pendingIds->count() > 1000;

            $threshold = max(1, (int) config('system_monitoring.queue_backlog_warning', 100));
            $result['status'] = $result['pending'] >= $threshold || $result['failed'] > 0 ? 'DEGRADED' : 'UNKNOWN';
            $result['message'] = 'Queue counts were checked; worker activity has no configured heartbeat.';
        } catch (Throwable) {
            $result['status'] = 'UNKNOWN';
            $result['message'] = 'Queue metrics could not be read from the configured storage.';
        }

        return $result;
    }

    private function scheduler($checkedAt): array
    {
        $tasks = [];
        $driverName = (string) config('cache.default', 'unknown');
        $cacheDriver = (string) config("cache.stores.{$driverName}.driver", $driverName);
        $persistentDrivers = ['file', 'database', 'redis', 'memcached', 'dynamodb'];
        $persistentCache = in_array($cacheDriver, $persistentDrivers, true);
        $prefix = rtrim((string) config('system_monitoring.scheduler_cache_prefix', 'likhae:system-health:scheduler'), ':');

        try {
            foreach (Schedule::events() as $event) {
                $command = (string) ($event->command ?? '');
                $signature = preg_match('/\b(likhae:[A-Za-z0-9:-]+)\b/', $command, $matches) === 1 ? $matches[1] : null;
                $name = $signature
                    ? str((string) preg_replace('/^likhae:/', '', $signature))->replace(['-', ':'], ' ')->headline()->toString()
                    : 'Scheduled command';
                $expression = (string) ($event->expression ?? '');
                $cadence = match ($expression) {
                    '* * * * *' => 'Every minute',
                    '0 * * * *' => 'Hourly',
                    '0 0 * * *' => 'Daily',
                    default => $expression !== '' ? 'Cron schedule: '.$expression : 'Unknown cadence',
                };
                $freshness = match ($expression) {
                    '* * * * *' => 300,
                    '0 * * * *' => 10800,
                    '0 0 * * *' => 129600,
                    default => null,
                };
                $lastSuccess = null;
                $lastFailure = null;
                if ($persistentCache && $signature !== null) {
                    $cacheKey = $prefix.':'.str_replace(':', '-', $signature);
                    try {
                        $lastSuccess = $this->heartbeatTime(Cache::store()->get($cacheKey.':success'));
                        $lastFailure = $this->heartbeatTime(Cache::store()->get($cacheKey.':failure'));
                    } catch (Throwable) {
                        // A missing/unreachable heartbeat store cannot confirm a task state.
                    }
                }

                $latest = $lastSuccess;
                $latestOutcome = 'success';
                if ($lastFailure !== null && ($latest === null || $lastFailure->greaterThan($latest))) {
                    $latest = $lastFailure;
                    $latestOutcome = 'failure';
                }
                $ageSeconds = $latest === null ? null : $checkedAt->getTimestamp() - $latest->getTimestamp();
                $status = ! $persistentCache
                    ? 'NOT CONFIGURED'
                    : ($latest === null || $freshness === null
                        ? 'UNKNOWN'
                        : ($ageSeconds > $freshness ? 'STALE' : ($latestOutcome === 'failure' ? 'DEGRADED' : 'HEALTHY')));

                $task = [
                    'name' => $name,
                    'command' => $signature,
                    'cadence' => $cadence,
                    'freshness_seconds' => $freshness,
                    'last_success' => $lastSuccess,
                    'last_failure' => $lastFailure,
                    'status' => $status,
                ];
                if ($signature === 'likhae:auto-confirm-delivered-orders') {
                    $task['rule'] = 'Eligibility begins 72 hours after a recorded delivery event.';
                }
                $tasks[] = $task;
            }
        } catch (Throwable) {
            $tasks = [];
        }

        $latestHeartbeat = collect($tasks)
            ->flatMap(fn (array $task) => [$task['last_success'], $task['last_failure']])
            ->filter(fn ($time) => $time instanceof Carbon)
            ->sortByDesc(fn (Carbon $time) => $time->getTimestamp())
            ->first();
        $taskStatuses = array_column($tasks, 'status');
        $status = ! $persistentCache
            ? 'NOT CONFIGURED'
            : (in_array('DEGRADED', $taskStatuses, true)
                ? 'DEGRADED'
                : (in_array('STALE', $taskStatuses, true)
                    ? 'STALE'
                    : ($taskStatuses === [] || in_array('UNKNOWN', $taskStatuses, true) ? 'UNKNOWN' : 'HEALTHY')));
        $hasHeartbeat = $latestHeartbeat instanceof Carbon;

        return [
            'status' => $status,
            'checked_at' => $checkedAt,
            'heartbeat' => $latestHeartbeat,
            'persistent_cache' => $persistentCache,
            'message' => ! $persistentCache
                ? 'A persistent cache driver is required for cross-process scheduler heartbeat monitoring.'
                : (! $hasHeartbeat
                    ? 'No verified scheduler heartbeat has been recorded yet; task registration does not prove execution.'
                    : 'Per-task heartbeats report the latest successful run, latest failure, and freshness threshold.'),
            'tasks' => $tasks,
        ];
    }

    private function heartbeatTime(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function label(string $value): string
    {
        return strtoupper($value) === 'MYSQL'
            ? 'MySQL'
            : (strtoupper($value) === 'SQLITE' ? 'SQLite' : ucfirst($value));
    }

    private function safeFailedJob(object $row): array
    {
        $payload = json_decode((string) ($row->payload_excerpt ?? ''), true);
        $candidate = is_array($payload)
            ? ($payload['displayName'] ?? data_get($payload, 'data.commandName'))
            : null;
        $jobType = is_string($candidate) && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $candidate) === 1
            ? class_basename($candidate)
            : 'Unavailable';
        $exception = (string) ($row->exception_excerpt ?? '');
        $exceptionClass = preg_match('/(?:^|\s)(?<class>[A-Za-z_][A-Za-z0-9_\\\\]*(?:Exception|Error))\b/', $exception, $matches) === 1
            ? class_basename($matches['class'])
            : null;

        return [
            'failed_at' => $row->failed_at ?? null,
            'job_type' => $jobType,
            'queue' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($row->queue ?? '')) ?? '', 0, 64),
            'summary' => $exceptionClass
                ? 'Job failed with '.$exceptionClass.'; exception message withheld.'
                : 'Job failed; exception details withheld.',
        ];
    }
}
