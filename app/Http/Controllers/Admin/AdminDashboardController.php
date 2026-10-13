<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\Dispute;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\Seller\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    private const ADMIN_MANAGED_ACCOUNT_TYPES = [
        User::TYPE_BUYER,
        User::TYPE_SELLER,
        User::TYPE_LOGISTICS,
    ];

    public function index(Request $request): View
    {
        $pendingApplications = RegistrationApplication::query()
            ->whereIn('status', [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW])
            ->whereHas('user', fn ($query) => $query->whereIn('account_type', self::ADMIN_MANAGED_ACCOUNT_TYPES));

        $marketplacePeriod = in_array($request->query('period'), ['7', '30', 'quarter'], true)
            ? $request->query('period')
            : '7';
        [$periodStart, $periodLabel, $groupingLabel, $marketplaceChart] = $this->marketplaceChart($marketplacePeriod);

        $marketplaceOrders = Order::query()
            ->whereNotIn('status', ['CANCELLED'])
            ->whereBetween('placed_at', [$periodStart, now()])
            ->get(['placed_at', 'grand_total']);
        $marketplaceChart = $this->fillMarketplaceChart($marketplaceChart, $marketplaceOrders);
        $marketplaceScale = $this->marketplaceScale($marketplaceChart);
        $marketplaceChart = $marketplaceChart->map(function (array $point) use ($marketplaceScale): array {
            $point['height'] = $marketplaceScale['maximum'] > 0
                ? round($point['total'] / $marketplaceScale['maximum'] * 100, 2)
                : 0;
            $point['compact'] = $this->compactCurrency($point['total']);
            $point['exact'] = '₱'.number_format($point['total'], 2);

            return $point;
        });

        $shipmentStatusCounts = Shipment::query()
            ->selectRaw('current_status, COUNT(*) AS aggregate')
            ->groupBy('current_status')
            ->pluck('aggregate', 'current_status');
        $statusTotal = fn (array $statuses): int => collect($statuses)
            ->sum(fn (string $status): int => (int) ($shipmentStatusCounts[$status] ?? 0));

        $fulfillmentStats = [
            'seller_preparation' => $statusTotal(['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP']),
            'logistics_transit' => $statusTotal(['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY']),
            'delivered' => $statusTotal(['DELIVERED']),
            'completed' => $statusTotal(['COMPLETED']),
            'exceptions' => $statusTotal(['DELIVERY_FAILED', 'RETURNED']),
        ];
        $fulfillmentStats['total'] = array_sum($fulfillmentStats);
        $fulfillmentStats['segments'] = $this->donutSegments($fulfillmentStats);

        $commissionTotals = CommissionTransaction::query()
            ->selectRaw('status, COALESCE(SUM(commission_amount), 0) AS aggregate')
            ->whereIn('status', ['PENDING', 'SETTLED'])
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $complianceConcerns = SellerComplianceCase::query()->whereIn('status', ['OPEN', 'UNDER_REVIEW']);
        $adminComplaints = Dispute::query()->whereIn('status', ['OPEN', 'UNDER_REVIEW']);

        return view('Admin.dashboard.index', [
            'accountStats' => [
                'pending' => (clone $pendingApplications)->count(),
                'active' => User::query()
                    ->where('status', User::STATUS_ACTIVE)
                    ->whereIn('account_type', self::ADMIN_MANAGED_ACCOUNT_TYPES)
                    ->count(),
                'pending_older_than_day' => (clone $pendingApplications)->where('submitted_at', '<', now()->subDay())->count(),
            ],
            'productStats' => [
                'total' => Product::count(),
                'flagged' => (clone $complianceConcerns)->whereNotNull('product_id')->distinct()->count('product_id'),
            ],
            'attentionStats' => [
                'compliance' => (clone $complianceConcerns)->count(),
                'complaints' => (clone $adminComplaints)->count(),
            ],
            'commissionStats' => [
                'pending' => (float) ($commissionTotals['PENDING'] ?? 0),
                'settled' => (float) ($commissionTotals['SETTLED'] ?? 0),
            ],
            'commissionRate' => (float) config('likhae.seller_commission_rate'),
            'marketplacePeriod' => $marketplacePeriod,
            'marketplacePeriodLabel' => $periodLabel,
            'marketplaceGroupingLabel' => $groupingLabel,
            'marketplaceGross' => (float) $marketplaceOrders->sum('grand_total'),
            'marketplaceChart' => $marketplaceChart,
            'marketplaceScale' => $marketplaceScale,
            'marketplaceHasData' => $marketplaceOrders->isNotEmpty(),
            'fulfillmentStats' => $fulfillmentStats,
            'recentPendingApplications' => (clone $pendingApplications)
                ->with('user')
                ->latest('submitted_at')
                ->limit(5)
                ->get(),
        ]);
    }

    private function marketplaceChart(string $period): array
    {
        if ($period === 'quarter') {
            $start = now()->startOfQuarter()->startOfDay();
            $points = collect();
            $cursor = $start->copy();

            while ($cursor->lte(now())) {
                $to = $cursor->copy()->endOfMonth()->min(now());
                $points->push([
                    'key' => $cursor->format('Y-m'),
                    'from' => $cursor->copy(),
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
        $start = now()->subDays($days - 1)->startOfDay();
        if ($days === 30) {
            $points = collect(range(0, 4))->map(function (int $offset) use ($start): array {
                $from = $start->copy()->addDays($offset * 7);
                $to = $from->copy()->addDays(6)->min(now());

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

        $points = collect(range(0, 6))->map(function (int $offset) use ($start): array {
            $date = $start->copy()->addDays($offset);

            return [
                'key' => $date->toDateString(),
                'from' => $date,
                'to' => $date->copy(),
                'date' => $date->format('M j, Y'),
                'label' => $date->format('D').' '.$date->format('M j'),
                'total' => 0.0,
            ];
        });

        return [$start, 'the last 7 days', 'Daily totals', $points];
    }

    private function fillMarketplaceChart(Collection $points, Collection $orders): Collection
    {
        return $points->map(function (array $point) use ($orders): array {
            $point['total'] = (float) $orders
                ->filter(fn (Order $order): bool => $order->placed_at->betweenIncluded($point['from'], $point['to']->endOfDay()))
                ->sum('grand_total');

            return $point;
        });
    }

    private function marketplaceScale(Collection $points): array
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
        if ($amount >= 1_000_000) {
            return '₱'.number_format($amount / 1_000_000, 1).'m';
        }

        if ($amount >= 1_000) {
            return '₱'.number_format($amount / 1_000, 1).'k';
        }

        return '₱'.number_format($amount, 0);
    }

    private function donutSegments(array $stats): string
    {
        $colors = ['var(--photo-maroon)', 'var(--photo-tan)', '#D9B99D', 'var(--photo-success)', 'var(--photo-danger)'];
        $keys = ['seller_preparation', 'logistics_transit', 'delivered', 'completed', 'exceptions'];
        $total = max(1, (int) $stats['total']);
        $offset = 0.0;
        $segments = [];

        foreach ($keys as $index => $key) {
            $next = $offset + ((int) $stats[$key] / $total * 100);
            $segments[] = sprintf('%s %.4F%% %.4F%%', $colors[$index], $offset, $next);
            $offset = $next;
        }

        return (int) $stats['total'] === 0
            ? 'var(--photo-border) 0% 100%'
            : implode(', ', $segments);
    }
}
