<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seller\Voucher;
use App\Services\Marketplace\PlatformCampaignCatalog;
use Illuminate\Http\JsonResponse;

class PlatformEventApiController extends Controller
{
    public function index(PlatformCampaignCatalog $campaigns): JsonResponse
    {
        $events = $campaigns->available()
            ->map(function (Voucher $campaign): array {
                $startsAt = $campaign->starts_at;
                $discount = strtoupper((string) $campaign->discount_type) === 'PERCENT'
                    ? number_format((float) $campaign->discount_value, 0).'% off'
                    : 'PHP '.number_format((float) $campaign->discount_value, 2).' off';
                $minimumSpend = (float) $campaign->minimum_order_amount > 0
                    ? ' Minimum spend PHP '.number_format((float) $campaign->minimum_order_amount, 2).'.'
                    : '';

                return [
                    'id' => $campaign->id,
                    'title' => $campaign->name ?: 'Platform event',
                    'body' => 'Use code '.$campaign->code.' for '.$discount.'.'.$minimumSpend,
                    'code' => $campaign->code,
                    'status' => $startsAt?->isFuture() ? 'upcoming' : 'active',
                    'published_at' => ($startsAt ?? $campaign->created_at)?->toIso8601String(),
                    'starts_at' => $startsAt?->toIso8601String(),
                    'ends_at' => $campaign->ends_at?->toIso8601String(),
                    'discount_type' => $campaign->discount_type,
                    'discount_value' => (float) $campaign->discount_value,
                    'minimum_order_amount' => (float) $campaign->minimum_order_amount,
                    'maximum_discount_amount' => $campaign->maximum_discount_amount === null
                        ? null
                        : (float) $campaign->maximum_discount_amount,
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $events]);
    }
}
