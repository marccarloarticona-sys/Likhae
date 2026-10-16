<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\TransferManifest;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RiderParcelScanController extends Controller
{
    public function resolve(Request $request, LogisticsSchemaReadiness $schema): RedirectResponse
    {
        $data = $request->validate([
            'tracking' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
            'return_to' => ['nullable', 'string', 'in:dashboard,deliveries,transfers'],
        ]);

        $rider = $request->user()->riderProfile;
        abort_unless($rider && $rider->status === 'ACTIVE', 403);
        $tracking = trim($data['tracking']);

        $manifest = $schema->transferReady()
            ? TransferManifest::query()
                ->where('assigned_driver_rider_profile_id', $rider->id)
                ->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])
                ->whereHas('items.transferLeg.shipment', fn ($query) => $query->where('tracking_number', $tracking))
                ->latest('id')
                ->first()
            : null;
        if ($manifest) {
            return redirect()->route('rider.transfers.show', $manifest)
                ->with('status', 'Parcel matched your assigned inter-hub manifest. Continue with the required custody scan.');
        }

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
                if (in_array($assignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true)) {
                    $trip = $schema->sellerPickupBatchesReady() ? $assignment->localTripItems()
                        ->with('trip')
                        ->latest('id')
                        ->get()
                        ->first(fn ($item) => $item->trip?->trip_type === RiderLocalTrip::TYPE_PICKUP
                            && (int) $item->trip->rider_profile_id === (int) $rider->id) : null;

                    if ($trip?->trip) {
                        return redirect()->route('rider.pickups.batches.show', $trip->trip)
                            ->with('status', 'Parcel matched an assigned pickup batch. Scan it there after physical handover.');
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
                        return redirect()->route('rider.pickups.batches.show', $trip->trip)
                            ->with('status', 'Pickup is recorded. Continue with the sorting-center handover workflow.');
                    }
                }

                return $this->notAvailable($request, 'This pickup parcel has already been processed or has no active pickup batch.');
            }

            if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY) {
                if ($assignment->status === 'COMPLETED'
                    || in_array($assignment->shipment?->current_status, ['DELIVERED', 'COMPLETED', 'RETURNED'], true)) {
                    return $this->notAvailable($request, 'Parcel already processed.');
                }
                $offerMinutes = max(1, (int) config('rider.assignment_offer_minutes', 15));
                if ($assignment->status === 'ASSIGNED'
                    && $assignment->assigned_at
                    && $assignment->assigned_at->lte(now()->subMinutes($offerMinutes))) {
                    return $this->notAvailable($request, 'This delivery offer has expired. Refresh the dispatch queue for a new assignment.');
                }
                if (in_array($assignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true)) {
                    return redirect()->route('rider.deliveries.show', $assignment)
                        ->with('status', 'Parcel matched an active delivery assigned to you. Scanning does not mark it delivered.');
                }
            }
        }

        return $this->notAvailable($request, 'Tracking number does not match an active job assigned to your account.');
    }

    public function verifyDelivery(Request $request, RiderAssignment $assignment): RedirectResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider && $assignment->assignment_type === RiderAssignment::TYPE_DELIVERY
            && (int) $assignment->rider_profile_id === (int) $rider->id, 403);

        if ($assignment->status === 'COMPLETED') {
            return redirect()->route('rider.deliveries.show', $assignment)->withErrors([
                'tracking' => 'Parcel already processed.',
            ]);
        }
        abort_unless($assignment->status === 'IN_PROGRESS', 409, 'Start this assigned delivery before verifying its waybill.');

        $data = $request->validate([
            'tracking' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        $shipment = $assignment->shipment;
        if (! $shipment || ! hash_equals((string) $shipment->tracking_number, trim($data['tracking']))) {
            throw ValidationException::withMessages([
                'tracking' => 'Tracking number does not match this job.',
            ]);
        }
        if (! in_array($shipment->current_status, ['OUT_FOR_DELIVERY', 'DELIVERY_FAILED'], true)) {
            abort(409, 'This parcel is not in a state that can be verified for delivery.');
        }

        return redirect()->route('rider.deliveries.show', $assignment)
            ->with('verified_tracking', trim($data['tracking']))
            ->with('verified_scan_method', strtoupper((string) ($data['scan_method'] ?? 'MANUAL')))
            ->with('status', 'Waybill verified for this assigned delivery. Complete the proof and COD steps to finish.');
    }

    private function notAvailable(Request $request, string $message): RedirectResponse
    {
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
