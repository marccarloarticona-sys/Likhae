<?php

namespace App\Services\Fulfillment;

use App\Models\Seller\SellerPickupCenterOffer;
use App\Models\Seller\SellerPickupRequest;
use Illuminate\Support\Collection;

final class SellerPickupDestinationAcceptanceService
{
    public function __construct(private readonly LogisticsSchemaReadiness $schema) {}

    public function allRequiredHubsAccepted(SellerPickupRequest $request): bool
    {
        if (! $request->seller_selected_fallback) {
            return true;
        }
        if (! $this->schema->sellerSelectedFallbackHubsReady()
            || ! $this->schema->sellerPickupLegOffersReady()) {
            return false;
        }

        $request->loadMissing([
            'memberships.shipment.sellerOrder.order.address',
            'centerOffers',
        ]);
        $groups = $this->destinationGroups($request);
        if ($groups->isEmpty()) {
            return false;
        }

        $acceptedOffers = $request->centerOffers->filter(fn (SellerPickupCenterOffer $offer): bool =>
            $offer->leg_type === SellerPickupCenterOffer::LEG_DELIVERY
            && $offer->status === SellerPickupCenterOffer::STATUS_ACCEPTED);

        return $groups->every(function (array $group) use ($acceptedOffers): bool {
            return $acceptedOffers->contains(function (SellerPickupCenterOffer $offer) use ($group): bool {
                $shipmentIds = collect((array) data_get($offer->route_snapshot, 'delivery_shipment_ids', []))
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();

                return (int) $offer->logistics_center_id === $group['center_id']
                    && (int) data_get($offer->route_snapshot, 'delivery_center_id', 0) === $group['center_id']
                    && (string) data_get($offer->route_snapshot, 'delivery_group_key', '') === $group['key']
                    && $shipmentIds === $group['shipment_ids'];
            });
        });
    }

    /** @return Collection<int, array{key:string,center_id:int,shipment_ids:list<int>}> */
    private function destinationGroups(SellerPickupRequest $request): Collection
    {
        return $request->memberships
            ->filter(fn ($membership): bool => (int) $membership->preferred_destination_logistics_center_id > 0)
            ->groupBy(function ($membership): string {
                $address = $membership->shipment?->sellerOrder?->order?->address;

                return implode('|', [
                    trim((string) $address?->province_code),
                    trim((string) $address?->municipality_code),
                    trim((string) $address?->barangay_code),
                    (string) ((int) $membership->preferred_destination_logistics_center_id),
                ]);
            })
            ->map(function (Collection $memberships): ?array {
                $first = $memberships->first();
                $address = $first?->shipment?->sellerOrder?->order?->address;
                if (! $first || ! $address) {
                    return null;
                }

                $shipmentIds = $memberships->pluck('shipment_id')
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();
                $psgc = implode('|', [
                    trim((string) $address->province_code),
                    trim((string) $address->municipality_code),
                    trim((string) $address->barangay_code),
                ]);

                return [
                    'key' => hash('sha256', $psgc.'|'.implode(',', $shipmentIds)),
                    'center_id' => (int) $first->preferred_destination_logistics_center_id,
                    'shipment_ids' => $shipmentIds,
                ];
            })
            ->filter()
            ->values();
    }
}
