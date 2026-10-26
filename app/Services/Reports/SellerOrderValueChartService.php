<?php

namespace App\Services\Reports;

use App\Models\Seller\SellerOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SellerOrderValueChartService
{
    /** @return array<string, mixed> */
    public function build(int $sellerProfileId, ?string $requestedPeriod): array
    {
        $period = in_array($requestedPeriod, ['7', '30', 'quarter'], true) ? $requestedPeriod : '7';
        $now = now();
        [$start, $periodLabel, $groupingLabel, $points] = $this->buckets($period, $now);
        $hasOrders = false;

        $points = $points->map(function (array $point) use ($sellerProfileId, &$hasOrders): array {
            $aggregate = SellerOrder::query()
                ->where('seller_orders.seller_profile_id', $sellerProfileId)
                ->where('seller_orders.status', 'COMPLETED')
                ->whereHas('order', fn ($query) => $query
                    ->where('status', 'COMPLETED')
                    ->whereBetween('completed_at', [$point['from'], $point['to']]))
                ->selectRaw('COUNT(*) AS order_count, COALESCE(SUM(seller_orders.grand_total), 0) AS order_value')
                ->first();

            $point['total'] = (float) ($aggregate?->order_value ?? 0);
            $hasOrders = $hasOrders || (int) ($aggregate?->order_count ?? 0) > 0;

            return $point;
        });

        $scale = $this->scale($points);
        $points = $points->map(function (array $point) use ($scale): array {
            $point['height'] = $scale['maximum'] > 0
                ? round($point['total'] / $scale['maximum'] * 100, 2)
                : 0;
            $point['compact'] = $this->compactCurrency($point['total']);
            $point['exact'] = html_entity_decode('&#8369;').number_format($point['total'], 2);

            return $point;
        });

        return [
            'period' => $period,
            'period_label' => $periodLabel,
            'grouping_label' => $groupingLabel,
            'gross' => (float) $points->sum('total'),
            'chart' => $points,
            'scale' => $scale,
            'has_orders' => $hasOrders,
            'start' => $start,
        ];
    }

    /** @return array{0: Carbon, 1: string, 2: string, 3: Collection<int, array<string, mixed>>} */
    private function buckets(string $period, Carbon $now): array
    {
        if ($period === 'quarter') {
            $start = $now->copy()->startOfQuarter()->startOfDay();
            $points = collect();
            $cursor = $start->copy();
            while ($cursor->lte($now)) {
                $to = $cursor->copy()->endOfMonth()->min($now->copy());
                $points->push([
                    'key' => $cursor->format('Y-m'),
                    'from' => $cursor->copy()->startOfMonth(),
                    'to' => $to,
                    'date' => $cursor->format('F Y'),
                    'label' => $cursor->format('M'),
                    'total' => 0.0,
                ]);
                $cursor->addMonth();
            }

            return [$start, 'this quarter', 'Monthly totals', $points];
        }

        $days = $period === '30' ? 30 : 7;
        $start = $now->copy()->subDays($days - 1)->startOfDay();
        if ($days === 30) {
            $points = collect(range(0, 4))->map(function (int $offset) use ($start, $now): array {
                $from = $start->copy()->addDays($offset * 7);
                $to = $from->copy()->addDays(6)->endOfDay()->min($now->copy());

                return [
                    'key' => $from->toDateString(),
                    'from' => $from,
                    'to' => $to,
                    'date' => $from->format('M j').' - '.$to->format('M j, Y'),
                    'label' => $from->format('M j').' - '.$to->format('M j'),
                    'total' => 0.0,
                ];
            });

            return [$start, 'the last 30 days', '7-day groups', $points];
        }

        $points = collect(range(0, 6))->map(function (int $offset) use ($start, $now): array {
            $date = $start->copy()->addDays($offset);

            return [
                'key' => $date->toDateString(),
                'from' => $date->copy()->startOfDay(),
                'to' => $date->copy()->endOfDay()->min($now->copy()),
                'date' => $date->format('M j, Y'),
                'label' => $date->format('D').' '.$date->format('M j'),
                'total' => 0.0,
            ];
        });

        return [$start, 'the last 7 days', 'Daily totals', $points];
    }

    /** @param Collection<int, array<string, mixed>> $points @return array{maximum: float, ticks: list<float>} */
    private function scale(Collection $points): array
    {
        $maximum = (float) $points->max('total');
        if ($maximum <= 0) {
            return ['maximum' => 0.0, 'ticks' => array_fill(0, 5, 0.0)];
        }

        $rawStep = $maximum / 4;
        $magnitude = 10 ** floor(log10($rawStep));
        $normalized = $rawStep / $magnitude;
        $niceNormalized = $normalized <= 1 ? 1 : ($normalized <= 2 ? 2 : ($normalized <= 2.5 ? 2.5 : ($normalized <= 5 ? 5 : 10)));
        $axisMaximum = $niceNormalized * $magnitude * 4;

        return [
            'maximum' => $axisMaximum,
            'ticks' => collect(range(4, 0))->map(fn (int $step): float => round($axisMaximum * $step / 4, 2))->all(),
        ];
    }

    private function compactCurrency(float $amount): string
    {
        $peso = html_entity_decode('&#8369;');
        if ($amount >= 1_000_000) {
            return $peso.number_format($amount / 1_000_000, 1).'m';
        }
        if ($amount >= 1_000) {
            return $peso.number_format($amount / 1_000, 1).'k';
        }

        return $peso.number_format($amount, 0);
    }
}
