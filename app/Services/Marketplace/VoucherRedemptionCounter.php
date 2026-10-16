<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VoucherRedemptionCounter
{
    /** @return Collection<int, int> Counts keyed by voucher ID. */
    public static function countsFor(Collection $vouchers, ?int $buyerUserId = null): Collection
    {
        $voucherIds = $vouchers->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($voucherIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('seller_orders')
            ->whereIn('seller_orders.voucher_id', $voucherIds)
            ->where('seller_orders.status', '<>', 'CANCELLED');

        if ($buyerUserId !== null) {
            $query->join('orders', 'orders.id', '=', 'seller_orders.order_id')
                ->where('orders.buyer_user_id', $buyerUserId);
        }

        return $query
            ->select('seller_orders.voucher_id')
            ->selectRaw('COUNT(DISTINCT seller_orders.order_id) AS redemption_count')
            ->groupBy('seller_orders.voucher_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->voucher_id => (int) $row->redemption_count]);
    }

    public static function count(int $voucherId, ?int $buyerUserId = null): int
    {
        return (int) self::countsFor(collect([(object) ['id' => $voucherId]]), $buyerUserId)->get($voucherId, 0);
    }
}
