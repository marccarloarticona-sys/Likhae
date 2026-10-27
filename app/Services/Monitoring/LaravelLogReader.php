<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Throwable;

class LaravelLogReader
{
    private string $logDirectory;

    public function __construct(?string $logDirectory = null)
    {
        $this->logDirectory = $logDirectory ?? storage_path('logs');
    }

    /**
     * Read only bounded tails of trusted, configured single/daily log files.
     * Returned records contain sanitized summaries, never raw contexts or traces.
     *
     * @return array{available:bool,partial:bool,reason:?string,coverage_start:?Carbon,sources:array,bytes_read:int,entries:array,checked_at:Carbon}
     */
    public function read(?int $limit = null): array
    {
        $maxFiles = max(1, min(14, (int) config('system_monitoring.log_max_files', 8)));
        $perFileLimit = max(4096, min(1048576, (int) config('system_monitoring.log_max_bytes_per_file', 262144)));
        $totalLimit = max($perFileLimit, min(4194304, (int) config('system_monitoring.log_max_total_bytes', 2097152)));
        $entryLimit = max(1, min(1000, $limit ?? (int) config('system_monitoring.log_max_entries', 500)));
        $files = $this->configuredFiles($maxFiles);

        if ($files === []) {
            return $this->unavailable('No readable configured Laravel log file is available.');
        }

        $entries = [];
        $sources = [];
        $totalBytes = 0;
        $partial = false;

        foreach ($files as $file) {
            if ($totalBytes >= $totalLimit) {
                $partial = true;
                break;
            }

            $remaining = $totalLimit - $totalBytes;
            $read = $this->tail($file['path'], min($perFileLimit, $remaining));
            if ($read === null) {
                $partial = true;

                continue;
            }

            $totalBytes += $read['bytes'];
            $partial = $partial || $read['truncated'];
            $sources[$file['name']] = true;
            foreach ($this->parse($read['contents'], $file['channel'], $file['name']) as $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (array $left, array $right): int => $right['timestamp']->getTimestamp() <=> $left['timestamp']->getTimestamp());
        $entries = array_slice($entries, 0, $entryLimit);
        $coverageStart = $entries !== [] ? min(array_column($entries, 'timestamp')) : null;

        return [
            'available' => $sources !== [],
            'partial' => $partial,
            'reason' => $sources === [] ? 'Configured log files could not be read.' : null,
            'coverage_start' => $coverageStart,
            'sources' => array_keys($sources),
            'bytes_read' => $totalBytes,
            'entries' => $entries,
            'checked_at' => now(),
        ];
    }

    /**
     * Return aggregate metadata for supported readable log files without exposing paths or names.
     */
    public function storageInventory(): array
    {
        $maxFiles = max(1, min(500, (int) config('system_monitoring.storage_log_inventory_max_files', 100)));
        $files = $this->configuredFiles($maxFiles);
        $checkedAt = now();
        if ($files === []) {
            return [
                'status' => 'UNKNOWN', 'bytes' => null, 'files' => null, 'last_updated' => null,
                'retention_days' => $this->configuredRetentionDays(), 'checked_at' => $checkedAt,
                'message' => 'No supported readable configured log file is available.',
            ];
        }

        $bytes = 0;
        $lastUpdated = null;
        $partial = count($files) >= $maxFiles;
        foreach ($files as $file) {
            $size = @filesize($file['path']);
            if (! is_int($size) || $size < 0) {
                $partial = true;

                continue;
            }
            $bytes += $size;
            if ($file['modified'] > 0) {
                $modifiedAt = Carbon::createFromTimestamp($file['modified']);
                if ($lastUpdated === null || $modifiedAt->greaterThan($lastUpdated)) {
                    $lastUpdated = $modifiedAt;
                }
            }
        }

        return [
            'status' => $partial ? 'UNKNOWN' : 'HEALTHY',
            'bytes' => $partial ? null : $bytes,
            'files' => $partial ? null : count($files),
            'last_updated' => $lastUpdated,
            'retention_days' => $this->configuredRetentionDays(),
            'checked_at' => $checkedAt,
            'message' => $partial
                ? 'Log inventory reached its configured file cap or a metadata read failed.'
                : 'Supported configured log files were measured without reading their contents.',
        ];
    }

    private function configuredFiles(int $maxFiles): array
    {
        $root = realpath($this->logDirectory);
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            return [];
        }

        $channels = (array) config('logging.channels', []);
        $default = (string) config('logging.default', 'stack');
        $toInspect = [$default];
        $visited = [];
        $configured = [];

        while ($toInspect !== []) {
            $name = array_shift($toInspect);
            if (! is_string($name) || isset($visited[$name]) || count($visited) > 32) {
                continue;
            }
            $visited[$name] = true;
            $channel = $channels[$name] ?? null;
            if (! is_array($channel)) {
                continue;
            }
            $driver = $channel['driver'] ?? null;
            if ($driver === 'stack') {
                foreach ((array) ($channel['channels'] ?? []) as $child) {
                    if (is_string($child)) {
                        $toInspect[] = $child;
                    }
                }

                continue;
            }
            if (! in_array($driver, ['single', 'daily'], true) || ! is_string($channel['path'] ?? null)) {
                continue;
            }

            $path = $channel['path'];
            $parent = realpath(dirname($path));
            if ($parent === false || ! $this->within($parent, $root)) {
                continue;
            }
            $base = basename($path);
            $stem = pathinfo($base, PATHINFO_FILENAME);
            $extension = pathinfo($base, PATHINFO_EXTENSION);
            $candidates = $driver === 'daily'
                ? glob($parent.DIRECTORY_SEPARATOR.$stem.'-????-??-??'.($extension !== '' ? '.'.$extension : '')) ?: []
                : [$parent.DIRECTORY_SEPARATOR.$base];

            foreach ($candidates as $candidate) {
                $real = realpath($candidate);
                if ($real === false || ! is_file($real) || ! is_readable($real) || ! $this->within($real, $root)) {
                    continue;
                }
                $configured[] = [
                    'path' => $real,
                    'name' => basename($real),
                    'channel' => $name,
                    'modified' => @filemtime($real) ?: 0,
                ];
            }
        }

        $unique = [];
        foreach ($configured as $file) {
            $unique[$file['path']] = $file;
        }
        $files = array_values($unique);
        usort($files, static fn (array $left, array $right): int => $right['modified'] <=> $left['modified']);

        return array_slice($files, 0, $maxFiles);
    }

