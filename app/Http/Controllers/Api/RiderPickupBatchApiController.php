<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderPickupBatchApiController extends Controller
{
    public function forAssignment(
        Request $request,
        int $assignment,
        LogisticsSchemaReadiness $schema,
        RiderAssignmentOfferExpiryService $offers,
        LocalRiderTripService $localTrips,
    ): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAccountType(User::TYPE_RIDER), 403);
        $rider = $user->riderProfile;
        abort_unless(
            $rider instanceof RiderProfile
                && $rider->status === 'ACTIVE'
                && RiderVehicleType::isLocalBarangay($rider->vehicle_type),
            403,
            'Active local rider access is required.',
        );
        abort_unless($schema->sellerPickupBatchesReady(), 503, 'Pickup batches are unavailable.');

        // Resolve only this rider's seller batch. Never perform a parcel action
        // through the delivery endpoint or infer a batch from a tracking code.
        $batch = RiderLocalTrip::query()
            ->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->whereHas('items.riderAssignment', fn ($query) => $query
                ->whereKey($assignment)
                ->where('rider_profile_id', $rider->id)
                ->where('assignment_type', 'PICKUP'))
            ->firstOrFail();

        return $this->show($request, (int) $batch->id, $schema, $offers, $localTrips);
    }

    public function show(
        Request $request,
        int $trip,
        LogisticsSchemaReadiness $schema,
        RiderAssignmentOfferExpiryService $offers,
        LocalRiderTripService $localTrips,
    ): JsonResponse {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && $user->isAccountType(User::TYPE_RIDER)
                && $user->status === User::STATUS_ACTIVE,
            403,
            'Active rider account access is required.',
        );

        $rider = $user->riderProfile;
        abort_unless(
            $rider instanceof RiderProfile
                && $rider->status === 'ACTIVE'
                && RiderVehicleType::isLocalBarangay($rider->vehicle_type),
            403,
            'Local Rider access is required.',
        );

        if (! $schema->sellerPickupBatchesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Seller pickup batch workflow is unavailable until its additive schema migration is installed.',
            ], 503);
        }

        $offers->expireDuePickupOffers((int) $rider->id);

        $batch = RiderLocalTrip::query()
            ->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->with([
                'seller.businessAddress',
                'logisticsCenter.address',
                'items.riderAssignment.shipment.sellerOrder.items',
            ])
            ->findOrFail($trip);

        if ($batch->status === RiderLocalTrip::EN_ROUTE_TO_HUB) {
            foreach ($batch->items as $item) {
                $assignment = $item->riderAssignment;
                $shipment = $assignment?->shipment;
                if ($assignment?->status === 'COMPLETED' && $shipment) {
                    // Reconcile stale batch state after the hub has received a parcel.
                    $localTrips->syncPickupIntake($shipment);
                }
            }

            $batch->refresh()->load([
                'seller.businessAddress',
                'logisticsCenter.address',
                'items.riderAssignment.shipment.sellerOrder.items',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->batchPayload($batch),
        ]);
    }

    private function batchPayload(RiderLocalTrip $trip): array
    {
        $items = $trip->items->map(function (RiderLocalTripItem $item): array {
            $assignment = $item->riderAssignment;
            $shipment = $assignment?->shipment;

            return [
                'assignment_id' => $assignment?->id,
                'tracking' => $shipment?->tracking_number,
                'seller_order_number' => $shipment?->sellerOrder?->seller_order_number,
                'products' => $shipment?->sellerOrder?->items?->pluck('product_name')->filter()->values() ?? collect(),
                'assignment_status' => $assignment?->status,
                'shipment_status' => $shipment?->current_status,
                'not_collected_reason' => $assignment?->rejection_reason,
            ];
        })->values();

        return [
            'id' => $trip->id,
            'batch_number' => 'P-'.$trip->id,
            'status' => $trip->status,
            'seller' => $trip->seller?->business_name,
            'pickup_address' => $trip->seller?->businessAddress?->formatted(),
            'logistics_center' => $trip->logisticsCenter?->business_name,
            'destination_address' => $trip->logisticsCenter?->address?->formatted(),
            'parcel_count' => $items->count(),
            'approved_capacity' => $trip->approved_parcel_capacity,
            'collected_count' => $items->where('assignment_status', 'COMPLETED')->count(),
            'items' => $items,
        ];
    }
}
