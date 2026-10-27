<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RiderParcelScanController extends Controller
{
    public function resolve(Request $request, LogisticsSchemaReadiness $schema, RiderAssignmentOfferExpiryService $offers): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'tracking' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
            'return_to' => ['nullable', 'string', 'in:dashboard,deliveries,transfers'],
        ]);

        $rider = $request->user()->riderProfile;
        abort_unless($rider && $rider->status === 'ACTIVE', 403);
        $tracking = trim($data['tracking']);

        // Search only jobs already assigned to this rider. A tracking number
        // never grants access to an otherwise unassigned parcel.
        $assignment = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->whereHas('shipment', fn ($query) => $query->where('tracking_number', $tracking))
            ->with('shipment')
            ->latest('id')
            ->first();

        if ($assignment) {
            if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP) {
                if (($offers->isExpired($assignment) && $offers->expireDue($assignment))
                    || $offers->wasExpired($assignment)) {
                    return $this->notAvailable($request, 'This pickup offer expired and was returned to Logistics for reassignment. Refresh Pickups for current work.');
                }
                if (in_array($assignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true)) {
                    $trip = $schema->sellerPickupBatchesReady() ? $assignment->localTripItems()
                        ->with('trip')
                        ->latest('id')
                        ->get()
                        ->first(fn ($item) => $item->trip?->trip_type === RiderLocalTrip::TYPE_PICKUP
                            && (int) $item->trip->rider_profile_id === (int) $rider->id) : null;

                    if ($trip?->trip) {
                        return $this->matchedScan($request, route('rider.pickups.batches.show', $trip->trip), 'Parcel matched an assigned pickup batch. Scan it there after physical handover.');
                    }
                }

                if ($assignment->status === 'COMPLETED' && $assignment->shipment?->current_status === 'PICKED_UP') {
                    $trip = $schema->sellerPickupBatchesReady() ? $assignment->localTripItems()
                        ->with('trip')
                        ->latest('id')
                        ->get()
                        ->first(fn ($item) => $item->trip?->trip_type === RiderLocalTrip::TYPE_PICKUP
                            && (int) $item->trip->rider_profile_id === (int) $rider->id) : null;

                    if ($trip?->trip) {
                        return $this->matchedScan($request, route('rider.pickups.batches.show', $trip->trip), 'Pickup is recorded. Continue with the sorting-center handover workflow.');
                    }
                }

                return $this->notAvailable($request, 'This pickup parcel has already been processed or has no active pickup batch.');
            }

            if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY) {
                if ($assignment->status === 'COMPLETED'
                    || in_array($assignment->shipment?->current_status, ['DELIVERED', 'COMPLETED', 'RETURNED'], true)) {
                    return $this->notAvailable($request, 'Parcel already processed.');
                }
                if (in_array($assignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true)) {
                    return $this->matchedScan($request, route('rider.deliveries.show', $assignment), 'Parcel matched an active delivery assigned to you. Scanning does not mark it delivered.');
                }
            }
        }

        return $this->notAvailable($request, 'Tracking number does not match an active job assigned to your account.');
    }

    private function matchedScan(Request $request, string $url, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'open_url' => $url]);
        }

        return redirect()->to($url)->with('status', $message);
    }

    private function notAvailable(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        $destination = match ($request->input('return_to')) {
            'deliveries' => 'rider.deliveries',
            'transfers' => 'rider.transfers',
            default => 'rider.dashboard',
        };

        return redirect()->route($destination)
            ->withErrors(['tracking' => $message])
            ->withInput(['tracking' => $request->input('tracking')]);
    }
}
