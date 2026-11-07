<?php

namespace Tests\Unit;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Services\Moderation\ProductModerationAlignment;
use App\Services\Moderation\ProductModerationRevisionHasher;
use App\Services\Moderation\ProductModerationRiskEngine;
use Tests\TestCase;

class ProductModerationAlignmentTest extends TestCase
{
    public function test_matching_business_and_subcategory_remain_low_risk(): void
    {
        $alignment = $this->assess();
        $this->assertSame('MATCH', $alignment['hierarchy_status']);
        $this->assertFalse($alignment['requires_review']);
        $this->assertFalse($alignment['incomplete']);
        $this->assertSame('LOW', $this->risk($alignment)['overall_risk']);
    }

    public function test_wrong_business_hierarchy_requires_review_even_when_ai_sees_an_ordinary_product(): void
    {
        $context = $this->context();
        $context['seller_line_of_business']['id'] = 99;
        $alignment = $this->assess($context);
        $this->assertSame('MISMATCH', $alignment['hierarchy_status']);
        $this->assertSame('MEDIUM', $this->risk($alignment)['overall_risk']);
        $this->assertSame([], $this->risk($alignment)['detected_categories']);
    }

    public function test_semantic_business_or_subcategory_mismatch_requires_review_without_a_listing_hold(): void
    {
        foreach (ProductModerationAlignment::SCORE_KEYS as $key) {
            $alignment = $this->assess(image: [$key => 0.99]);
            $this->assertTrue($alignment['requires_review']);
            $result = $this->risk($alignment);
            $this->assertSame('MEDIUM', $result['overall_risk']);
            $this->assertSame('REQUEST_SELLER_EDIT', $result['recommended_action']);
            $this->assertSame('LOW', $result['image_risk']);
        }
    }

    public function test_missing_scores_or_context_require_manual_review_instead_of_implying_a_match(): void
    {
        foreach ([$this->assess(image: ['business_mismatch_probability' => null]), $this->assess([])] as $alignment) {
            $this->assertTrue($alignment['incomplete']);
            $this->assertSame('UNKNOWN', $this->risk($alignment)['overall_risk']);
            $this->assertSame('MANUAL_REVIEW', $this->risk($alignment)['recommended_action']);
        }
    }

    public function test_business_and_category_metadata_changes_invalidate_old_scan_fingerprints(): void
    {
        $business = new Category(['name' => "Men's Apparel", 'is_active' => true]);
        $business->id = 1;
        $category = new Category(['name' => 'Activewear', 'parent_id' => 1, 'is_active' => true]);
        $category->id = 2;
        $category->setRelation('parent', $business);
        $seller = (new SellerProfile())->setRelation('primaryCategory', $business);
        $product = (new Product(['name' => 'Basketball jersey']))
            ->setRelation('category', $category)->setRelation('sellerProfile', $seller);
        foreach (['images', 'options', 'variants'] as $relation) {
            $product->setRelation($relation, collect());
        }
        $hasher = new ProductModerationRevisionHasher();
        $before = $hasher->hash($product);
        $business->name = 'Electronics';
        $this->assertNotSame($before, $hasher->hash($product));
        $before = $hasher->hash($product);
        $category->description = 'Sports clothing only';
        $this->assertNotSame($before, $hasher->hash($product));
    }

    public function test_business_and_subcategory_are_scored_separately(): void
    {
        $scores = [
            'available' => true,
            'business_mismatch_probability' => 0.01,
            'subcategory_mismatch_probability' => 0.88,
        ];
        $alignment = ProductModerationAlignment::assess($this->context(), $scores, $scores, true);

        $this->assertSame('NO_MISMATCH_SIGNAL', $alignment['checks']['business_mismatch_probability']['status']);
        $this->assertArrayNotHasKey('category_mismatch_probability', $alignment['checks']);
        $this->assertSame('POSSIBLE_MISMATCH', $alignment['checks']['subcategory_mismatch_probability']['status']);
    }

    public function test_root_category_does_not_report_a_missing_subcategory_as_a_mismatch(): void
    {
        $context = $this->context();
        $context['selected_category']['parent_id'] = null;
        $context['selected_parent_category'] = null;
        $alignment = $this->assess($context);

        $this->assertSame('NOT_APPLICABLE', $alignment['checks']['subcategory_mismatch_probability']['status']);
        $this->assertFalse($alignment['incomplete']);
    }

    private function context(): array
    {
        return [
            'seller_line_of_business' => ['id' => 1, 'available' => true],
            'selected_category' => ['id' => 2, 'parent_id' => 1, 'available' => true],
            'selected_parent_category' => ['id' => 1, 'available' => true],
        ];
    }

    private function assess(?array $context = null, array $image = []): array
    {
        $signal = ['available' => true, 'business_mismatch_probability' => 0.01, 'subcategory_mismatch_probability' => 0.03];

        return ProductModerationAlignment::assess($context ?? $this->context(), $signal, array_replace($signal, $image), true);
    }

    private function risk(array $alignment): array
    {
        return (new ProductModerationRiskEngine())->decide(
            ['available' => true, 'category_probabilities' => ['SAFE' => 1]],
            ['available' => true, 'category_probabilities' => ['ORDINARY_PRODUCT' => 1]],
            true, true, $alignment,
        );
    }
}
