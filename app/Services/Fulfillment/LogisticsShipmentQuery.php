<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentTransferLeg;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One row per shipment, including item-level parcels. COMPLETED stays delivered;
 * RETURNED stays failed. PLACED, CONFIRMED and PREPARING are pre-logistics
 * stages; a cancelled seller order is excluded from operational counts.
 * A manifest/leg grants visibility but is never another parcel row.
 */
final class LogisticsShipmentQuery
{
    public const CATEGORIES = [
        'pending' => ['READY_FOR_PICKUP'],
        'in_transit' => ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'],
        'delivered' => ['DELIVERED', 'COMPLETED'],
        'failed' => ['DELIVERY_FAILED', 'RETURNED', 'LOST', 'DAMAGED'],
    ];

    public function filters(Request $request, bool $withStatus = false): array
    {
        // The center always comes from the authenticated relationship.
        abort_if($request->hasAny(['company_id', 'hub_id', 'logistics_center_id']), 403);

        $rules = [
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ];
        if ($withStatus) {
            $rules['status'] = ['nullable', 'string', Rule::in(array_merge(
                [''], array_keys(self::CATEGORIES), Shipment::STATUSES,
                array_map('strtolower', Shipment::STATUSES),
            ))];
        }

        $filters = $request->validate($rules);
        if ($withStatus && isset($filters['status']) && ! isset(self::CATEGORIES[$filters['status']])) {
            $filters['status'] = strtoupper($filters['status']);
        }

        return $filters;
    }

    public function visibleTo(LogisticsCenter $center): Builder
    {
        return Shipment::query()->where(function (Builder $query) use ($center): void {
            $query->where('logistics_center_id', $center->id)
                ->orWhere(fn (Builder $unassigned) => $unassigned
                    ->whereNull('logistics_center_id')
                    ->whereHas('serviceArea', fn (Builder $area) => $area
                        ->where('logistics_center_id', $center->id)));

            if (app(LogisticsSchemaReadiness::class)->automaticRoutingReady()) {
                // Transfer legs grant both hubs an operations history. A cancelled
                // leg alone does not grant access; manifests are never counted as parcels.
                $query->orWhereHas('transferLegs', fn (Builder $leg) => $leg
                    ->where('status', '!=', ShipmentTransferLeg::CANCELLED)
                    ->where(fn (Builder $hubs) => $hubs
                        ->where('origin_logistics_center_id', $center->id)
                        ->orWhere('destination_logistics_center_id', $center->id)));
            }
        });
    }

    public function operationalFor(LogisticsCenter $center): Builder
    {
        return $this->visibleTo($center)
            ->whereHas('sellerOrder', fn (Builder $order) => $order->where('status', '!=', 'CANCELLED'));
    }

    public function dated(Builder $query, array $filters): Builder
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        if (! empty($filters['from_date'])) {
            $query->where('shipments.created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['from_date'], $timezone)->startOfDay());
        }
        if (! empty($filters['to_date'])) {
            $query->where('shipments.created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['to_date'], $timezone)->addDay()->startOfDay());
        }

        return $query;
    }

    public function category(Builder $query, string $category): Builder
    {
        return $query->whereIn('current_status', self::CATEGORIES[$category]);
    }

    public function counts(Builder $query): array
    {
        $byStatus = (clone $query)->selectRaw('current_status, COUNT(*) AS total')
            ->groupBy('current_status')->pluck('total', 'current_status');

        return collect(self::CATEGORIES)->mapWithKeys(fn (array $statuses, string $category) => [
            $category => collect($statuses)->sum(fn (string $status): int => (int) ($byStatus[$status] ?? 0)),
        ])->all();
    }
}