    private function within(string $path, string $root): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) || $path === $root;
    }

    private function configuredRetentionDays(): ?int
    {
        $channels = (array) config('logging.channels', []);
        $toInspect = [(string) config('logging.default', 'stack')];
        $visited = [];
        $retention = [];
        while ($toInspect !== [] && count($visited) < 32) {
            $name = array_shift($toInspect);
            if (isset($visited[$name])) {
                continue;
            }
            $visited[$name] = true;
            $channel = $channels[$name] ?? null;
            if (! is_array($channel)) {
                continue;
            }
            if (($channel['driver'] ?? null) === 'stack') {
                foreach ((array) ($channel['channels'] ?? []) as $child) {
                    if (is_string($child)) {
                        $toInspect[] = $child;
                    }
                }

                continue;
            }
            if (($channel['driver'] ?? null) === 'daily') {
                $days = $channel['days'] ?? $channel['max_files'] ?? null;
                if (is_numeric($days) && (int) $days > 0) {
                    $retention[] = min(3650, (int) $days);
                }
            }
        }

        return $retention === [] ? null : max($retention);
    }

    private function tail(string $path, int $maxBytes): ?array
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return null;
        }

        try {
            $size = @filesize($path);
            if (! is_int($size) || $size < 0) {
                return null;
            }
            $bytes = min($size, $maxBytes);
            if ($bytes === 0) {
                return ['contents' => '', 'bytes' => 0, 'truncated' => false];
            }
            fseek($handle, -$bytes, SEEK_END);
            $contents = fread($handle, $bytes);
            if (! is_string($contents)) {
                return null;
            }

            $truncated = $size > $bytes;
            if ($truncated) {
                $newline = strpos($contents, "\n");
                $contents = $newline === false ? '' : substr($contents, $newline + 1);
            }
            if (function_exists('iconv')) {
                $normalized = @iconv('UTF-8', 'UTF-8//IGNORE', $contents);
                if (is_string($normalized)) {
                    $contents = $normalized;
                }
            }

            return ['contents' => $contents, 'bytes' => $bytes, 'truncated' => $truncated];
        } finally {
            fclose($handle);
        }
    }

    private function parse(string $contents, string $channel, string $fileName): array
    {
        $entries = [];
        $current = null;
        $recordIndex = 0;
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\[(?<timestamp>[^\]]+)\]\s+(?<channel>[A-Za-z0-9_.-]+)\.(?<level>[A-Z]+):\s?(?<message>.*)$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $entry = $this->entry($current, $channel, $fileName, $recordIndex++);
                    if ($entry !== null) {
                        $entries[] = $entry;
                    }
                }
                $current = [
                    'timestamp' => $matches['timestamp'],
                    'level' => strtolower($matches['level']),
                    'message' => $matches['message'],
                ];
            }
            // Continuation lines (including stack traces and raw contexts) are intentionally discarded.
        }
        if ($current !== null) {
            $entry = $this->entry($current, $channel, $fileName, $recordIndex);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function entry(array $raw, string $channel, string $fileName, int $recordIndex): ?array
    {
        try {
            $timestamp = Carbon::parse($raw['timestamp']);
        } catch (Throwable) {
            return null;
        }
        [$rawMessage, $context] = $this->splitContext((string) $raw['message']);
        $severity = in_array($raw['level'], ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)
            ? strtoupper($raw['level'])
            : 'UNKNOWN';
        $status = $this->httpStatus($rawMessage, $context);
        $exceptionClass = $this->exceptionClass($rawMessage, $context);

        $entry = [
            'id' => hash('sha256', $timestamp->toIso8601String().'|'.$severity.'|'.$channel.'|'.$fileName.'|'.$recordIndex),
            'timestamp' => $timestamp,
            'severity' => $severity,
            'summary' => $this->safeSummary($rawMessage, $severity, $status, $exceptionClass),
            'source' => $channel,
            'http_status' => $status,
            'exception_class' => $exceptionClass,
            'route' => $this->safeContextString($context, ['system_monitoring_route_name']),
            'request_path' => $this->safeContextPath($context['system_monitoring_request_path'] ?? null),
            'request_method' => $this->safeContextMethod($context['system_monitoring_request_method'] ?? null),
            'request_id' => $this->safeContextRequestId($context['system_monitoring_request_id'] ?? null),
        ];

        return $entry;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function splitContext(string $message): array
    {
        if (preg_match('/\s+(?<context>\{.*\})\s*$/s', $message, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [$message, []];
        }

        $context = json_decode($matches['context'][0], true);
        if (! is_array($context)) {
            return [$message, []];
        }

        return [rtrim(substr($message, 0, $matches['context'][1])), $context];
    }

    private function httpStatus(string $message, array $context): ?int
    {
        $contextStatus = $context['system_monitoring_http_status'] ?? null;
        if (is_numeric($contextStatus) && (int) $contextStatus >= 100 && (int) $contextStatus <= 599) {
            return (int) $contextStatus;
        }

        return preg_match('/\b(?:http(?:[_ ]?status)?|status[_ ]?code)\s*[:= ]\s*([1-5][0-9]{2})\b/i', $message, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function exceptionClass(string $message, array $context): ?string
    {
        if (preg_match('/(?<class>[A-Za-z_][A-Za-z0-9_\\\\]*(?:Exception|Error))\b/', $message, $matches) === 1
            && $this->isAllowedExceptionClass($matches['class'])) {
            return $matches['class'];
        }

        $exception = $context['exception'] ?? null;
        $class = is_array($exception) ? ($exception['class'] ?? null) : null;
        if (! is_string($class) && is_string($exception)
            && preg_match('/(?:\[object\]\s*)?\(?(?<class>[A-Za-z_][A-Za-z0-9_\\\\]*(?:Exception|Error))(?=\s*\(|:)/', $exception, $matches) === 1) {
            $class = $matches['class'];
        }

        return is_string($class) && $this->isAllowedExceptionClass($class) ? $class : null;
    }

    private function safeContextString(array $context, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,180}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function safeContextPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > 255
            || preg_match('/^\/[A-Za-z0-9._~{}\/:?-]*$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function safeContextMethod(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'OTHER'], true)
            ? $value
            : null;
    }

    private function safeContextRequestId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9-]{36}$/i', $value) === 1
            ? $value
            : null;
    }

    private function isAllowedExceptionClass(string $class): bool
    {
        return $class === 'Error'
            || $class === 'TypeError'
            || $class === 'PDOException'
            || $class === 'RuntimeException'
            || $class === 'LogicException'
            || $class === 'ErrorException'
            || str_starts_with($class, 'App\\')
            || str_starts_with($class, 'Illuminate\\')
            || str_starts_with($class, 'Symfony\\')
            || str_starts_with($class, 'GuzzleHttp\\');
    }

    private function safeSummary(string $message, string $severity, ?int $httpStatus, ?string $exceptionClass): string
    {
        if (preg_match('/CLOUDFLARE_AI_API_TOKEN|authorization\s*:\s*bearer|bearer\s+[A-Za-z0-9._~+\/-]{12,}/i', $message) === 1) {
            return 'Credential-bearing provider log record; credentials and request details withheld.';
        }
        if (preg_match('/\b(AI_PROVIDER_TIMEOUT|AI_QUOTA_LIMIT|AI_MODEL_UNAVAILABLE|AI_INVALID_RESPONSE|AI_NOT_CONFIGURED|AI_SCAN_DEFERRED|AI_INVALID_IMAGE|AI_UNSUPPORTED_IMAGE|AI_JOB_FAILED)\b/', $message, $matches) === 1) {
            return 'Product moderation scan failure: '.$matches[1].'; request details withheld.';
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            if ($exceptionClass !== null) {
                return 'HTTP '.$httpStatus.' response recorded: '.$this->exceptionSummary($exceptionClass);
            }

            return 'HTTP '.$httpStatus.' response recorded; request details withheld.';
        }
        if (preg_match('/sqlstate|pdoexception|database|mysql|postgres|sqlsrv|connection refused/i', $message) === 1) {
            return 'Database query or connection failed; connection and query details withheld.';
        }
        if (preg_match('/timed? out|timeout/i', $message) === 1) {
            return 'The operation timed out; message details withheld.';
        }
        if ($exceptionClass !== null) {
            return $this->exceptionSummary($exceptionClass);
        }

        if (preg_match('/\b(?:could not connect|connection refused|connection failed)\b/i', $message) === 1) {
            return 'A required service could not be reached; connection details withheld.';
        }
        if (preg_match('/\b(?:not configured|missing configuration)\b/i', $message) === 1) {
            return 'A required service setting is missing; configuration details withheld.';
        }
        if (preg_match('/\bquota\b.*\b(?:limit|exceed|reached)\b/i', $message) === 1) {
            return 'A provider quota limit was reached; request details withheld.';
        }
        if (preg_match('/\b(?:retry|retries)\b.*\b(?:limit|exhausted|failed)\b/i', $message) === 1) {
            return 'The operation reached its retry limit and did not complete.';
        }
        if (preg_match('/\b(?:invalid|malformed) response\b/i', $message) === 1) {
            return 'A required service returned an invalid response; response details withheld.';
        }
        if (preg_match('/\b(?:(?:notification|mail|email)(?:\s+(?:delivery|dispatch|sending))?\s+(?:was\s+)?(?:fail(?:ed|ure)?|could not|unable)|(?:fail(?:ed|ure)?|could not|unable)\s+to\s+(?:send|deliver)\s+(?:the\s+)?(?:notification|mail|email))\b/i', $message) === 1) {
            return 'Notification delivery failed; recipient details withheld.';
        }
        if (preg_match('/\b(?:upload|image|file|proof)\b/i', $message) === 1
            && preg_match('/\b(?:fail(?:ed|ure)?|could not|unable|exception)\b/i', $message) === 1) {
            return 'A file upload or storage operation failed; file details withheld.';
        }

        return match ($severity) {
            'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'Application error recorded; message details withheld.',
            'WARNING' => 'Application warning recorded; message details withheld.',
            default => 'Application '.strtolower($severity).' log record; message details withheld.',
        };
    }

    private function exceptionSummary(string $exceptionClass): string
    {
        $shortClass = class_basename($exceptionClass);
        $description = match ($exceptionClass) {
            'Illuminate\\Validation\\ValidationException' => 'Request data did not pass validation',
            'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => 'The requested page or record was not found',
            'Illuminate\\Auth\\AuthenticationException' => 'Authentication is required for this request',
            'Illuminate\\Auth\\Access\\AuthorizationException' => 'Access to this page or action was denied',
            'Illuminate\\Session\\TokenMismatchException' => 'The session token was invalid or expired',
            'Illuminate\\Database\\QueryException', 'PDOException' => 'A database query failed',
            'Illuminate\\Database\\ConnectionException' => 'The application could not connect to the database',
            'Illuminate\\Http\\Client\\ConnectionException', 'GuzzleHttp\\Exception\\ConnectException' => 'The application could not connect to an external service',
            'Illuminate\\Queue\\MaxAttemptsExceededException' => 'A background job exhausted its retry limit',
            'InvalidArgumentException' => 'An operation received an invalid argument',
            'LogicException' => 'An operation ran in an invalid application state',
            'TypeError' => 'Application code received a value of the wrong type',
            'Error', 'ErrorException' => 'A PHP runtime error occurred',
            'RuntimeException' => 'An application operation could not complete',
            default => 'An exception occurred',
        };

        return $description.' ('.$shortClass.'); message details withheld.';
    }

    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'partial' => true,
            'reason' => $reason,
            'coverage_start' => null,
            'sources' => [],
            'bytes_read' => 0,
            'entries' => [],
            'checked_at' => now(),
        ];
    }
}
