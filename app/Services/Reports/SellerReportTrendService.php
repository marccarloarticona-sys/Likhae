<?php

namespace App\Services\Reports;

use App\Models\Seller\SellerOrder;
use Illuminate\Support\Carbon;

final class SellerReportTrendService
{
    /** @return array<string, mixed> */
    public function build(
        int $sellerProfileId,
        Carbon $from,
        Carbon $to,
        ?int $productId,
        SellerSalesSummaryService $salesSummary,
    ): array {
        $timezone = (string) config('app.timezone', 'UTC');
        $start = $from->copy()->timezone($timezone)->startOfDay();
        $end = $to->copy()->timezone($timezone)->endOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $unit = $days <= 14 ? 'day' : ($days <= 183 ? 'week' : ($days <= 1095 ? 'month' : 'year'));
        $buckets = $this->buckets($start, $end, $unit);
        $bucketByDate = [];

        foreach ($buckets as $index => $bucket) {
            $date = $bucket['from']->copy()->startOfDay();
            $lastDate = $bucket['to']->copy()->startOfDay();
            while ($date->lte($lastDate)) {
                $bucketByDate[$date->toDateString()] = $index;
                $date->addDay();
            }
        }

        $dailyFinancials = $salesSummary->daily($sellerProfileId, $start, $end, $productId);
        foreach ($dailyFinancials as $date => $financials) {
            if (! array_key_exists($date, $bucketByDate)) {
                continue;
            }
            $index = $bucketByDate[$date];
            $buckets[$index]['gross'] += $financials['gross'];
            $buckets[$index]['commission'] += $financials['commission'];
        }

        $orders = SellerOrder::query()
            ->where('seller_profile_id', $sellerProfileId)
            ->whereBetween('created_at', [$from, $to])
            ->when($productId, fn ($query) => $query->whereHas('items', fn ($items) => $items->where('product_id', $productId)))
            ->cursor(['created_at', 'status']);

        foreach ($orders as $order) {
            if (! $order->created_at) {
                continue;
            }
            $date = $order->created_at->copy()->timezone($timezone)->toDateString();
            if (! array_key_exists($date, $bucketByDate)) {
                continue;
            }
            $index = $bucketByDate[$date];
            $buckets[$index]['orders']++;
            $buckets[$index]['completed'] += $order->status === 'COMPLETED' ? 1 : 0;
            $buckets[$index]['cancelled'] += $order->status === 'CANCELLED' ? 1 : 0;
        }

        $grossMaximum = (float) max(array_column($buckets, 'gross') ?: [0]);
        $orderMaximum = (int) max(array_column($buckets, 'orders') ?: [0]);
        $pointCount = max(1, count($buckets));
        foreach ($buckets as $index => &$bucket) {
            $bucket['x'] = $pointCount === 1 ? 500 : round($index / ($pointCount - 1) * 1000, 2);
            $bucket['net'] = $bucket['gross'] - $bucket['commission'];
            $bucket['gross_y'] = $this->chartY($bucket['gross'], $grossMaximum);
            $bucket['net_y'] = $this->chartY($bucket['net'], $grossMaximum);
            $bucket['commission_y'] = $this->chartY($bucket['commission'], $grossMaximum);
            $bucket['order_height'] = $orderMaximum > 0
                ? round($bucket['orders'] / $orderMaximum * 100, 2)
                : 0;
            $bucket['gross_exact'] = html_entity_decode('&#8369;').number_format($bucket['gross'], 2);
            $bucket['net_exact'] = html_entity_decode('&#8369;').number_format($bucket['net'], 2);
        }
        unset($bucket);

        $linePath = fn (string $field): string => collect($buckets)
            ->map(fn (array $bucket): string => $bucket['x'].','.$bucket[$field])
            ->implode(' ');

        return [
            'chart' => $buckets,
            'grouping_label' => match ($unit) {
                'day' => 'Daily totals',
                'week' => 'Weekly totals',
                'month' => 'Monthly totals',
                default => 'Annual totals',
            },
            'gross_path' => $linePath('gross_y'),
            'net_path' => $linePath('net_y'),
            'commission_path' => $linePath('commission_y'),
            'gross_area_path' => $this->areaPath($buckets, 'gross_y'),
            'gross_maximum' => $grossMaximum,
            'orders_maximum' => $orderMaximum,
            'has_gross' => $grossMaximum > 0,
            'has_orders' => $orderMaximum > 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buckets(Carbon $start, Carbon $end, string $unit): array
    {
        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $bucketEnd = match ($unit) {
                'week' => $cursor->copy()->addDays(6)->endOfDay()->min($end),
                'month' => $cursor->copy()->endOfMonth()->min($end),
                'year' => $cursor->copy()->endOfYear()->min($end),
                default => $cursor->copy()->endOfDay()->min($end),
            };
            $label = match ($unit) {
                'week' => $cursor->format('M j').'–'.$bucketEnd->format('M j'),
                'month' => $cursor->format('M Y'),
                'year' => $cursor->format('Y'),
                default => $cursor->format('M j'),
            };
            $buckets[] = [
                'from' => $cursor->copy(),
                'to' => $bucketEnd->copy(),
                'label' => $label,
                'gross' => 0.0,
                'commission' => 0.0,
                'net' => 0.0,
                'orders' => 0,
                'completed' => 0,
                'cancelled' => 0,
            ];
            $cursor = $bucketEnd->copy()->addDay()->startOfDay();
        }

        return $buckets;
    }

    private function chartY(float $value, float $maximum): float
    {
        return $maximum > 0 ? round(190 - ($value / $maximum * 160), 2) : 190;
    }

    /** @param list<array<string, mixed>> $buckets */
    private function areaPath(array $buckets, string $field): string
    {
        if ($buckets === []) {
            return '';
        }

        $points = collect($buckets)
            ->map(fn (array $bucket): string => 'L '.$bucket['x'].' '.$bucket[$field])
            ->implode(' ');
        $lastX = $buckets[array_key_last($buckets)]['x'];

        return 'M 0 190 '.$points.' L '.$lastX.' 190 Z';
    }
}
