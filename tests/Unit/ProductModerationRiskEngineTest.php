<?php

namespace Tests\Unit;

use App\Services\Moderation\ProductModerationRiskEngine;
use Tests\TestCase;

class ProductModerationRiskEngineTest extends TestCase
{
    private ProductModerationRiskEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ProductModerationRiskEngine();
    }

    public function test_clear_text_and_image_signals_produce_low_risk(): void
    {
        $result = $this->engine->decide(
            ['available' => true, 'category_probabilities' => ['SAFE' => 0.96, 'FIREARM' => 0.02]],
            ['available' => true, 'category_probabilities' => ['ORDINARY_PRODUCT' => 0.97, 'REAL_FIREARM' => 0.01]],
            true,
        );

        $this->assertSame('LOW', $result['overall_risk']);
        $this->assertSame('NO_ACTION', $result['recommended_action']);
    }

    public function test_medium_text_signal_requests_review_without_holding_listing(): void
    {
        $result = $this->engine->decide(
            ['available' => true, 'category_probabilities' => ['SAFE' => 0.48, 'POSSIBLE_FIREARM' => 0.42]],
            ['available' => true, 'category_probabilities' => ['ORDINARY_PRODUCT' => 0.91, 'GUN_LIKE_OBJECT' => 0.03]],
            true,
        );

        $this->assertSame('MEDIUM', $result['overall_risk']);
        $this->assertSame('REQUEST_SELLER_EDIT', $result['recommended_action']);
    }

    public function test_high_image_signal_recommends_a_temporary_listing_hold(): void
    {
        $result = $this->engine->decide(
            ['available' => true, 'category_probabilities' => ['SAFE' => 0.96]],
            ['available' => true, 'category_probabilities' => ['ORDINARY_PRODUCT' => 0.20, 'REAL_FIREARM' => 0.72]],
            true,
        );

        $this->assertSame('HIGH', $result['overall_risk']);
        $this->assertSame('TEMPORARY_LISTING_HOLD', $result['recommended_action']);
    }

    public function test_incomplete_model_or_image_input_is_unknown_not_safe(): void
    {
        $result = $this->engine->decide(
            ['available' => true, 'category_probabilities' => ['SAFE' => 0.99]],
            ['available' => false, 'category_probabilities' => []],
            true,
            false,
        );

        $this->assertSame('UNKNOWN', $result['overall_risk']);
        $this->assertSame('MANUAL_REVIEW', $result['recommended_action']);
    }

    public function test_text_image_mismatch_is_escalated_to_medium_review(): void
    {
        $result = $this->engine->decide(
            ['available' => true, 'category_probabilities' => ['SAFE' => 0.95, 'FIREARM' => 0.01]],
            ['available' => true, 'category_probabilities' => ['ORDINARY_PRODUCT' => 0.96, 'REAL_FIREARM' => 0.01], 'mismatch_probability' => 0.8],
            true,
        );

        $this->assertSame('MEDIUM', $result['overall_risk']);
        $this->assertStringContainsString('mismatch', strtolower($result['evidence_summary']));
    }
}
