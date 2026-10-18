<?php

namespace Tests\Unit;

use App\Services\Moderation\CloudflareProductModerationProvider;
use App\Services\Moderation\ProductModerationProviderException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareProductModerationProviderTest extends TestCase
{
    private CloudflareProductModerationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.cloudflare.account_id' => 'acct-test-12345678',
            'services.cloudflare.ai_token' => 'test-only-token-value',
            'product_moderation.models.primary' => '@cf/cloudflare/clef-flash',
            'product_moderation.models.object' => '@cf/facebook/detr-resnet-50',
        ]);
        $this->provider = new CloudflareProductModerationProvider();
    }

    public function test_clef_request_keeps_model_path_segments_and_returns_normalized_scores(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'result' => [
                    'answers' => [
                        'text_category' => ['SAFE' => 0.91, 'FIREARM' => 0.03],
                        'disguised' => 0.02,
                    ],
                ],
            ]),
        ]);

        $result = $this->provider->classifyText(['title' => 'Storage basket']);

        $this->assertSame('SAFE', $result['top_category']);
        $this->assertSame(0.91, $result['confidence']);
        Http::assertSent(function (ClientRequest $request): bool {
            return str_contains($request->url(), '/ai/run/@cf/cloudflare/clef-flash')
                && $request->hasHeader('Authorization', 'Bearer test-only-token-value')
                && ($request->data()['model'] ?? null) === 'clef-flash';
        });
    }

    public function test_quota_limit_is_a_sanitized_retryable_provider_error(): void
    {
        Http::fake(['*' => Http::response(['success' => false], 429)]);

        try {
            $this->provider->classifyText(['title' => 'Storage basket']);
            $this->fail('Expected a quota exception.');
        } catch (ProductModerationProviderException $exception) {
            $this->assertSame('AI_QUOTA_LIMIT', $exception->failureCode);
            $this->assertTrue($exception->retryable);
            $this->assertStringNotContainsString('test-only-token-value', $exception->getMessage());
        }
    }
}
