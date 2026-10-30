<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderPickupBatchApiController extends Controller
{
    public function show(
        Request $request,
        int $trip,
        LogisticsSchemaReadiness $schema,
        RiderAssignmentOfferExpiryService $offers,
    ): JsonResponse {
        if (! $schema->sellerPickupBatchesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Seller pickup batch workflow is not enabled until its additive schema migration is installed.',
            ], 503);
        }

        $user = $request->user();
        abort_unless($user instanceof User && $user->isAccountType(User::TYPE_RIDER), 403, 'Rider access is required.');

        $rider = $user->riderProfile;
        abort_unless(
            $rider instanceof RiderProfile
                && $rider->status === 'ACTIVE'
                && RiderVehicleType::isLocalBarangay($rider->vehicle_type),
            403,
            'Active local rider access is required.',
        );

        $offers->expireDuePickupOffers((int) $rider->id);

        $pickup = RiderLocalTrip::query()
            ->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->with([
                'seller.businessAddress',
                'logisticsCenter.address',
                'items.riderAssignment.shipment.sellerOrder.items',
            ])
            ->findOrFail($trip);

        $items = $pickup->items->map(function (RiderLocalTripItem $item): array {
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

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $pickup->id,
                'batch_number' => 'P-'.$pickup->id,
                'status' => $pickup->status,
                'seller' => $pickup->seller?->business_name,
                'pickup_address' => $pickup->seller?->businessAddress?->formatted(),
                'logistics_center' => $pickup->logisticsCenter?->business_name,
                'destination_address' => $pickup->logisticsCenter?->address?->formatted(),
                'parcel_count' => $items->count(),
                'approved_capacity' => $pickup->approved_parcel_capacity,
                'collected_count' => $items->where('assignment_status', 'COMPLETED')->count(),
                'items' => $items,
            ],
        ]);
    }
}
