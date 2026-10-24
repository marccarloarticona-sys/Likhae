<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShipmentReturnWorkflowService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly ShipmentWorkflowService $shipments,
    ) {}

    public function available(): bool
    {
        return $this->schema->shipmentReturnsReady();
    }

    public function hasOpenWorkflow(Shipment $shipment): bool
    {
        return $this->available()
            && ShipmentReturnWorkflow::query()
                ->where('shipment_id', $shipment->id)
                ->whereIn('status', ShipmentReturnWorkflow::OPEN_STATUSES)
                ->exists();
    }

    public function initiateFromDeliveryFailure(
        Shipment $shipment,
        RiderAssignment $assignment,
        User $actor,
        string $reason,
        string $disposition = ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN,
    ): ShipmentReturnWorkflow
    {
        $this->requireAvailable();
        $disposition = in_array($disposition, [
            ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN,
            ShipmentReturnWorkflow::DISPOSITION_REDELIVERY,
        ], true) ? $disposition : ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN;
        if ($disposition === ShipmentReturnWorkflow::DISPOSITION_REDELIVERY && ! $this->schema->shipmentReturnEnhancementsReady()) {
            throw new RuntimeException('Route return custody is unavailable until its additive migration is installed.');
        }

        $centerId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
        if (! $centerId) {
            throw new RuntimeException('This shipment has no assigned return sorting center.');
        }

        return DB::transaction(function () use ($shipment, $assignment, $actor, $reason, $centerId, $disposition): ShipmentReturnWorkflow {
            $workflow = ShipmentReturnWorkflow::query()
                ->where('shipment_id', $shipment->id)
                ->lockForUpdate()
                ->first();
            if ($workflow && in_array($workflow->status, ShipmentReturnWorkflow::OPEN_STATUSES, true)) {
                return $workflow;
            }
            if ($workflow && $workflow->status !== ShipmentReturnWorkflow::CENTER_RECEIVED_FOR_REDELIVERY) {
                throw new RuntimeException('A completed return workflow already exists for this shipment.');
            }

            $values = [
                'logistics_center_id' => $centerId,
                'original_delivery_assignment_id' => $assignment->id,
                'status' => ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF,
                'reason' => trim($reason),
                'initiated_by_user_id' => $actor->id,
                'initiated_at' => now(),
                'center_handoff_by_user_id' => null,
                'center_handoff_at' => null,
                'center_received_by_user_id' => null,
                'center_received_at' => null,
                'return_rider_profile_id' => null,
                'return_rider_assigned_by_user_id' => null,
                'return_rider_assigned_at' => null,
                'return_rider_accepted_at' => null,
                'return_rider_picked_up_by_user_id' => null,
                'return_rider_picked_up_at' => null,
                'seller_handoff_by_user_id' => null,
                'seller_handoff_at' => null,
                'seller_received_by_user_id' => null,
                'seller_received_at' => null,
                'seller_confirmation_notes' => null,
            ];
            if ($this->schema->shipmentReturnEnhancementsReady()) {
                $values['return_disposition'] = $disposition;
                $values['pending_return_rider_profile_id'] = null;
                $values['handoff_authorized_by_user_id'] = null;
                $values['handoff_authorized_at'] = null;
                $values['handoff_reason'] = null;
                $values['handoff_from_confirmed_at'] = null;
                $values['handoff_to_confirmed_at'] = null;
                $values['handoff_completed_at'] = null;
                $values['return_exception_reason'] = null;
                $values['return_exception_recorded_by_user_id'] = null;
                $values['return_exception_recorded_at'] = null;
            }

            if ($workflow) {
                $workflow->update($values);
                return $workflow->refresh();
            }

            return ShipmentReturnWorkflow::query()->create(['shipment_id' => $shipment->id] + $values);
        });
    }

    public function handoffToCenterAsOriginalRider(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $assignment = $locked->originalDeliveryAssignment()->firstOrFail();
            $this->assertAssignedRider($assignment, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();

            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_CENTER_HANDOFF', $method, $shipment->tracking_number, 'SUCCESS', $center, $assignment, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT,
                'center_handoff_by_user_id' => $actor->id,
                'center_handoff_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Return parcel handed to the sorting center by the original delivery rider.', $assignment);

            return $locked->refresh();
        });
    }

    public function receiveAtCenter(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();

            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_CENTER_RECEIVED', $method, $shipment->tracking_number, 'SUCCESS', $center, null, $notes);
            $status = ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT;
            $note = 'Sorting center confirmed receipt of the returned parcel.';
            if ($this->schema->shipmentReturnEnhancementsReady()) {
                if ($locked->return_disposition === ShipmentReturnWorkflow::DISPOSITION_REDELIVERY) {
                    $status = ShipmentReturnWorkflow::CENTER_RECEIVED_FOR_REDELIVERY;
                    $note = 'Logistics received the undelivered parcel; it remains eligible for the approved redelivery workflow.';
                } else {
                    $sellerCenterId = (int) $shipment->origin_logistics_center_id;
                    if ($sellerCenterId <= 0) {
                        $status = ShipmentReturnWorkflow::RETURN_EXCEPTION;
                        $this->recordReturnException($locked, $actor, 'The seller-side return center is not recorded for this shipment.');
                        $note = 'Sorting center received the return parcel, but seller-side routing needs Logistics review.';
                    } elseif ($sellerCenterId !== (int) $center->id) {
                        $status = ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER;
                        $sellerCenter = LogisticsCenter::query()->with('address')->find($sellerCenterId);
                        if (! $sellerCenter) {
                            $status = ShipmentReturnWorkflow::RETURN_EXCEPTION;
                            $this->recordReturnException($locked, $actor, 'The recorded seller-side center is unavailable. Logistics must review the shipment routing snapshot.');
                            $note = 'Sorting center received the return parcel, but the seller-side center is unavailable; Logistics review is required.';
                        } elseif ($this->schema->transferReady()) {
                            $transfer = app(ShipmentTransferService::class);
                            try {
                                $leg = $transfer->createPlannedLeg($shipment, $center, $sellerCenter);
                                if ($leg && $leg->status === \App\Models\Logistics\ShipmentTransferLeg::PLANNED) {
                                    $transfer->markReady($leg, $actor);
                                }
                            } catch (RuntimeException $exception) {
                                $status = ShipmentReturnWorkflow::RETURN_EXCEPTION;
                                $this->recordReturnException($locked, $actor, 'Cross-center return transfer could not be planned: '.$exception->getMessage());
                                $note = 'Sorting center received the return parcel, but transfer planning needs Logistics review.';
                            }
                        }
                        if ($status === ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER) {
                            $note = $this->schema->transferReady()
                                ? 'The original delivery center received the parcel; it is awaiting transfer to the seller-side center.'
                                : 'The original delivery center received the parcel; seller-side transfer is paused until transfer schema is available.';
                        }
                    }
                }
            }

            $locked->update([
                'status' => $status,
                'center_received_by_user_id' => $actor->id,
                'center_received_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, $note);

            return $locked->refresh();
        });
    }

    /** @param list<string> $trackingNumbers @return list<array{tracking_number:string,success:bool,message:string}> */
    public function receiveBatchAtCenter(User $actor, array $trackingNumbers, string $method, ?string $notes = null): array
    {
        $this->requireAvailable();
        $center = $this->assertLogisticsActor($actor);
        $results = [];
        $seen = [];

        foreach ($trackingNumbers as $trackingNumber) {
            $code = trim((string) $trackingNumber);
            $normalized = strtoupper($code);
            if ($code === '') {
                continue;
            }
            if (isset($seen[$normalized])) {
                $results[] = ['tracking_number' => $code, 'success' => false, 'message' => 'Duplicate tracking number in this receiving batch.'];
                continue;
            }
            $seen[$normalized] = true;

            $workflow = ShipmentReturnWorkflow::query()
                ->where('logistics_center_id', $center->id)
                ->where('status', ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT)
                ->whereHas('shipment', fn ($query) => $query->where('tracking_number', $code))
                ->first();
            if (! $workflow) {
                $results[] = ['tracking_number' => $code, 'success' => false, 'message' => 'No parcel at this center is awaiting return receipt for that tracking number.'];
                continue;
            }

            try {
                $this->receiveAtCenter($workflow, $actor, $method, $code, $notes);
                $results[] = ['tracking_number' => $code, 'success' => true, 'message' => 'Receipt confirmed.'];
            } catch (RuntimeException $exception) {
                $results[] = ['tracking_number' => $code, 'success' => false, 'message' => $exception->getMessage()];
            }
        }

        return $results;
    }

    public function prepareCrossCenterTransfer(ShipmentReturnWorkflow $workflow, User $actor): ShipmentReturnWorkflow
    {
        if (! $this->schema->shipmentReturnEnhancementsReady() || ! $this->schema->transferReady()) {
            throw new RuntimeException('Cross-center return transfers are unavailable until the return and transfer schema is installed.');
        }

        return DB::transaction(function () use ($workflow, $actor): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $center = $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $sellerCenter = LogisticsCenter::query()->with('address')->find((int) $shipment->origin_logistics_center_id);
            if (! $sellerCenter || (int) $sellerCenter->id === (int) $center->id) {
                throw new RuntimeException('A different verified seller-side center is required for this transfer.');
            }

            $transfer = app(ShipmentTransferService::class);
            $leg = $transfer->createPlannedLeg($shipment, $center, $sellerCenter);
            if ($leg && $leg->status === \App\Models\Logistics\ShipmentTransferLeg::PLANNED) {
                $transfer->markReady($leg, $actor);
            }
            $this->recordReturnEvent($shipment, $actor, $center, 'Return transfer to the seller-side center is ready to join an authorized inter-center manifest.');

            return $locked->refresh();
        });
    }

    public function assignReturnRider(ShipmentReturnWorkflow $workflow, User $actor, int $riderProfileId): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $riderProfileId): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $center = $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [
                ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT,
                ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER,
                ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED,
            ]);
            $rider = RiderProfile::query()
                ->whereKey($riderProfileId)
                ->where('logistics_center_id', $center->id)
                ->whereIn('status', ['ACTIVE', 'WARNED'])
                ->whereIn('vehicle_type', RiderVehicleType::localValues())
                ->first();
            if (! $rider) {
                throw new RuntimeException('Select an active local rider assigned to this sorting center.');
            }
            if ((int) $locked->return_rider_profile_id === (int) $rider->id) {
                throw new RuntimeException('This rider is already assigned to this return.');
            }
            $hadPreviousRider = $locked->return_rider_profile_id !== null;

            $locked->update([
                'return_rider_profile_id' => $rider->id,
                'return_rider_assigned_by_user_id' => $actor->id,
                'return_rider_assigned_at' => now(),
                'return_rider_accepted_at' => null,
                'return_rider_picked_up_by_user_id' => null,
                'return_rider_picked_up_at' => null,
                'seller_handoff_by_user_id' => null,
                'seller_handoff_at' => null,
                'status' => ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER,
            ]);
            $assignmentNote = $hadPreviousRider
                ? 'Logistics reassigned the return rider before parcel pickup.'
                : 'Logistics assigned a return-to-seller rider.';
            $this->recordReturnEvent($locked->shipment()->firstOrFail(), $actor, $center, $assignmentNote);

            return $locked->refresh();
        });
    }

    public function requestReturnRiderHandoff(ShipmentReturnWorkflow $workflow, User $actor, int $newRiderProfileId, string $reason): ShipmentReturnWorkflow
    {
        if (! $this->schema->shipmentReturnEnhancementsReady()) {
            throw new RuntimeException('Verified return-rider handoff is unavailable until its additive migration is installed.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required when starting a return-rider handoff.');
        }

        return DB::transaction(function () use ($workflow, $actor, $newRiderProfileId, $reason): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $center = $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER]);
            if (! $locked->return_rider_picked_up_at || ! $locked->return_rider_profile_id) {
                throw new RuntimeException('A return rider must have scanned pickup before a post-pickup handoff can be requested.');
            }

            $newRider = RiderProfile::query()
                ->whereKey($newRiderProfileId)
                ->where('logistics_center_id', $center->id)
                ->whereIn('status', ['ACTIVE', 'WARNED'])
                ->whereIn('vehicle_type', RiderVehicleType::localValues())
                ->whereHas('user', fn ($query) => $query->where('status', User::STATUS_ACTIVE)->where('account_type', User::TYPE_RIDER))
                ->first();
            if (! $newRider) {
                throw new RuntimeException('Select an active local return rider assigned to this sorting center.');
            }
            if ((int) $newRider->id === (int) $locked->return_rider_profile_id) {
                throw new RuntimeException('The new rider must differ from the current parcel custodian.');
            }

            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $locked->update([
                'status' => ShipmentReturnWorkflow::RETURN_RIDER_HANDOFF_PENDING,
                'pending_return_rider_profile_id' => $newRider->id,
                'handoff_authorized_by_user_id' => $actor->id,
                'handoff_authorized_at' => now(),
                'handoff_reason' => $reason,
                'handoff_from_confirmed_at' => null,
                'handoff_to_confirmed_at' => null,
                'handoff_completed_at' => null,
                'return_exception_reason' => null,
                'return_exception_recorded_by_user_id' => null,
                'return_exception_recorded_at' => null,
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Return rider reassignment is pending two verified tracking scans. The current rider remains the parcel custodian. Reason: '.$reason);

            return $locked->refresh();
        });
    }

    public function confirmReturnRiderHandoffFrom(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::RETURN_RIDER_HANDOFF_PENDING]);
            if ($locked->handoff_from_confirmed_at) {
                throw new RuntimeException('The current rider has already confirmed this handoff.');
            }
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();
            $this->shipments->recordScan($shipment, $actor, 'RETURN_RIDER_HANDOFF_FROM', $method, $shipment->tracking_number, 'SUCCESS', $center);
            $locked->update(['handoff_from_confirmed_at' => now()]);
            $this->completeReturnRiderHandoffIfVerified($locked, $shipment, $actor, $center);

            return $locked->refresh();
        });
    }

    public function cancelReturnRiderHandoff(ShipmentReturnWorkflow $workflow, User $actor, string $reason): ShipmentReturnWorkflow
    {
        if (! $this->schema->shipmentReturnEnhancementsReady()) {
            throw new RuntimeException('Verified return-rider handoff is unavailable until its additive migration is installed.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required when stopping an incomplete rider handoff.');
        }

        return DB::transaction(function () use ($workflow, $actor, $reason): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $center = $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::RETURN_RIDER_HANDOFF_PENDING]);
            if ($locked->handoff_to_confirmed_at) {
                throw new RuntimeException('Both rider scans are already confirmed; the completed handoff cannot be cancelled.');
            }

            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $locked->update([
                'status' => ShipmentReturnWorkflow::RETURN_EXCEPTION,
                'pending_return_rider_profile_id' => null,
                'return_exception_reason' => $reason,
                'return_exception_recorded_by_user_id' => $actor->id,
                'return_exception_recorded_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Logistics stopped an incomplete rider handoff. Existing rider custody is retained and requires review. Reason: '.$reason);

            return $locked->refresh();
        });
    }

    public function resolveReturnException(ShipmentReturnWorkflow $workflow, User $actor, string $resolutionNotes): ShipmentReturnWorkflow
    {
        if (! $this->schema->shipmentReturnEnhancementsReady()) {
            throw new RuntimeException('Return exception handling is unavailable until its additive migration is installed.');
        }
        $resolutionNotes = trim($resolutionNotes);
        if ($resolutionNotes === '') {
            throw new RuntimeException('Enter the Logistics review and resolution notes.');
        }

        return DB::transaction(function () use ($workflow, $actor, $resolutionNotes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $center = $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::RETURN_EXCEPTION]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $originCenterId = (int) $shipment->origin_logistics_center_id;
            $currentCenterId = (int) $shipment->logistics_center_id;
            if ($originCenterId <= 0) {
                throw new RuntimeException('The shipment has no verified seller-side center. Correct its routing data before resolving this exception.');
            }

            $nextStatus = $locked->return_rider_picked_up_at && $locked->return_rider_profile_id
                ? ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER
                : ($currentCenterId === $originCenterId
                    ? ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT
                    : ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER);
            if ($nextStatus === ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER && ! $this->schema->transferReady()) {
                throw new RuntimeException('Transfer schema is unavailable; this parcel must remain in exception review until it is installed.');
            }
            $locked->update([
                'status' => $nextStatus,
                'logistics_center_id' => $center->id,
                'pending_return_rider_profile_id' => null,
                'return_exception_reason' => null,
                'return_exception_recorded_by_user_id' => null,
                'return_exception_recorded_at' => null,
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Logistics resolved the return exception and resumed '.$nextStatus.'. Review: '.$resolutionNotes);

            return $locked->refresh();
        });
    }

    public function confirmReturnRiderHandoffTo(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertPendingReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::RETURN_RIDER_HANDOFF_PENDING]);
            if (! $locked->handoff_from_confirmed_at) {
                throw new RuntimeException('The current custodian must scan the parcel before the receiving rider can accept it.');
            }
            if ($locked->handoff_to_confirmed_at) {
                throw new RuntimeException('The receiving rider has already confirmed this handoff.');
            }
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();
            $this->shipments->recordScan($shipment, $actor, 'RETURN_RIDER_HANDOFF_TO', $method, $shipment->tracking_number, 'SUCCESS', $center);
            $locked->update(['handoff_to_confirmed_at' => now()]);
            $this->completeReturnRiderHandoffIfVerified($locked, $shipment, $actor, $center);

            return $locked->refresh();
        });
    }

    public function acceptAsReturnRider(ShipmentReturnWorkflow $workflow, User $actor): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER]);
            $locked->update([
                'status' => ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED,
                'return_rider_accepted_at' => now(),
            ]);
            $this->recordReturnEvent($locked->shipment()->firstOrFail(), $actor, $locked->logisticsCenter()->firstOrFail(), 'Return rider accepted the seller return.');

            return $locked->refresh();
        });
    }

    public function pickupFromCenterAsReturnRider(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();

            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_SELLER_PICKUP', $method, $shipment->tracking_number, 'SUCCESS', $center, null, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER,
                'return_rider_picked_up_by_user_id' => $actor->id,
                'return_rider_picked_up_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Return rider collected the parcel from the sorting center.');

            return $locked->refresh();
        });
    }

    public function handoffToSellerAsReturnRider(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_SELLER_HANDOFF', $method, $shipment->tracking_number, 'SUCCESS', null, null, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::AWAITING_SELLER_CONFIRMATION,
                'seller_handoff_by_user_id' => $actor->id,
                'seller_handoff_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, null, 'Return rider recorded seller handoff; seller confirmation is pending.', null, false);

            return $locked->refresh();
        });
    }

    public function confirmSellerReceipt(ShipmentReturnWorkflow $workflow, User $actor, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $shipment = $locked->shipment()->with('sellerOrder')->lockForUpdate()->firstOrFail();
            $sellerId = $actor->sellerProfile?->id;
            if (! $sellerId || (int) $shipment->sellerOrder?->seller_profile_id !== (int) $sellerId) {
                abort(403);
            }
            if ($locked->status === ShipmentReturnWorkflow::SELLER_RECEIVED) {
                return $locked;
            }
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_SELLER_CONFIRMATION]);
            $this->assertDeliveryFailed($shipment);
            $this->shipments->recordScan(
                $shipment,
                $actor,
                'RETURN_TO_SELLER_CONFIRMED',
                'MANUAL',
                $shipment->tracking_number,
                'SUCCESS',
                null,
                null,
                $notes ?: 'Seller confirmed receipt of the returned parcel.',
            );
            $locked->update([
                'status' => ShipmentReturnWorkflow::SELLER_RECEIVED,
                'seller_received_by_user_id' => $actor->id,
                'seller_received_at' => now(),
                'seller_confirmation_notes' => $notes,
            ]);
            $shipment->update(['current_status' => 'RETURNED']);
            $this->shipments->recordEvent($shipment, 'RETURNED', $actor, 'RETURN_TO_SELLER_COMPLETED: Seller confirmed receipt after reverse custody handoff.', null, null, false);

            return $locked->refresh();
        });
    }

    private function lockWorkflow(ShipmentReturnWorkflow $workflow): ShipmentReturnWorkflow
    {
        $this->requireAvailable();

        return ShipmentReturnWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
    }

    private function completeReturnRiderHandoffIfVerified(ShipmentReturnWorkflow $workflow, Shipment $shipment, User $actor, LogisticsCenter $center): void
    {
        $workflow->refresh();
        if (! $workflow->handoff_from_confirmed_at || ! $workflow->handoff_to_confirmed_at) {
            return;
        }
        if (! $workflow->pending_return_rider_profile_id) {
            throw new RuntimeException('The receiving rider is no longer available for this handoff.');
        }

        $workflow->update([
            'return_rider_profile_id' => $workflow->pending_return_rider_profile_id,
            'pending_return_rider_profile_id' => null,
            'handoff_completed_at' => now(),
            'status' => ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER,
        ]);
        $this->recordReturnEvent($shipment, $actor, $center, 'Return parcel custody transferred after both authorized riders scanned the tracking number.');
    }

    private function recordReturnException(ShipmentReturnWorkflow $workflow, User $actor, string $reason): void
    {
        if (! $this->schema->shipmentReturnEnhancementsReady()) {
            return;
        }

        $workflow->update([
            'return_exception_reason' => trim($reason),
            'return_exception_recorded_by_user_id' => $actor->id,
            'return_exception_recorded_at' => now(),
        ]);
    }

    private function requireAvailable(): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Return-to-seller workflow is unavailable until its additive database migration is installed.');
        }
    }

    private function assertStatus(ShipmentReturnWorkflow $workflow, array $allowed): void
    {
        if (! in_array($workflow->status, $allowed, true)) {
            throw new RuntimeException('This return step is no longer available. Refresh the page to see the current custody status.');
        }
    }

    private function assertDeliveryFailed(Shipment $shipment): void
    {
        if ($shipment->current_status !== 'DELIVERY_FAILED') {
            throw new RuntimeException('The parcel must remain in DELIVERY_FAILED until the seller confirms return receipt.');
        }
    }

    private function assertTrackingCode(Shipment $shipment, string $code): void
    {
        if (! hash_equals((string) $shipment->tracking_number, trim($code))) {
            throw new RuntimeException('The scanned code does not match this parcel tracking number.');
        }
    }

    private function assertLogisticsCenter(ShipmentReturnWorkflow $workflow, User $actor): LogisticsCenter
    {
        $center = $this->assertLogisticsActor($actor);
        if (! $center || (int) $center->id !== (int) $workflow->logistics_center_id) {
            abort(403);
        }

        return $center;
    }

    private function assertLogisticsActor(User $actor): LogisticsCenter
    {
        $actor->loadMissing('logisticsCenter');
        $center = $actor->logisticsCenter;
        if ($actor->account_type !== User::TYPE_LOGISTICS || $actor->status !== User::STATUS_ACTIVE || ! $center) {
            abort(403);
        }

        return $center;
    }

    private function assertAssignedRider(RiderAssignment $assignment, User $actor): void
    {
        $actor->loadMissing('riderProfile');
        if ($assignment->assignment_type !== RiderAssignment::TYPE_DELIVERY
            || ! $actor->riderProfile
            || (int) $assignment->rider_profile_id !== (int) $actor->riderProfile->id) {
            abort(403);
        }
    }

    private function assertReturnRider(ShipmentReturnWorkflow $workflow, User $actor): RiderProfile
    {
        $actor->loadMissing('riderProfile');
        $profile = $actor->riderProfile;
        if ($actor->account_type !== User::TYPE_RIDER || $actor->status !== User::STATUS_ACTIVE
            || ! $profile || ! in_array($profile->status, ['ACTIVE', 'WARNED'], true)
            || (int) $profile->id !== (int) $workflow->return_rider_profile_id) {
            abort(403);
        }

        return $profile;
    }

    private function assertPendingReturnRider(ShipmentReturnWorkflow $workflow, User $actor): RiderProfile
    {
        $actor->loadMissing('riderProfile');
        $profile = $actor->riderProfile;
        if ($actor->account_type !== User::TYPE_RIDER || $actor->status !== User::STATUS_ACTIVE
            || ! $profile || ! in_array($profile->status, ['ACTIVE', 'WARNED'], true)
            || (int) $profile->id !== (int) $workflow->pending_return_rider_profile_id) {
            abort(403);
        }

        return $profile;
    }

    private function recordReturnEvent(Shipment $shipment, User $actor, ?LogisticsCenter $center, string $notes, ?RiderAssignment $assignment = null, bool $useShipmentCenterFallback = true): void
    {
        $this->shipments->recordEvent($shipment, 'DELIVERY_FAILED', $actor, 'RETURN_CUSTODY: '.$notes, $assignment, $center, $useShipmentCenterFallback);
    }
}
