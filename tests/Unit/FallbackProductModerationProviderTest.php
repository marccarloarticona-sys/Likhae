<?php

namespace Tests\Unit;

use App\Services\Moderation\CloudflareProductModerationProvider;
use App\Services\Moderation\FallbackProductModerationProvider;
use App\Services\Moderation\GeminiProductModerationProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FallbackProductModerationProviderTest extends TestCase
{
    private FallbackProductModerationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'product_moderation.fallback.enabled' => true,
            'product_moderation.models.primary' => '@cf/cloudflare/clef-flash',
            'product_moderation.models.object' => '@cf/facebook/detr-resnet-50',
            'services.cloudflare.account_id' => 'acct-test-12345678',
            'services.cloudflare.ai_token' => 'test-only-cloudflare-token',
            'services.gemini.key' => 'test-only-gemini-key',
            'services.gemini.model' => 'gemini-flash-lite-latest',
        ]);
        $this->provider = new FallbackProductModerationProvider(
            new CloudflareProductModerationProvider(),
            new GeminiProductModerationProvider(),
        );
    }

    public function test_cloudflare_is_used_before_the_configured_gemini_backup(): void
    {
        Http::fake([
            '*api.cloudflare.com*' => Http::response([
                'success' => true,
                'result' => ['answers' => ['text_category' => ['SAFE' => 0.96], 'disguised' => 0.01]],
            ]),
            '*generativelanguage.googleapis.com*' => Http::response([], 500),
        ]);

        $result = $this->provider->classifyText(['title' => 'Storage basket']);

        $this->assertSame('Cloudflare Workers AI', $result['provider_source']);
        $this->assertSame('@cf/cloudflare/clef-flash', $result['model_source']);
        $this->assertFalse($result['used_fallback']);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_gemini_is_used_when_cloudflare_text_classification_fails(): void
    {
        Http::fake([
            '*api.cloudflare.com*' => Http::response(['success' => false], 503),
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'category_probabilities' => ['SAFE' => 0.91, 'FIREARM' => 0.02],
                            'disguised_probability' => 0.01,
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $result = $this->provider->classifyText(['title' => 'Storage basket']);

        $this->assertSame('Google Gemini', $result['provider_source']);
        $this->assertSame('gemini-flash-lite-latest', $result['model_source']);
        $this->assertTrue($result['used_fallback']);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com')
            && ! str_contains($request->url(), 'key=')
            && $request->hasHeader('x-goog-api-key', 'test-only-gemini-key'));
    }

    public function test_gemini_image_backup_receives_inline_image_data(): void
    {
        Http::fake([
            '*api.cloudflare.com*' => Http::response(['success' => false], 503),
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'category_probabilities' => ['ORDINARY_PRODUCT' => 0.90, 'REAL_FIREARM' => 0.01],
                            'mismatch_probability' => 0.02,
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $result = $this->provider->classifyImages(
            ['title' => 'Storage basket'],
            [['mime_type' => 'image/jpeg', 'bytes' => 'sample-image-bytes']],
        );

        $this->assertSame('Google Gemini', $result['provider_source']);
        $this->assertTrue($result['used_fallback']);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com')
            && data_get($request->data(), 'contents.0.parts.0.inline_data.mime_type') === 'image/jpeg'
            && data_get($request->data(), 'contents.0.parts.0.inline_data.data') === base64_encode('sample-image-bytes'));
    }
}
