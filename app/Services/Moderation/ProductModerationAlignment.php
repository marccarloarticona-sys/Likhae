<?php

namespace App\Services\Moderation;

use App\Models\Seller\Category;
use App\Models\Seller\Product;

class ProductModerationAlignment
{
    public const SCORE_KEYS = [
        'business_mismatch_probability',
        'subcategory_mismatch_probability',
    ];

    public static function context(Product $product): array
    {
        $product->loadMissing(['category.parent', 'sellerProfile.primaryCategory']);

        return [
            'version' => 1,
            'seller_line_of_business' => self::category($product->sellerProfile?->primaryCategory),
            'selected_category' => self::category($product->category),
            'selected_parent_category' => self::category($product->category?->parent),
        ];
    }

    private static function category(?Category $category): ?array
    {
        return $category ? [
            'id' => (int) $category->id,
            'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
            'name' => $category->name,
            'description' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $category->description)) ?: ''), 0, 300),
            'available' => $category->is_active && $category->archived_at === null,
        ] : null;
    }

    public static function assess(array $context, array $text, array $image, bool $completeImages): array
    {
        $business = $context['seller_line_of_business'] ?? null;
        $category = $context['selected_category'] ?? null;
        $parent = $context['selected_parent_category'] ?? null;
        $hierarchy = ! $business || ! $category ? 'UNKNOWN' : (
            ! $business['available'] || ! $category['available']
                || ($category['parent_id'] && ! ($parent['available'] ?? false))
                ? 'UNAVAILABLE'
                : (($category['id'] === $business['id'] || $category['parent_id'] === $business['id']) ? 'MATCH' : 'MISMATCH')
        );
        $checks = [];
        foreach (self::SCORE_KEYS as $key) {
            if ($key === 'subcategory_mismatch_probability' && ! ($category['parent_id'] ?? null)) {
                $checks[$key] = ['status' => 'NOT_APPLICABLE', 'probability' => null, 'complete' => true];

                continue;
            }
            $hasRequiredContext = match ($key) {
                'business_mismatch_probability' => (bool) $business,
                default => (bool) $category,
            };
            $scores = [$text[$key] ?? null, $image[$key] ?? null];
            $complete = $hasRequiredContext && $completeImages
                && ($text['available'] ?? false) && ($image['available'] ?? false)
                && is_numeric($scores[0]) && is_numeric($scores[1]);
            $availableScores = array_filter($scores, 'is_numeric');
            $probability = $availableScores === [] ? null : round(max(0, min(1, max($availableScores))), 4);
            $checks[$key] = [
                'status' => $probability === null ? 'UNKNOWN' : (
                    $probability >= (float) config('product_moderation.risk.medium', 0.30) ? 'POSSIBLE_MISMATCH' : 'NO_MISMATCH_SIGNAL'
                ),
                'probability' => $probability,
                'complete' => $complete,
            ];
        }

        return [
            'context' => $context,
            'hierarchy_status' => $hierarchy,
            'checks' => $checks,
            'requires_review' => in_array($hierarchy, ['MISMATCH', 'UNAVAILABLE'], true)
                || in_array('POSSIBLE_MISMATCH', array_column($checks, 'status'), true),
            'incomplete' => $hierarchy === 'UNKNOWN'
                || in_array('UNKNOWN', array_column($checks, 'status'), true)
                || in_array(false, array_column($checks, 'complete'), true),
        ];
    }
}
