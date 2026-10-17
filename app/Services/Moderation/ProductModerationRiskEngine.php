<?php

namespace App\Services\Moderation;

class ProductModerationRiskEngine
{
    /** @return array<string, mixed> */
    public function decide(array $text, array $image, bool $hasImages, bool $completeImages = true): array
    {
        if (! ($text['available'] ?? false) || ! ($image['available'] ?? false) || ! $hasImages || ! $completeImages) {
            return [
                'overall_risk' => 'UNKNOWN',
                'text_risk' => 'UNKNOWN',
                'image_risk' => 'UNKNOWN',
                'detected_categories' => [],
                'confidence' => null,
                'recommended_action' => 'MANUAL_REVIEW',
                'evidence_summary' => $hasImages
                    ? 'AI results were incomplete. Manual review is required.'
                    : 'No product image was available for a complete review.',
            ];
        }

        $taxonomy = array_keys(config('product_moderation.categories', []));
        $riskCategories = array_values(array_diff($taxonomy, ['SAFE', 'UNCERTAIN']));
        $textScore = $this->riskProbability((array) ($text['category_probabilities'] ?? []), $riskCategories);
        $imageCategories = [
            'REAL_FIREARM', 'GUN_LIKE_OBJECT', 'AMMUNITION', 'EXPLOSIVE', 'POSSIBLE_SUBSTANCE',
            'DRUG_PARAPHERNALIA', 'KNIFE_OR_WEAPON',
        ];
        $imageScore = $this->riskProbability((array) ($image['category_probabilities'] ?? []), $imageCategories);
        $mismatch = max(
            (float) ($image['mismatch_probability'] ?? 0),
            (float) ($text['disguised_probability'] ?? 0),
        );
        $mismatchRisk = $mismatch >= (float) config('product_moderation.risk.medium', 0.30);
        if ($mismatchRisk) {
            $imageScore = min(1, $imageScore + (float) config('product_moderation.risk.mismatch_boost', 0.20));
        }

        $textRisk = $this->level($textScore);
        $imageRisk = $this->level($imageScore);
        $highThreshold = (float) config('product_moderation.risk.high', 0.65);
        $criticalThreshold = (float) config('product_moderation.risk.critical', 0.86);
        $agreementThreshold = (float) config('product_moderation.risk.critical_agreement', 0.65);
        $bothStrong = $textScore >= $agreementThreshold && $imageScore >= $agreementThreshold;

        $overall = match (true) {
            $bothStrong && min($textScore, $imageScore) >= $criticalThreshold => 'CRITICAL',
            $textScore >= $criticalThreshold || $imageScore >= $criticalThreshold => 'HIGH',
            $textScore >= $highThreshold || $imageScore >= $highThreshold || $bothStrong => 'HIGH',
            $textScore >= (float) config('product_moderation.risk.medium', 0.30)
                || $imageScore >= (float) config('product_moderation.risk.medium', 0.30)
                || $mismatchRisk => 'MEDIUM',
            default => 'LOW',
        };

        $categories = collect([
            ...array_keys(array_filter((array) ($text['category_probabilities'] ?? []), fn ($score, $key): bool => in_array($key, $riskCategories, true) && (float) $score >= 0.20, ARRAY_FILTER_USE_BOTH)),
            ...array_keys(array_filter((array) ($image['category_probabilities'] ?? []), fn ($score, $key): bool => in_array($key, $imageCategories, true) && (float) $score >= 0.20, ARRAY_FILTER_USE_BOTH)),
        ])->unique()->values()->all();

        return [
            'overall_risk' => $overall,
            'text_risk' => $textRisk,
            'image_risk' => $imageRisk,
            'detected_categories' => $categories,
            'confidence' => round(max($textScore, $imageScore), 4),
            'recommended_action' => match ($overall) {
                'LOW' => 'NO_ACTION',
                'MEDIUM' => 'REQUEST_SELLER_EDIT',
                'HIGH' => 'TEMPORARY_LISTING_HOLD',
                'CRITICAL' => 'ESCALATE',
                default => 'MANUAL_REVIEW',
            },
            'evidence_summary' => $mismatchRisk
                ? 'AI signals suggest a possible mismatch or unclear presentation. Admin review is required.'
                : ($categories === []
                    ? 'No strong prohibited-item signal was identified by the configured models.'
                    : 'Possible policy concern detected. This is an AI review signal, not a legal conclusion.'),
        ];
    }

    /** @param array<string, mixed> $probabilities @param list<string> $categories */
    private function riskProbability(array $probabilities, array $categories): float
    {
        $scores = [];
        foreach ($categories as $category) {
            if (is_numeric($probabilities[$category] ?? null)) {
                $scores[] = (float) $probabilities[$category];
            }
        }

        return $scores === [] ? 0.0 : max($scores);
    }

    private function level(float $score): string
    {
        if ($score >= (float) config('product_moderation.risk.high', 0.65)) {
            return 'HIGH';
        }
        if ($score >= (float) config('product_moderation.risk.medium', 0.30)) {
            return 'MEDIUM';
        }

        return 'LOW';
    }
}
