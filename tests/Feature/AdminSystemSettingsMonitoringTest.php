<?php

namespace Tests\Feature;

use App\Models\Admin\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSystemSettingsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = storage_path('logs/.system-settings-test-'.bin2hex(random_bytes(6)).'.log');
        config([
            'logging.default' => 'single',
            'logging.channels.single' => ['driver' => 'single', 'path' => $this->logFile],
            'app.env' => 'testing',
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->logFile);
        parent::tearDown();
    }

    public function test_system_settings_shows_working_checks_without_duplicate_or_unavailable_panels(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.system-settings'));

        $response->assertOk()
            ->assertSee('System Health Overview')
            ->assertSee('Database')
            ->assertSee('HEALTHY')
            ->assertSee('Storage Usage & Capacity')
            ->assertSee('Database Table Usage')
            ->assertSee('File Storage Breakdown')
            ->assertSee('Recent System Errors')
            ->assertSee('Admin Preferences')
            ->assertSee('Appearance')
            ->assertSee('Sounds')
            ->assertDontSee('Top 10 Largest Database Tables')
            ->assertDontSee('Queue & Scheduler')
            ->assertDontSee('Scheduler Health')
            ->assertDontSee('Recent Failed Jobs')
            ->assertDontSee('HTTP response')
            ->assertDontSee('Coming soon')
            ->assertSee('last hour Unknown');
    }

    public function test_all_monitoring_routes_are_guest_and_non_admin_only(): void
    {
        $paths = [
            'admin.system-settings', 'admin.system-settings.errors',
            'admin.system-settings.logs', 'admin.system-settings.audit-logs',
        ];
        foreach ($paths as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('admin.system-settings.storage.refresh'))->assertRedirect(route('login'));

        foreach ([User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER] as $role) {
            $user = User::factory()->create([
                'account_type' => $role,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]);
            foreach ($paths as $route) {
                $this->actingAs($user)->get(route($route))->assertForbidden();
            }
        }
    }

    public function test_non_admin_cannot_refresh_storage_measurements(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($buyer)->post(route('admin.system-settings.storage.refresh'))->assertForbidden();
    }

    public function test_storage_refresh_is_rate_limited_and_does_not_modify_admin_preferences(): void
    {
        $admin = $this->admin();
        $before = $admin->notification_preferences;

        $this->actingAs($admin)->post(route('admin.system-settings.storage.refresh'))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.system-settings.storage.refresh'))->assertTooManyRequests();

        $this->assertSame($before, $admin->fresh()->notification_preferences);
    }

    public function test_admin_can_open_each_read_only_monitoring_view(): void
    {
        $admin = $this->admin();
        foreach ([
            'admin.system-settings', 'admin.system-settings.errors',
            'admin.system-settings.logs', 'admin.system-settings.audit-logs',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_error_preview_redacts_sensitive_log_text_and_escapes_markup(): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[{$timestamp}] production.ERROR: Request failed password=local-secret email=person@example.com <script>alert(1)</script>\n#0 /private/trace.php\n");
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.system-settings.errors'));

        $response->assertOk()
            ->assertDontSee('local-secret')
            ->assertDontSee('person@example.com')
            ->assertDontSee('/private/trace.php')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('message details withheld');
    }

    public function test_error_view_filters_by_severity_status_source_date_and_safe_search_text(): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        file_put_contents($this->logFile, implode("\n", [
            "[{$timestamp}] production.ERROR: http_status=500 email=hidden@example.com",
            "[{$timestamp}] production.WARNING: retry required",
        ]));
        $filters = [
            'severity' => 'ERROR',
            'status' => '500',
            'source' => 'single',
            'from' => now()->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
            'q' => 'HTTP 500',
        ];

        $response = $this->actingAs($this->admin())->get(route('admin.system-settings.errors', $filters));

        $response->assertOk()->assertSee('HTTP 500 response recorded')->assertDontSee('Application warning recorded');
    }

    public function test_audit_viewer_shows_admin_attributed_events_and_no_other_roles(): void
    {
        $admin = $this->admin();
        $seller = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        AuditLog::query()->create(['actor_user_id' => $admin->id, 'event' => 'ADMIN_POLICY_UPDATED', 'auditable_type' => User::class, 'auditable_id' => 99, 'created_at' => now()]);
        AuditLog::query()->create(['actor_user_id' => $seller->id, 'event' => 'SELLER_PROFILE_UPDATED', 'auditable_type' => User::class, 'auditable_id' => 100, 'created_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.system-settings.audit-logs'));

        $response->assertOk()->assertSee('Admin Policy Updated')->assertDontSee('Seller Profile Updated');
    }

    public function test_audit_viewer_applies_actor_action_entity_date_and_search_filters(): void
    {
        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'first_name' => 'Filterable',
            'last_name' => 'Admin',
        ]);
        AuditLog::query()->create(['actor_user_id' => $admin->id, 'event' => 'ADMIN_POLICY_UPDATED', 'auditable_type' => User::class, 'auditable_id' => 88, 'created_at' => now()]);
        AuditLog::query()->create(['actor_user_id' => $admin->id, 'event' => 'ADMIN_ACCOUNT_REVIEWED', 'auditable_type' => User::class, 'auditable_id' => 89, 'created_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.system-settings.audit-logs', [
            'actor' => $admin->id,
            'action' => 'ADMIN_POLICY_UPDATED',
            'entity' => User::class,
            'from' => now()->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
            'q' => 'Filterable',
        ]));

        $response->assertOk()->assertViewHas('entries', function ($entries): bool {
            $rows = $entries->items();

            return $entries->total() === 1
                && count($rows) === 1
                && $rows[0]->event === 'ADMIN_POLICY_UPDATED';
        });
    }

    public function test_existing_admin_audit_pdf_export_remains_admin_only_and_downloadable(): void
    {
        $admin = $this->admin();
        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'ADMIN_SYSTEM_SETTINGS_REVIEWED',
            'auditable_type' => User::class,
            'auditable_id' => $admin->id,
            'created_at' => now(),
        ]);

        $this->get(route('admin.audit.export'))->assertRedirect(route('login'));

        $response = $this->actingAs($admin)->get(route('admin.audit.export'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_failed_job_metadata_is_safe_and_never_renders_serialized_payload_or_exception_text(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessPayment', 'data' => ['secret' => 'test-only-token']]),
            'exception' => 'RuntimeException: payment auth_token=test-only-token',
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.system-settings'));

        $response->assertOk()->assertSee('ProcessPayment')->assertSee('RuntimeException')->assertDontSee('test-only-token');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }
}
