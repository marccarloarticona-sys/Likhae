<?php

namespace App\Services\Reports;

use App\Models\Admin\CommissionTransaction;
use App\Models\Seller\SellerOrder;
use App\Support\FinancialRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

final class SellerSalesSummaryService
{
    /** @return array{0: Carbon, 1: Carbon} */
    public function reportingRange(?string $from, ?string $to): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = now($timezone);
        $start = Carbon::parse($from ?: $now->copy()->startOfMonth(), $timezone)->startOfDay();
        $end = Carbon::parse($to ?: $now, $timezone)->endOfDay();

        return [$start, $end];
    }

    /**
     * Sales are recognized from the immutable commission transaction created
     * after every parcel in a seller order has been delivered. Gross is the
     * ledger's original merchandise amount; shipping and Additional Care are
     * excluded. Net is gross less the assessed commission only.
     *
     * @return array{gross: float, commission: float, net: float, orders: int}
     */
    public function summarize(int $sellerProfileId, Carbon $from, Carbon $to, ?int $productId = null): array
    {
        if ($productId !== null) {
            $product = $this->productBreakdown($sellerProfileId, $from, $to, $productId)->get($productId);
            $gross = (float) data_get($product, 'gross', 0);
            $commission = (float) data_get($product, 'commission', 0);

            return [
                'gross' => $gross,
                'commission' => $commission,
                'net' => $gross - $commission,
                'orders' => (int) data_get($product, 'orders', 0),
            ];
        }

        return $this->aggregate($this->transactions($sellerProfileId, $from, $to));
    }

    /**
     * Product values are allocated from the same recognized ledger rows as the
     * seller summary. Historic order item prices provide the product weights;
     * current catalog prices are never consulted.
     *
     * @return Collection<int, array{gross: float, commission: float, net: float, units: int, orders: int}>
     */
    public function productBreakdown(int $sellerProfileId, Carbon $from, Carbon $to, ?int $productId = null): Collection
    {
        $records = $this->transactions($sellerProfileId, $from, $to, $productId)
            ->with('sellerOrder.items')
            ->get(['id', 'seller_order_id', 'commissionable_amount', 'commission_amount', 'calculated_at']);

        $products = [];
        foreach ($records as $transaction) {
            $items = $transaction->sellerOrder?->items ?? collect();
            $groups = $items
                ->filter(fn ($item): bool => $item->product_id !== null && (int) $item->quantity > 0)
                ->groupBy(fn ($item): int => (int) $item->product_id);
            if ($groups->isEmpty()) {
                continue;
            }

            foreach ($groups as $id => $group) {
                $products[$id] ??= ['gross_cents' => 0, 'commission_cents' => 0, 'units' => 0, 'orders' => []];
                $products[$id]['units'] += (int) $group->sum('quantity');
                $products[$id]['orders'][$transaction->seller_order_id] = true;
            }

            // Without a snapshot for every line, there is no defensible way to
            // apportion this order's ledger values among its products.
            $hasCompleteSnapshots = $items->isNotEmpty() && $items->every(fn ($item): bool =>
                $item->product_id !== null
                && (int) $item->quantity > 0
                && $item->original_unit_price !== null
            );
            if (! $hasCompleteSnapshots) {
                continue;
            }

            $weights = $groups->map(fn (Collection $group): int => (int) $group->sum(
                fn ($item): int => FinancialRules::cents($item->original_unit_price) * (int) $item->quantity,
            ))->all();
            if (array_sum($weights) <= 0) {
                continue;
            }

            $grossAllocations = $this->proportionalCents($weights, FinancialRules::cents($transaction->commissionable_amount));
            $commissionAllocations = $this->proportionalCents($grossAllocations, FinancialRules::cents($transaction->commission_amount));
            foreach ($weights as $id => $_weight) {
                $products[$id]['gross_cents'] += $grossAllocations[$id] ?? 0;
                $products[$id]['commission_cents'] += $commissionAllocations[$id] ?? 0;
            }
        }

        return collect($products)->map(fn (array $product): array => [
            'gross' => $product['gross_cents'] / 100,
            'commission' => $product['commission_cents'] / 100,
            'net' => ($product['gross_cents'] - $product['commission_cents']) / 100,
            'units' => $product['units'],
            'orders' => count($product['orders']),
        ]);
    }

    /** @return Builder<\App\Models\Admin\CommissionTransaction> */
    public function recognizedOrderIdsQuery(int $sellerProfileId, Carbon $from, Carbon $to, ?int $productId = null): Builder
    {
        return $this->transactions($sellerProfileId, $from, $to, $productId)->select('seller_order_id');
    }

    /** @return Collection<string, array{gross: float, commission: float}> */
    public function daily(int $sellerProfileId, Carbon $from, Carbon $to, ?int $productId = null): Collection
    {
        return $this->transactions($sellerProfileId, $from, $to, $productId)
            ->get(['id', 'commissionable_amount', 'commission_amount', 'calculated_at'])
            ->groupBy(fn (CommissionTransaction $transaction): string => $transaction->calculated_at
                ->copy()
                ->timezone((string) config('app.timezone', 'UTC'))
                ->toDateString())
            ->map(fn (Collection $transactions): array => [
                'gross' => (float) $transactions->sum('commissionable_amount'),
                'commission' => (float) $transactions->sum('commission_amount'),
            ]);
    }

    private function transactions(int $sellerProfileId, Carbon $from, Carbon $to, ?int $productId = null)
    {
        return CommissionTransaction::query()
            ->whereNotNull('calculated_at')
            ->whereIn('status', ['PENDING', 'SETTLED'])
            ->whereBetween('calculated_at', [$from, $to])
            ->whereHas('sellerOrder', function ($query) use ($sellerProfileId, $productId): void {
                $query->where('seller_profile_id', $sellerProfileId)
                    ->where('status', '!=', 'CANCELLED')
                    ->whereHas('shipments')
                    ->whereDoesntHave('shipments', fn ($shipments) => $shipments
                        ->whereNull('current_status')
                        ->orWhereNotIn('current_status', ['DELIVERED', 'COMPLETED']))
                    ->when($productId, fn ($orders) => $orders->whereHas('items', fn ($items) => $items->where('product_id', $productId)));
            });
    }

    /** @return array{gross: float, commission: float, net: float, orders: int} */
    private function aggregate(Builder $transactions): array
    {
        $totals = (clone $transactions)->selectRaw(
            'COALESCE(SUM(commissionable_amount), 0) as gross, COALESCE(SUM(commission_amount), 0) as commission, COUNT(*) as orders',
        )->first();
        $gross = (float) ($totals->gross ?? 0);
        $commission = (float) ($totals->commission ?? 0);

        return [
            'gross' => $gross,
            'commission' => $commission,
            'net' => $gross - $commission,
            'orders' => (int) ($totals->orders ?? 0),
        ];
    }

    /**
     * Allocate an exact integer-cent total in proportion to historic product
     * weights. Largest remainders receive residual cents, with product ID as
     * a deterministic tie breaker, so allocations always sum to the ledger.
     *
     * @param array<int, int> $weights
     * @return array<int, int>
     */
    private function proportionalCents(array $weights, int $targetCents): array
    {
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0 || $weights === []) {
            return [];
        }

        $sign = $targetCents < 0 ? -1 : 1;
        $target = abs($targetCents);
        $allocations = [];
        $fractions = [];
        foreach ($weights as $id => $weight) {
            $exact = $target * ($weight / $totalWeight);
            $whole = (int) floor($exact + 1.0e-10);
            $allocations[$id] = $whole;
            $fractions[$id] = $exact - $whole;
        }

        $ids = array_keys($allocations);
        usort($ids, fn (int $left, int $right): int =>
            ($fractions[$right] <=> $fractions[$left]) ?: ($left <=> $right),
        );
        $remaining = $target - array_sum($allocations);
        for ($index = 0; $index < $remaining; $index++) {
            $allocations[$ids[$index % count($ids)]]++;
        }

        return array_map(fn (int $amount): int => $amount * $sign, $allocations);
    }
}
