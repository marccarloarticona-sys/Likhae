<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Seller\SellerPickupRequest;
use Illuminate\Support\Facades\DB;

final class RiderAssignmentOfferExpiryService
{
    public function __construct(
        private readonly ShipmentWorkflowService $workflow,
        private readonly LocalRiderTripService $trips,
        private readonly LogisticsSchemaReadiness $schema,
    ) {}

    public function cutoff(): \Illuminate\Support\Carbon
    {
        return now()->subMinutes(max(1, (int) config('rider.assignment_offer_minutes', 15)));
    }

    public function isExpired(RiderAssignment $assignment): bool
    {
        return $assignment->assignment_type === RiderAssignment::TYPE_PICKUP
            && $assignment->status === 'ASSIGNED'
            && $assignment->assigned_at !== null
            && $assignment->assigned_at->lte($this->cutoff());
    }

    public function wasExpired(RiderAssignment $assignment): bool
    {
        return $assignment->assignment_type === RiderAssignment::TYPE_PICKUP
            && $assignment->status === 'CANCELLED'
            && str_starts_with((string) $assignment->rejection_reason, 'Offer expired');
    }

    public function expireDuePickupOffers(?int $riderProfileId = null, ?int $logisticsCenterId = null): int
    {
        $expired = 0;

        RiderAssignment::query()
            ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
            ->where('status', 'ASSIGNED')
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', $this->cutoff())
            ->when($riderProfileId !== null, fn ($query) => $query->where('rider_profile_id', $riderProfileId))
            ->when($logisticsCenterId !== null, fn ($query) => $query->whereHas('riderProfile', fn ($rider) => $rider->where('logistics_center_id', $logisticsCenterId)))
            ->orderBy('id')
            ->chunkById(100, function ($assignments) use (&$expired): void {
                foreach ($assignments as $candidate) {
                    $expired += $this->expireDue($candidate) ? 1 : 0;
                }
            });

        return $expired;
    }

    public function expireDue(RiderAssignment $candidate): bool
    {
        if ($candidate->assignment_type !== RiderAssignment::TYPE_PICKUP) {
            return false;
        }

        $tripId = $this->schema->sellerPickupBatchesReady()
            ? RiderLocalTripItem::query()
                ->where('rider_assignment_id', $candidate->id)
                ->whereHas('trip', fn ($query) => $query->where('trip_type', RiderLocalTrip::TYPE_PICKUP))
                ->latest('id')
                ->value('rider_local_trip_id')
            : null;

        return $tripId
            ? $this->expirePickupBatch($candidate, (int) $tripId)
            : $this->expireStandalonePickup($candidate);
    }

    private function expirePickupBatch(RiderAssignment $candidate, int $tripId): bool
    {
        $cutoff = $this->cutoff();

        return DB::transaction(function () use ($candidate, $tripId, $cutoff): bool {
            $trip = RiderLocalTrip::query()->whereKey($tripId)->lockForUpdate()->first();
            if (! $trip || $trip->trip_type !== RiderLocalTrip::TYPE_PICKUP
                || ! in_array($trip->status, [RiderLocalTrip::LOADING, RiderLocalTrip::READY], true)) {
                return false;
            }

            $items = RiderLocalTripItem::query()
                ->where('rider_local_trip_id', $trip->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $assignmentIds = $items->pluck('rider_assignment_id')->filter()->unique()->values();
            if ($assignmentIds->isEmpty() || ! $assignmentIds->contains((int) $candidate->id)) {
                return false;
            }

            $shipments = Shipment::query()
                ->whereIn('id', RiderAssignment::query()->whereIn('id', $assignmentIds)->select('shipment_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $assignments = RiderAssignment::query()
                ->whereIn('id', $assignmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $expiredCandidate = $assignments->firstWhere('id', $candidate->id);

            if (! $expiredCandidate
                || $expiredCandidate->assignment_type !== RiderAssignment::TYPE_PICKUP
                || $expiredCandidate->status !== 'ASSIGNED'
                || ! $expiredCandidate->assigned_at
                || $expiredCandidate->assigned_at->gt($cutoff)
                || $assignments->count() !== $assignmentIds->count()
                || $assignments->contains(fn (RiderAssignment $assignment): bool =>
                    $assignment->assignment_type !== RiderAssignment::TYPE_PICKUP
                    || $assignment->status !== 'ASSIGNED')) {
                return false;
            }

            $now = now();
            foreach ($assignments as $assignment) {
                $assignment->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => $now,
                    'rejection_reason' => 'Offer expired before rider acceptance.',
                ]);

                $shipment = $shipments->get((int) $assignment->shipment_id);
                if ($shipment && $shipment->current_status === 'PICKUP_ASSIGNED') {
                    $shipment->update(['current_status' => 'READY_FOR_PICKUP']);
                    $this->workflow->recordEvent(
                        $shipment,
                        'READY_FOR_PICKUP',
                        null,
                        'Pickup offer expired; parcel returned to the Logistics pickup queue.',
                        $assignment,
                    );
                }
            }

            RiderLocalTripItem::query()
                ->whereIn('rider_assignment_id', $assignmentIds)
                ->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED])
                ->update([
                    'status' => RiderLocalTripItem::CANCELLED,
                    'completed_at' => $now,
                    'failure_reason' => 'Pickup offer expired before rider acceptance.',
                ]);

            $trip->update([
                'status' => RiderLocalTrip::CANCELLED,
                'cancelled_at' => $now,
                'exception_notes' => 'Pickup offer expired before rider acceptance; returned to the Logistics pickup queue.',
            ]);

            if ($this->schema->sellerPickupRequestsReady()) {
                SellerPickupRequest::query()
                    ->where('rider_local_trip_id', $trip->id)
                    ->where('status', 'ASSIGNED')
                    ->update([
                        'rider_local_trip_id' => null,
                        'status' => 'REQUESTED',
                    ]);
            }

            return true;
        });
    }

    private function expireStandalonePickup(RiderAssignment $candidate): bool
    {
        $cutoff = $this->cutoff();

        return DB::transaction(function () use ($candidate, $cutoff): bool {
            $shipmentId = RiderAssignment::query()->whereKey($candidate->id)->value('shipment_id');
            $shipment = $shipmentId ? Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first() : null;
            if (! $shipment) {
                return false;
            }

            $assignment = RiderAssignment::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $assignment
                || $assignment->assignment_type !== RiderAssignment::TYPE_PICKUP
                || $assignment->status !== 'ASSIGNED'
                || ! $assignment->assigned_at
                || $assignment->assigned_at->gt($cutoff)
                || (int) $assignment->shipment_id !== (int) $shipment->id) {
                return false;
            }

            $assignment->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'rejection_reason' => 'Offer expired before rider acceptance.',
            ]);

            if ($shipment->current_status === 'PICKUP_ASSIGNED') {
                $shipment->update(['current_status' => 'READY_FOR_PICKUP']);
                $this->workflow->recordEvent(
                    $shipment,
                    'READY_FOR_PICKUP',
                    null,
                    'Pickup offer expired; parcel returned to the Logistics pickup queue.',
                    $assignment,
                );
            }

            $this->trips->syncAssignment($assignment->refresh());

            return true;
        });
    }
}
