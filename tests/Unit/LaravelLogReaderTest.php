<?php

namespace Tests\Unit;

use App\Services\Monitoring\LaravelLogReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LaravelLogReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'likhae-log-reader-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        config([
            'logging.default' => 'single',
            'logging.channels.single' => ['driver' => 'single', 'path' => $this->root.DIRECTORY_SEPARATOR.'laravel.log'],
            'system_monitoring.log_max_files' => 8,
            'system_monitoring.log_max_bytes_per_file' => 262144,
            'system_monitoring.log_max_total_bytes' => 2097152,
            'system_monitoring.log_max_entries' => 500,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_reads_real_format_entries_and_redacts_sensitive_values_and_traces(): void
    {
        $time = now()->format('Y-m-d H:i:s');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'laravel.log', implode("\n", [
            "[{$time}] testing.ERROR: Request failed password=very-secret-pass email=person@example.com <script>alert(1)</script>",
            '#0 /srv/private/Database.php(17): connect() with auth_token=jwt-secret',
            "[{$time}] testing.WARNING: Retry limit reached",
        ]));

        $result = (new LaravelLogReader($this->root))->read();

        $this->assertTrue($result['available']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('ERROR', $result['entries'][0]['severity']);
        $this->assertStringContainsString('message details withheld', $result['entries'][0]['summary']);
        $this->assertStringNotContainsString('very-secret-pass', $result['entries'][0]['summary']);
        $this->assertStringNotContainsString('person@example.com', $result['entries'][0]['summary']);
        $this->assertStringNotContainsString('<script>', $result['entries'][0]['summary']);
        $this->assertStringNotContainsString('Database.php', $result['entries'][0]['summary']);
        $this->assertSame('WARNING', $result['entries'][1]['severity']);
    }

    public function test_it_bounds_tail_reads_and_marks_truncated_coverage_partial(): void
    {
        config(['system_monitoring.log_max_bytes_per_file' => 4096, 'system_monitoring.log_max_total_bytes' => 4096]);
        $record = '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: '.str_repeat('x', 180)."\n";
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'laravel.log', str_repeat($record, 100));

        $result = (new LaravelLogReader($this->root))->read();

        $this->assertLessThanOrEqual(4096, $result['bytes_read']);
        $this->assertTrue($result['partial']);
        $this->assertLessThanOrEqual(500, count($result['entries']));
    }

    public function test_it_shows_safe_product_moderation_failure_codes_and_hides_cloudflare_credentials(): void
    {
        $time = now()->format('Y-m-d H:i:s');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'laravel.log', implode("\n", [
            "[{$time}] testing.WARNING: Product moderation provider failure: AI_QUOTA_LIMIT.",
            "[{$time}] testing.ERROR: Authorization: Bearer cloudflare-secret-token-value",
        ]));

        $result = (new LaravelLogReader($this->root))->read();

        $this->assertStringContainsString('AI_QUOTA_LIMIT', $result['entries'][0]['summary']);
        $this->assertStringNotContainsString('cloudflare-secret-token-value', $result['entries'][1]['summary']);
        $this->assertStringContainsString('credentials and request details withheld', $result['entries'][1]['summary']);
    }

    public function test_it_supports_daily_rotated_files_without_reading_outside_the_configured_root(): void
    {
        $dailyPath = $this->root.DIRECTORY_SEPARATOR.'laravel.log';
        $dailyFile = $this->root.DIRECTORY_SEPARATOR.'laravel-'.now()->format('Y-m-d').'.log';
        file_put_contents($dailyFile, '['.now()->format('Y-m-d H:i:s').'] testing.INFO: Rotated entry');
        config(['logging.channels.single' => ['driver' => 'daily', 'path' => $dailyPath]]);

        $result = (new LaravelLogReader($this->root))->read();
        $this->assertTrue($result['available']);
        $this->assertSame('Application info log record; message details withheld.', $result['entries'][0]['summary']);

        $outside = dirname($this->root).DIRECTORY_SEPARATOR.'likhae-outside-log-'.bin2hex(random_bytes(5)).'.log';
        file_put_contents($outside, '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: Must not be read');
        config(['logging.channels.single' => ['driver' => 'single', 'path' => $outside]]);
        $blocked = (new LaravelLogReader($this->root))->read();
        @unlink($outside);

        $this->assertFalse($blocked['available']);
        $this->assertSame([], $blocked['entries']);
    }

    public function test_storage_inventory_reports_only_aggregate_log_metadata(): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'laravel.log';
        file_put_contents($path, 'private log contents must not be returned');
        config(['logging.channels.single' => ['driver' => 'single', 'path' => $path]]);

        $inventory = (new LaravelLogReader($this->root))->storageInventory();

        $this->assertSame('HEALTHY', $inventory['status']);
        $this->assertSame(filesize($path), $inventory['bytes']);
        $this->assertSame(1, $inventory['files']);
        $this->assertNotNull($inventory['last_updated']);
        $this->assertStringNotContainsString('private log contents', json_encode($inventory));
        $this->assertStringNotContainsString('laravel.log', json_encode($inventory));
    }
}
