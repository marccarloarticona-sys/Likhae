<?php

namespace App\Services\Buyer;

use App\Models\Admin\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BuyerRiskMonitor
{
    public const SIGNAL_EVENT = 'BUYER_RISK_SIGNAL';

    public function recordBuyerRiskSignals(User $buyer): void
    {
        if (! $buyer->isAccountType(User::TYPE_BUYER)
            || ! Schema::hasTable('orders')
            || ! Schema::hasTable('audit_logs')) {
            return;
        }

        $signals = [];
        $cancellationWindowDays = max(1, (int) config('buyer_risk.cancellation_window_days', 30));
        $cancellationWindowStart = now()->subDays($cancellationWindowDays);
        $ordersInWindow = DB::table('orders')
            ->where('buyer_user_id', $buyer->id)
            ->whereNotNull('placed_at')
            ->where('placed_at', '>=', $cancellationWindowStart)
            ->count();

        if (Schema::hasTable('shipment_events') && Schema::hasTable('shipments') && Schema::hasTable('seller_orders')) {
            $buyerCancelledOrders = DB::table('shipment_events')
                ->join('shipments', 'shipments.id', '=', 'shipment_events.shipment_id')
                ->join('seller_orders', 'seller_orders.id', '=', 'shipments.seller_order_id')
                ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
                ->where('orders.buyer_user_id', $buyer->id)
                ->whereNotNull('orders.placed_at')
                ->where('orders.placed_at', '>=', $cancellationWindowStart)
                ->where('shipment_events.occurred_at', '>=', $cancellationWindowStart)
                ->where('shipment_events.notes', 'like', 'BUYER_ORDER_CANCELLED:%')
                ->distinct()
                ->count('orders.id');
            $minimumOrders = max(1, (int) config('buyer_risk.minimum_orders_for_cancellation_signal', 4));
            $minimumCancellations = max(1, (int) config('buyer_risk.minimum_buyer_cancellations', 3));
            $minimumCancellationRate = min(1, max(0, (float) config('buyer_risk.minimum_cancellation_rate', 0.60)));
            $cancellationRate = $ordersInWindow > 0 ? $buyerCancelledOrders / $ordersInWindow : 0;

            if ($ordersInWindow >= $minimumOrders
                && $buyerCancelledOrders >= $minimumCancellations
                && $cancellationRate >= $minimumCancellationRate) {
                $signals[] = [
                    'signal_type' => 'REPEATED_BUYER_CANCELLATIONS',
                    'window_days' => $cancellationWindowDays,
                    'orders_in_window' => $ordersInWindow,
                    'buyer_cancelled_orders' => $buyerCancelledOrders,
                    'cancellation_rate_percent' => (int) round($cancellationRate * 100),
                    'threshold_percent' => (int) round($minimumCancellationRate * 100),
                ];
            }
        }

        $rapidOrderWindowMinutes = max(1, (int) config('buyer_risk.rapid_order_window_minutes', 10));
        $minimumRapidOrders = max(2, (int) config('buyer_risk.minimum_rapid_orders', 5));
        $rapidOrders = DB::table('orders')
            ->where('buyer_user_id', $buyer->id)
            ->whereNotNull('placed_at')
            ->where('placed_at', '>=', now()->subMinutes($rapidOrderWindowMinutes))
            ->count();

        if ($rapidOrders >= $minimumRapidOrders) {
            $signals[] = [
                'signal_type' => 'RAPID_ORDERING',
                'window_minutes' => $rapidOrderWindowMinutes,
                'orders_in_window' => $rapidOrders,
                'threshold_orders' => $minimumRapidOrders,
            ];
        }

        $deduplicationStart = now()->subHours(max(1, (int) config('buyer_risk.signal_deduplication_hours', 24)));
        foreach ($signals as $signal) {
            $alreadyRecorded = AuditLog::query()
                ->where('event', self::SIGNAL_EVENT)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $buyer->id)
                ->where('created_at', '>=', $deduplicationStart)
                ->get(['new_values'])
                ->contains(fn (AuditLog $log): bool => data_get($log->new_values, 'signal_type') === $signal['signal_type']);

            if ($alreadyRecorded) {
                continue;
            }

            AuditLog::query()->create([
                'actor_user_id' => null,
                'event' => self::SIGNAL_EVENT,
                'auditable_type' => User::class,
                'auditable_id' => $buyer->id,
                'new_values' => array_merge($signal, [
                    'status' => 'OPEN',
                    'reference' => 'RISK-'.$buyer->id.'-'.Str::upper((string) Str::ulid()),
                    'review_guidance' => 'Automated signal for human review; this is not a finding of misconduct.',
                ]),
                'created_at' => now(),
            ]);
        }
    }

    /** @return Collection<int, int> Buyer user IDs with open or in-review safety signals. */
    public function openBuyerIds(): Collection
    {
        if (! Schema::hasTable('audit_logs')) {
            return collect();
        }

        return AuditLog::query()
            ->where('event', self::SIGNAL_EVENT)
            ->where('auditable_type', User::class)
            ->whereIn('new_values->status', ['OPEN', 'UNDER_REVIEW'])
            ->distinct()
            ->pluck('auditable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}
