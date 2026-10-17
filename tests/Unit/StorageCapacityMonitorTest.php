<?php

namespace Tests\Unit;

use App\Services\Monitoring\DatabaseTableStorageMonitor;
use App\Services\Monitoring\LaravelLogReader;
use App\Services\Monitoring\StorageCapacityMonitor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageCapacityMonitorTest extends TestCase
{
    private string $root;

    private ?string $linkPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'likhae-storage-monitor-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        config([
            'system_monitoring.storage_snapshot_cache_key' => 'storage-capacity-test:'.bin2hex(random_bytes(6)),
            'system_monitoring.storage_snapshot_ttl_seconds' => 300,
            'system_monitoring.storage_scan_timeout_seconds' => 2,
            'system_monitoring.storage_scan_max_entries' => 10000,
            'system_monitoring.storage_file_categories' => [],
            'system_monitoring.storage_cache_paths' => [],
            'logging.default' => 'null',
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $this->root.DIRECTORY_SEPARATOR.'public'],
            'filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root.DIRECTORY_SEPARATOR.'private'],
            'filesystems.disks.registrations' => ['driver' => 'local', 'root' => $this->root.DIRECTORY_SEPARATOR.'private'],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->linkPath !== null && is_link($this->linkPath)) {
            @unlink($this->linkPath);
        }
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_server_capacity_thresholds_use_measured_total_and_free_space(): void
    {
        $warningMonitor = $this->monitorWithCapacity(1000, 200);
        $warning = $warningMonitor->snapshot();
        $this->assertSame('WARNING', $warning['server_disk']['status']);
        $this->assertSame(800, $warning['server_disk']['used_bytes']);
        $this->assertSame(200, $warning['server_disk']['available_bytes']);
        $this->assertSame(80.0, $warning['server_disk']['usage_percent']);

        config(['system_monitoring.storage_snapshot_cache_key' => 'storage-capacity-critical:'.bin2hex(random_bytes(5))]);
        $critical = $this->monitorWithCapacity(1000, 100)->snapshot()['server_disk'];
        $this->assertSame('CRITICAL', $critical['status']);
        $this->assertSame(90.0, $critical['usage_percent']);
    }

    public function test_unavailable_volume_capacity_does_not_fabricate_a_percentage(): void
    {
        $disk = $this->monitorWithCapacity(false, false)->snapshot()['server_disk'];

        $this->assertSame('UNKNOWN', $disk['status']);
        $this->assertNull($disk['total_bytes']);
        $this->assertNull($disk['available_bytes']);
        $this->assertNull($disk['usage_percent']);
    }

    public function test_file_categories_are_measured_without_double_counting_the_volume(): void
    {
        $media = $this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'media';
        mkdir($media.DIRECTORY_SEPARATOR.'nested', 0700, true);
        file_put_contents($media.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'one.bin', '1234567');
        config([
            'system_monitoring.storage_file_categories' => [
                'all_media' => ['label' => 'Media', 'disk' => 'public', 'path' => 'media'],
                'nested_media' => ['label' => 'Nested media', 'disk' => 'public', 'path' => 'media/nested'],
            ],
            'system_monitoring.storage_snapshot_cache_key' => 'storage-capacity-files:'.bin2hex(random_bytes(5)),
        ]);

        $result = $this->monitorWithCapacity(1000, 200)->snapshot();

        $this->assertSame('HEALTHY', $result['categories']['all_media']['status']);
        $this->assertSame(7, $result['categories']['all_media']['bytes']);
        $this->assertSame(0, $result['categories']['nested_media']['bytes']);
        $this->assertSame(7, $result['uploads']['bytes']);
        $this->assertSame(1, $result['uploads']['files']);
        $this->assertSame(800, $result['server_disk']['used_bytes']);
        $this->assertNotSame($result['server_disk']['used_bytes'], $result['uploads']['bytes']);
        $this->assertSame('1234567', file_get_contents($media.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'one.bin'));
    }

    public function test_missing_and_over_bound_directories_are_unknown_not_zero(): void
    {
        config([
            'system_monitoring.storage_file_categories' => [
                'missing' => ['label' => 'Missing', 'disk' => 'public', 'path' => 'not-created'],
            ],
            'system_monitoring.storage_snapshot_cache_key' => 'storage-capacity-missing:'.bin2hex(random_bytes(5)),
        ]);
        $missing = $this->monitorWithCapacity(1000, 500)->snapshot();
        $this->assertSame('UNKNOWN', $missing['categories']['missing']['status']);
        $this->assertNull($missing['categories']['missing']['bytes']);
        $this->assertNull($missing['uploads']['bytes']);

        $large = $this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'large';
        mkdir($large, 0700, true);
        file_put_contents($large.DIRECTORY_SEPARATOR.'a.bin', 'a');
        file_put_contents($large.DIRECTORY_SEPARATOR.'b.bin', 'b');
        config([
            'system_monitoring.storage_file_categories' => [
                'large' => ['label' => 'Large', 'disk' => 'public', 'path' => 'large'],
            ],
            'system_monitoring.storage_scan_max_entries' => 1,
            'system_monitoring.storage_snapshot_cache_key' => 'storage-capacity-bounded:'.bin2hex(random_bytes(5)),
        ]);
        $bounded = $this->monitorWithCapacity(1000, 500)->snapshot();
        $this->assertSame('UNKNOWN', $bounded['categories']['large']['status']);
        $this->assertNull($bounded['categories']['large']['bytes']);
        $this->assertStringContainsString('bound', $bounded['categories']['large']['message']);
    }

    public function test_symbolic_links_are_not_followed_and_cache_freshness_is_visible(): void
    {
        $category = $this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'media';
        mkdir($category, 0700, true);
        file_put_contents($category.DIRECTORY_SEPARATOR.'inside.bin', 'in');
        $outside = $this->root.DIRECTORY_SEPARATOR.'outside.bin';
        file_put_contents($outside, 'outside-data');
        $this->linkPath = $category.DIRECTORY_SEPARATOR.'linked.bin';
        if (! @symlink($outside, $this->linkPath)) {
            $this->markTestSkipped('The Windows test environment does not permit creating a symbolic-link fixture.');
        }
        config([
            'system_monitoring.storage_file_categories' => [
                'media' => ['label' => 'Media', 'disk' => 'public', 'path' => 'media'],
            ],
        ]);

        $monitor = $this->monitorWithCapacity(1000, 500);
        $first = $monitor->snapshot();
        $second = $monitor->snapshot();

        $this->assertSame(2, $first['categories']['media']['bytes']);
        $this->assertSame(1, $first['categories']['media']['files']);
        $this->assertFalse($first['cached']);
        $this->assertTrue($second['cached']);
        $this->assertSame($first['checked_at']->toIso8601String(), $second['checked_at']->toIso8601String());
        $this->travel(61)->seconds();
        $freshness = $monitor->snapshot();
        $this->assertTrue($freshness['cached']);
        $this->assertGreaterThanOrEqual(61, $freshness['age_seconds']);
    }

    public function test_invalid_or_unreadable_category_root_is_unknown(): void
    {
        $fileRoot = $this->root.DIRECTORY_SEPARATOR.'not-a-directory';
        file_put_contents($fileRoot, 'x');
        config([
            'filesystems.disks.public.root' => $fileRoot,
            'system_monitoring.storage_file_categories' => [
                'uploads' => ['label' => 'Uploads', 'disk' => 'public', 'path' => 'uploads'],
            ],
        ]);

        $result = $this->monitorWithCapacity(1000, 500)->snapshot();

        $this->assertSame('UNKNOWN', $result['categories']['uploads']['status']);
        $this->assertNull($result['categories']['uploads']['bytes']);
    }

    public function test_unsafe_category_path_is_rejected(): void
    {
        config([
            'system_monitoring.storage_file_categories' => [
                'outside' => ['label' => 'Outside', 'disk' => 'public', 'path' => '../outside'],
            ],
        ]);

        $result = $this->monitorWithCapacity(1000, 500)->snapshot();

        $this->assertSame('UNKNOWN', $result['categories']['outside']['status']);
        $this->assertStringContainsString('safe', $result['categories']['outside']['message']);
    }

    private function monitorWithCapacity(int|float|false $total, int|float|false $free): StorageCapacityMonitor
    {
        return new class(app(DatabaseTableStorageMonitor::class), app(LaravelLogReader::class), $total, $free) extends StorageCapacityMonitor
        {
            public function __construct(
                DatabaseTableStorageMonitor $databaseTables,
                LaravelLogReader $logs,
                private int|float|false $total,
                private int|float|false $free,
            ) {
                parent::__construct($databaseTables, $logs);
            }

            protected function diskTotalSpace(string $path): int|float|false
            {
                return $this->total;
            }

            protected function diskFreeSpace(string $path): int|float|false
            {
                return $this->free;
            }
        };
    }
}
