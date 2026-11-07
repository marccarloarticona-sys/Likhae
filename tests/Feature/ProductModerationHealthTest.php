<?php

namespace Tests\Feature;

use App\Models\Admin\PlatformSetting;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModerationHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_degraded_when_only_backup_credentials_are_configured(): void
    {
        PlatformSetting::put('product_moderation_enabled', true, 'boolean');
        config([
            'product_moderation.provider' => 'cloudflare',
            'product_moderation.fallback.provider' => 'gemini',
            'product_moderation.fallback.enabled' => true,
            'services.cloudflare.account_id' => null,
            'services.cloudflare.ai_token' => null,
            'services.gemini.key' => 'test-only-key',
            'queue.default' => 'sync',
        ]);

        $health = app(ProductModerationService::class)->healthSnapshot();

        $this->assertSame('DEGRADED', $health['status']);
        $this->assertTrue($health['configured']);
        $this->assertFalse($health['primary_configured']);
        $this->assertTrue($health['fallback_configured']);
        $this->assertSame('sync', $health['queue_connection']);
    }
}
