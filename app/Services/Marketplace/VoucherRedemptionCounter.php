<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->whereIn('seller_orders.voucher_id', $voucherIds)
            ->where('seller_orders.status', '<>', 'CANCELLED');
        $rows = $query->get([
            'seller_orders.voucher_id',
            'seller_orders.order_id',
            'orders.buyer_user_id',
        ]);
        foreach (['platform_voucher_id', 'shipping_voucher_id'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                continue;
            }
            $rows = $rows->merge(DB::table('orders')
                ->whereIn($column, $voucherIds)
                ->where('status', '<>', 'CANCELLED')
                ->get(['id as order_id', 'buyer_user_id', $column.' as voucher_id']));
        }
        $rows = $rows
            ->filter(fn ($row): bool => $buyerUserId === null || (int) $row->buyer_user_id === $buyerUserId)
            ->unique(fn ($row): string => $row->voucher_id.':'.$row->order_id);

        return $rows->groupBy(fn ($row): int => (int) $row->voucher_id)
            ->map(fn (Collection $uses): int => $uses->pluck('order_id')->unique()->count());
    }

    public static function count(int $voucherId, ?int $buyerUserId = null): int
    {
        return (int) self::countsFor(collect([(object) ['id' => $voucherId]]), $buyerUserId)->get($voucherId, 0);
    }

    /**
     * Read redemption rows as a current read after the caller has locked the
     * voucher row. Ordinary aggregate reads can retain an older repeatable-read
     * snapshot while waiting for that lock and allow an extra use.
     *
     * @return array{redemptions: int, buyer_redemptions: int}
     */
    public static function lockedUsageFor(int $voucherId, ?int $buyerUserId = null): array
    {
        $rows = DB::table('seller_orders')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('seller_orders.voucher_id', $voucherId)
            ->where('seller_orders.status', '<>', 'CANCELLED')
            ->orderBy('seller_orders.id')
            ->lockForUpdate()
            ->get(['seller_orders.order_id', 'seller_orders.voucher_id', 'orders.buyer_user_id']);
        foreach (['platform_voucher_id', 'shipping_voucher_id'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                continue;
            }
            $rows = $rows->merge(DB::table('orders')
                ->where($column, $voucherId)
                ->where('status', '<>', 'CANCELLED')
                ->orderBy('id')
                ->lockForUpdate()
                ->select(['id as order_id', 'buyer_user_id'])
                ->selectRaw('? as voucher_id', [$voucherId])
                ->get());
        }
        $rows = $rows->unique(fn ($row): string => $row->voucher_id.':'.$row->order_id);

        return [
            'redemptions' => $rows->pluck('order_id')->unique()->count(),
            'buyer_redemptions' => $buyerUserId === null
                ? 0
                : $rows->where('buyer_user_id', $buyerUserId)->pluck('order_id')->unique()->count(),
        ];
    }
}
