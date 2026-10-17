<?php

namespace Tests\Unit;

use App\Services\Monitoring\DatabaseTableStorageMonitor;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseTableStorageMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.options' => [],
            'system_monitoring.storage_snapshot_cache_key' => 'database-table-test:'.bin2hex(random_bytes(6)),
            'system_monitoring.storage_table_page_size' => 25,
        ]);
    }

    public function test_reads_only_mysql_table_metadata_and_calculates_estimated_sizes(): void
    {
        $queries = [];
        $connection = $this->mockMySqlConnection([
            [(object) ['table_count' => 3, 'data_bytes' => '4096', 'index_bytes' => '2048']],
            [(object) ['matching_count' => 3]],
            [
                $this->metadata('orders', 'InnoDB', '160', '2048', '1024'),
                $this->metadata('messages', 'InnoDB', '300', '1024', '512'),
                $this->metadata('users', 'InnoDB', '30', '1024', '512'),
            ],
            [
                $this->metadata('orders', 'InnoDB', '160', '2048', '1024'),
                $this->metadata('messages', 'InnoDB', '300', '1024', '512'),
                $this->metadata('users', 'InnoDB', '30', '1024', '512'),
            ],
        ], $queries);

        $result = app(DatabaseTableStorageMonitor::class)->snapshot();

        $this->assertSame('HEALTHY', $result['status']);
        $this->assertSame(6144, $result['estimated_database_bytes']);
        $this->assertSame(4096, $result['estimated_data_bytes']);
        $this->assertSame(2048, $result['estimated_index_bytes']);
        $this->assertSame(3, $result['table_count']);
        $this->assertSame('orders', $result['top_tables'][0]['table_name']);
        $this->assertSame(3072, $result['tables'][0]['total_bytes']);
        $this->assertSame('3.00 KB', $result['tables'][0]['total_human']);
        $this->assertStringNotContainsString('business-row-secret', json_encode($result));
        $this->assertSame(4, count($queries));
        foreach ($queries as [$sql]) {
            $this->assertStringContainsString('information_schema.TABLES', $sql);
            $this->assertStringNotContainsString('SELECT *', strtoupper($sql));
            $this->assertStringStartsWith('SELECT', strtoupper(trim($sql)));
        }
        $topQuery = collect($queries)->first(fn (array $query) => str_contains($query[0], 'LIMIT 10'));
        $this->assertStringContainsString('ORDER BY (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) DESC', $topQuery[0]);
        $this->assertSame('orders', $result['top_tables'][0]['table_name']);

        $cached = app(DatabaseTableStorageMonitor::class)->snapshot();
        $this->assertTrue($cached['cached']);
        $this->assertSame($result['checked_at']->toIso8601String(), $cached['checked_at']->toIso8601String());

        unset($connection);
    }

    public function test_search_sort_and_pagination_are_bound_and_allowlisted(): void
    {
        $queries = [];
        $this->mockMySqlConnection([
            [(object) ['table_count' => 80, 'data_bytes' => 1000, 'index_bytes' => 500]],
            [(object) ['matching_count' => 40]],
            [$this->metadata('users', 'InnoDB', '100', '100', '50')],
            [$this->metadata('orders', 'InnoDB', '90', '200', '80')],
        ], $queries);

        $result = app(DatabaseTableStorageMonitor::class)->snapshot([
            'table_search' => 'users%',
            'table_sort' => 'rows',
            'table_page' => 2,
        ]);

        $this->assertSame('users', $result['search']);
        $this->assertSame('rows', $result['sort']);
        $this->assertSame(2, $result['current_page']);
        $this->assertSame(2, $result['last_page']);
        $pageQuery = collect($queries)->first(fn (array $query) => str_contains($query[0], 'LIMIT ? OFFSET ?'));
        $this->assertNotNull($pageQuery);
        $this->assertStringContainsString('ORDER BY COALESCE(TABLE_ROWS, 0) DESC', $pageQuery[0]);
        $this->assertSame(['unit_test_schema', '%users%', 25, 25], $pageQuery[1]);
    }

    public function test_zero_table_metadata_is_a_valid_empty_database_snapshot(): void
    {
        $this->mockMySqlConnection([
            [(object) ['table_count' => 0, 'data_bytes' => 0, 'index_bytes' => 0]],
            [(object) ['matching_count' => 0]],
            [],
            [],
        ]);

        $result = app(DatabaseTableStorageMonitor::class)->snapshot();

        $this->assertSame('HEALTHY', $result['status']);
        $this->assertSame(0, $result['table_count']);
        $this->assertSame(0, $result['estimated_database_bytes']);
        $this->assertSame([], $result['tables']);
        $this->assertSame([], $result['top_tables']);
    }

    public function test_invalid_metadata_and_missing_permission_fail_to_unknown(): void
    {
        $this->mockMySqlConnection([[(object) ['table_count' => '-2', 'data_bytes' => 10, 'index_bytes' => 4]]]);
        $invalid = app(DatabaseTableStorageMonitor::class)->snapshot();
        $this->assertSame('UNKNOWN', $invalid['status']);
        $this->assertNull($invalid['estimated_database_bytes']);

        config(['system_monitoring.storage_snapshot_cache_key' => 'database-table-permission-test:'.bin2hex(random_bytes(5))]);
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('unit_test_schema');
        $connection->shouldReceive('select')->once()->andThrow(new \RuntimeException('permission denied for secret_schema'));
        $connection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')->once()->andReturn($connection);

        $denied = app(DatabaseTableStorageMonitor::class)->snapshot();
        $this->assertSame('UNKNOWN', $denied['status']);
        $this->assertStringNotContainsString('secret_schema', json_encode($denied));
    }

    public function test_database_connection_failure_is_reported_as_unknown(): void
    {
        DB::shouldReceive('build')->once()->andThrow(new \RuntimeException('connection failed with password=unit-secret'));

        $result = app(DatabaseTableStorageMonitor::class)->snapshot();

        $this->assertSame('UNKNOWN', $result['status']);
        $this->assertNull($result['estimated_database_bytes']);
        $this->assertStringNotContainsString('unit-secret', json_encode($result));
    }

    public function test_non_mysql_driver_is_not_configured_and_byte_formatting_is_human_readable(): void
    {
        config(['database.default' => 'sqlite']);
        $monitor = app(DatabaseTableStorageMonitor::class);

        $this->assertSame('NOT CONFIGURED', $monitor->snapshot()['status']);
        $this->assertSame('0 B', $monitor->formatBytes(0));
        $this->assertSame('1.00 KB', $monitor->formatBytes(1024));
        $this->assertSame('1.00 MB', $monitor->formatBytes(1048576));
        $this->assertSame('Unknown', $monitor->formatBytes(null));
    }

    private function mockMySqlConnection(array $responses, ?array &$queries = null): Connection
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('unit_test_schema');
        $connection->shouldReceive('select')->times(count($responses))->andReturnUsing(function (string $sql, array $bindings = []) use (&$responses, &$queries) {
            $queries[] = [$sql, $bindings];

            return array_shift($responses);
        });
        $connection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')->once()->andReturn($connection);

        return $connection;
    }

    private function metadata(string $name, string $engine, string $rows, string $data, string $index): object
    {
        return (object) [
            'table_name' => $name,
            'engine' => $engine,
            'estimated_rows' => $rows,
            'data_bytes' => $data,
            'index_bytes' => $index,
            'business_row' => 'business-row-secret',
        ];
    }
}
