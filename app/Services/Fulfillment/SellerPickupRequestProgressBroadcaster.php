<?php

namespace App\Services\Fulfillment;

use App\Events\SellerPickupRequestProgressUpdated;
use App\Models\Seller\SellerPickupRequestShipment;

final class SellerPickupRequestProgressBroadcaster
{
    public function broadcast(int $pickupRequestId): void
    {
        $shipmentIds = SellerPickupRequestShipment::query()
            ->where('seller_pickup_request_id', $pickupRequestId)
            ->pluck('shipment_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($shipmentIds === []) {
            return;
        }

        try {
            SellerPickupRequestProgressUpdated::dispatch($pickupRequestId, $shipmentIds);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
