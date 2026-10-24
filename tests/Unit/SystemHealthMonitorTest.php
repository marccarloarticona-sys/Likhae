<?php

namespace Tests\Unit;

use App\Services\Monitoring\SystemHealthMonitor;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemHealthMonitorTest extends TestCase
{
    private ?string $schedulerCachePath = null;

    protected function tearDown(): void
    {
        if ($this->schedulerCachePath !== null) {
            File::deleteDirectory($this->schedulerCachePath);
        }
        parent::tearDown();
    }

    public function test_database_probe_uses_a_single_read_only_query_and_measures_latency(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtoupper(trim($query->sql));
        });

        $snapshot = app(SystemHealthMonitor::class)->snapshot();

        $this->assertSame('HEALTHY', $snapshot['database']['status']);
        $this->assertIsNumeric($snapshot['database']['latency_ms']);
        $this->assertInstanceOf(Carbon::class, $snapshot['database']['checked_at']);
        $this->assertContains('SELECT 1', $queries);
    }

    public function test_confirmed_database_connection_failure_is_down_without_exposing_exception_details(): void
    {
        $probeConnection = \Mockery::mock(Connection::class);
        $probeConnection->shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new \RuntimeException('Connection refused for password=unit-test-secret'));
        $probeConnection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')
            ->once()
            ->withArgs(fn (array $config): bool => ($config['options'][\PDO::ATTR_TIMEOUT] ?? null) === 2)
            ->andReturn($probeConnection);

        $snapshot = app(SystemHealthMonitor::class)->snapshot();

        $this->assertSame('DOWN', $snapshot['database']['status']);
        $this->assertSame('Database connectivity could not be verified.', $snapshot['database']['message']);
        $this->assertStringNotContainsString('unit-test-secret', json_encode($snapshot['database']));
        $this->assertIsNumeric($snapshot['database']['latency_ms']);
    }

    public function test_external_probe_rejects_hosts_outside_the_fixed_allowlist(): void
    {
        config(['app.url' => 'https://127.0.0.1:8080']);

        $domain = app(SystemHealthMonitor::class)->snapshot()['domain'];

        $this->assertNull($domain['domain']);
        $this->assertSame('NOT CONFIGURED', $domain['dns']['status']);
        $this->assertSame('NOT CONFIGURED', $domain['https']['status']);
    }

    public function test_scheduler_heartbeats_report_success_failure_and_stale_freshness(): void
    {
        $this->schedulerCachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'likhae-scheduler-test-'.bin2hex(random_bytes(6));
        mkdir($this->schedulerCachePath, 0700, true);
        config([
            'cache.default' => 'file',
            'cache.stores.file.driver' => 'file',
            'cache.stores.file.path' => $this->schedulerCachePath,
        ]);

        $signatures = collect(Schedule::events())
            ->map(function ($event): ?string {
                return preg_match('/\\b(likhae:[A-Za-z0-9:-]+)\\b/', (string) ($event->command ?? ''), $matches) === 1
                    ? $matches[1]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
        $prefix = (string) config('system_monitoring.scheduler_cache_prefix');
        $this->assertNotEmpty($signatures);
        foreach ($signatures as $signature) {
            Cache::store()->put($this->heartbeatKey($prefix, $signature, 'success'), now()->toIso8601String(), now()->addDays(30));
        }

        $health = app(SystemHealthMonitor::class)->snapshot();
        $this->assertSame('HEALTHY', $health['scheduler']['status']);
        $this->assertSame('HEALTHY', $health['cache']['status']);
        $this->assertCount(count($signatures), $health['scheduler']['tasks']);

        $failureKey = $this->heartbeatKey($prefix, $signatures[0], 'failure');
        Cache::store()->put($failureKey, now()->addSecond()->toIso8601String(), now()->addDays(30));
        $failedHealth = app(SystemHealthMonitor::class)->snapshot();
        $failedTask = collect($failedHealth['scheduler']['tasks'])->firstWhere('command', $signatures[0]);
        $this->assertSame('DEGRADED', $failedTask['status']);

        Cache::store()->forget($failureKey);
        $staleKey = $this->heartbeatKey($prefix, $signatures[0], 'success');
        Cache::store()->put($staleKey, now()->subDays(2)->toIso8601String(), now()->addDays(30));
        $staleHealth = app(SystemHealthMonitor::class)->snapshot();
        $staleTask = collect($staleHealth['scheduler']['tasks'])->firstWhere('command', $signatures[0]);
        $this->assertSame('STALE', $staleTask['status']);
        $this->assertSame('STALE', $staleHealth['scheduler']['status']);
    }

    private function heartbeatKey(string $prefix, string $signature, string $outcome): string
    {
        return rtrim($prefix, ':').':'.str_replace(':', '-', $signature).':'.$outcome;
    }
}
