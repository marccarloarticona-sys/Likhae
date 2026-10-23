<?php

namespace App\Services\Fulfillment;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ServiceArea;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Support\LocalTripCapacityRules;
use App\Support\RiderVehicleType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LocalRiderTripService
{
    private const OPEN_ASSIGNMENT_STATUSES = ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'];

    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly LocalRiderEligibilityService $eligibility,
    ) {}

    public function createTrip(User $actor, RiderProfile $rider, ServiceArea $area, string $tripType, ?SellerProfile $seller = null): RiderLocalTrip
    {
        $tripType = strtoupper(trim($tripType));
        if (! in_array($tripType, [RiderLocalTrip::TYPE_PICKUP, RiderLocalTrip::TYPE_DELIVERY], true)) {
            throw new RuntimeException('A local trip must be a pickup or delivery trip.');
        }
        if ($tripType === RiderLocalTrip::TYPE_PICKUP) {
            $this->assertSellerPickupReady();
        } else {
            $this->assertReady();
        }
        if ($tripType === RiderLocalTrip::TYPE_PICKUP && (! $seller || ! $seller->exists)) {
            throw new RuntimeException('A seller pickup trip must identify exactly one seller.');
        }
        if ($tripType === RiderLocalTrip::TYPE_DELIVERY && $seller) {
            throw new RuntimeException('A delivery trip cannot be assigned a seller pickup profile.');
        }

        $actor->loadMissing('logisticsCenter');
        $rider->loadMissing('user', 'logisticsCenter', 'areaAssignments');
        $area->loadMissing('locations', 'logisticsCenter');
        $this->assertActorAtCenter($actor, (int) $rider->logistics_center_id);
        $location = $this->eligibility->assertRiderCanCoverArea($rider->logisticsCenter, $rider, $area);
        if (! $rider->areaAssignments->contains(fn ($assignment): bool => $assignment->is_active && (int) $assignment->service_area_id === (int) $area->id)) {
            throw new RuntimeException('The rider is not actively assigned to this service area.');
        }
        $this->assertCanonicalBarangay($rider->logisticsCenter, $location->province_code, $location->municipality_code, $location->barangay_code);
        if ($tripType === RiderLocalTrip::TYPE_PICKUP) {
            $seller->loadMissing('businessAddress');
            $address = $seller->businessAddress;
            if (! $address
                || trim((string) $address->province_code) !== trim((string) $location->province_code)
                || trim((string) $address->municipality_code) !== trim((string) $location->municipality_code)
                || trim((string) $address->barangay_code) !== trim((string) $location->barangay_code)) {
                throw new RuntimeException('The pickup trip service area must match the seller business address PSGC.');
            }
        }
        $capacity = LocalTripCapacityRules::approvedCapacity($rider->approved_parcel_capacity);

        return RiderLocalTrip::query()->create([
            'rider_profile_id' => $rider->id,
            'logistics_center_id' => $rider->logistics_center_id,
            'seller_profile_id' => $seller?->id,
            'trip_type' => $tripType,
            'province_code' => trim((string) $location->province_code),
            'municipality_code' => trim((string) $location->municipality_code),
            'barangay_code' => trim((string) $location->barangay_code),
            'approved_parcel_capacity' => $capacity,
            'status' => RiderLocalTrip::LOADING,
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function addAssignment(User $actor, RiderLocalTrip $trip, RiderAssignment $assignment): RiderLocalTripItem
    {
        if ($trip->trip_type === RiderLocalTrip::TYPE_PICKUP) {
            $this->assertSellerPickupReady();
        } else {
            $this->assertReady();
        }

        return DB::transaction(function () use ($actor, $trip, $assignment): RiderLocalTripItem {
            $trip = RiderLocalTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $assignment = RiderAssignment::query()->whereKey($assignment->id)->with([
                'riderProfile.user', 'riderProfile.logisticsCenter',
                'riderProfile.areaAssignments.serviceArea.locations',
                'shipment.sellerOrder.sellerProfile.businessAddress',
            ])->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $trip->logistics_center_id);
            $this->assertTripOpenForLoading($trip);
            if ((int) $assignment->rider_profile_id !== (int) $trip->rider_profile_id
                || (int) $assignment->riderProfile?->logistics_center_id !== (int) $trip->logistics_center_id) {
                throw new RuntimeException('The assignment rider and logistics center do not match this trip.');
            }
            if (! in_array($assignment->status, self::OPEN_ASSIGNMENT_STATUSES, true)) {
                throw new RuntimeException('Only an active rider assignment can be loaded onto a trip.');
            }
            if (($trip->trip_type === RiderLocalTrip::TYPE_PICKUP && $assignment->assignment_type !== RiderAssignment::TYPE_PICKUP)
                || ($trip->trip_type === RiderLocalTrip::TYPE_DELIVERY && $assignment->assignment_type !== RiderAssignment::TYPE_DELIVERY)) {
                throw new RuntimeException('The assignment type does not match this trip.');
            }

            if ($trip->trip_type === RiderLocalTrip::TYPE_DELIVERY
                && $this->shipmentRequiresHubTransfer($assignment->shipment)) {
                throw new RuntimeException('This parcel is assigned to a destination hub transfer and cannot be added to a local delivery trip.');
            }

            if ($trip->trip_type === RiderLocalTrip::TYPE_PICKUP
                && ((int) $trip->seller_profile_id < 1
                    || (int) $trip->seller_profile_id !== (int) $assignment->shipment?->sellerOrder?->seller_profile_id)) {
                throw new RuntimeException('Every parcel in a seller pickup trip must belong to its one assigned seller.');
            }

            [$provinceCode, $municipalityCode, $barangayCode] = $this->assignmentCodes($assignment);
            if ($provinceCode !== trim((string) $trip->province_code)
                || $municipalityCode !== trim((string) $trip->municipality_code)
                || $barangayCode !== trim((string) $trip->barangay_code)) {
                throw new RuntimeException('The assignment PSGC barangay does not match the trip barangay.');
            }
            $this->assertCanonicalBarangay($assignment->riderProfile->logisticsCenter, $provinceCode, $municipalityCode, $barangayCode);
            if (! $this->riderHasExactArea($assignment->riderProfile, $provinceCode, $municipalityCode, $barangayCode)) {
                throw new RuntimeException('The rider has no active service-area assignment for this exact barangay PSGC.');
            }

            $duplicate = RiderLocalTripItem::query()
                ->where('rider_assignment_id', $assignment->id)
                ->whereHas('trip', fn ($query) => $query->whereNotIn('status', [RiderLocalTrip::COMPLETED, RiderLocalTrip::CANCELLED, RiderLocalTrip::EXCEPTION]))
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                throw new RuntimeException('This rider assignment is already loaded on an active local trip.');
            }

            if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY) {
                $shipmentAlreadyLoaded = RiderLocalTripItem::query()
                    ->whereHas('riderAssignment', fn ($query) => $query
                        ->where('shipment_id', $assignment->shipment_id)
                        ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                        ->whereKeyNot($assignment->id))
                    ->whereHas('trip', fn ($query) => $query->whereNotIn('status', [
                        RiderLocalTrip::COMPLETED,
                        RiderLocalTrip::CANCELLED,
                        RiderLocalTrip::EXCEPTION,
                    ]))
                    ->lockForUpdate()
                    ->exists();
                if ($shipmentAlreadyLoaded) {
                    throw new RuntimeException('This shipment already belongs to another active delivery trip.');
                }
            }

            if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP) {
                $shipmentAlreadyLoaded = RiderLocalTripItem::query()
                    ->whereHas('riderAssignment', fn ($query) => $query
                        ->where('shipment_id', $assignment->shipment_id)
                        ->where('assignment_type', RiderAssignment::TYPE_PICKUP))
                    ->whereHas('trip', fn ($query) => $query->whereNotIn('status', [
                        RiderLocalTrip::COMPLETED,
                        RiderLocalTrip::CANCELLED,
                        RiderLocalTrip::EXCEPTION,
                    ]))
                    ->lockForUpdate()
                    ->exists();
                if ($shipmentAlreadyLoaded) {
                    throw new RuntimeException('This shipment is already assigned to an active seller pickup batch.');
                }
            }

            $loadedCount = $trip->items()->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED])->count();
            LocalTripCapacityRules::assertAvailable((int) $loadedCount, (int) $trip->approved_parcel_capacity);

            return RiderLocalTripItem::query()->create([
                'rider_local_trip_id' => $trip->id,
                'rider_assignment_id' => $assignment->id,
                'added_by_user_id' => $actor->id,
                'status' => RiderLocalTripItem::QUEUED,
            ]);
        });
    }

    /**
     * Local delivery trip loads are verified by confirming physical custody.
     * The optional scan map remains accepted for compatibility with older callers.
     *
     * @param array<int|string, string> $scannedCodes ignored for local trip load verification
     */
    public function loadTrip(User $actor, RiderLocalTrip $trip, array $scannedCodes = []): RiderLocalTrip
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $trip): RiderLocalTrip {
            $trip = RiderLocalTrip::query()->whereKey($trip->id)->with('items.riderAssignment.shipment')->lockForUpdate()->firstOrFail();
            $this->assertTripActor($actor, $trip, true);
            if ($trip->trip_type === RiderLocalTrip::TYPE_PICKUP) {
                throw new RuntimeException('Seller pickup batches are verified one parcel at a time at the seller.');
            }
            $activeItems = $trip->items->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED]);
            if ($trip->trip_type === RiderLocalTrip::TYPE_DELIVERY
                && $activeItems->contains(fn (RiderLocalTripItem $item): bool =>
                    $this->shipmentRequiresHubTransfer($item->riderAssignment?->shipment))) {
                throw new RuntimeException('This local trip contains a parcel that must be sent through Hub Transfers.');
            }
            if ($trip->status === RiderLocalTrip::READY
                && $trip->items->every(fn (RiderLocalTripItem $item): bool => $item->status === RiderLocalTripItem::LOADED)) {
                return $trip;
            }
            $this->assertTripOpenForLoading($trip);
            if ($trip->items->isEmpty()) {
                throw new RuntimeException('A local trip must contain at least one assignment before loading.');
            }
            if ($activeItems->count() > (int) $trip->approved_parcel_capacity) {
                throw new RuntimeException('The local trip exceeds its approved parcel capacity.');
            }
            if ($activeItems->contains(fn (RiderLocalTripItem $item): bool => ! in_array($item->riderAssignment?->status, self::OPEN_ASSIGNMENT_STATUSES, true))) {
                throw new RuntimeException('Every trip item must have an active rider assignment before loading.');
            }
            $now = now();
            $trip->items()->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED])->update([
                'status' => RiderLocalTripItem::LOADED,
                'loaded_at' => $now,
                'verified_at' => $now,
            ]);
            $trip->update(['status' => RiderLocalTrip::READY]);

            return $trip->refresh();
        });
    }

    public function startTrip(User $actor, RiderLocalTrip $trip): RiderLocalTrip
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $trip): RiderLocalTrip {
            $trip = RiderLocalTrip::query()->whereKey($trip->id)->with('items.riderAssignment.shipment')->lockForUpdate()->firstOrFail();
            $this->assertTripActor($actor, $trip, false);
            if ($trip->trip_type === RiderLocalTrip::TYPE_PICKUP) {
                throw new RuntimeException('Use the Seller Pickup page to accept and start a pickup batch.');
            }
            if ($trip->trip_type === RiderLocalTrip::TYPE_DELIVERY
                && $trip->items->contains(fn (RiderLocalTripItem $item): bool =>
                    in_array($item->status, [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED], true)
                    && $this->shipmentRequiresHubTransfer($item->riderAssignment?->shipment))) {
                throw new RuntimeException('This local trip contains a parcel that must be sent through Hub Transfers.');
            }
            if ($trip->status === RiderLocalTrip::IN_PROGRESS) {
                return $trip;
            }
            if (! in_array($trip->status, [RiderLocalTrip::LOADING, RiderLocalTrip::READY], true)) {
                throw new RuntimeException('Only an active loading trip can depart.');
            }
            $activeItems = $trip->items->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED]);
            if ($activeItems->isEmpty()) {
                throw new RuntimeException('Add at least one delivery parcel to this trip before departure.');
            }
            if ($activeItems->count() > (int) $trip->approved_parcel_capacity) {
                throw new RuntimeException('The local trip exceeds its approved parcel capacity.');
            }
            if ($activeItems->contains(fn (RiderLocalTripItem $item): bool => ! in_array($item->riderAssignment?->status, self::OPEN_ASSIGNMENT_STATUSES, true))) {
                throw new RuntimeException('Every parcel on the trip must have an active rider assignment before departure.');
            }

            $now = now();
            $trip->items()->where('status', RiderLocalTripItem::QUEUED)->update([
                'status' => RiderLocalTripItem::LOADED,
                'loaded_at' => $now,
            ]);
            $trip->update(['status' => RiderLocalTrip::IN_PROGRESS, 'started_at' => $now]);

            return $trip->refresh();
        });
    }

    public function startTripForAssignment(User $actor, RiderAssignment $assignment): void
    {
        if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP || ! $this->schema->localTripsReady()) {
            return;
        }
        $item = RiderLocalTripItem::query()->with('trip')->where('rider_assignment_id', $assignment->id)->latest()->first();
        if ($item) {
            $this->startTrip($actor, $item->trip);
        }
    }

    public function assertSellerPickupBatchAssignment(RiderAssignment $assignment): void
    {
        if (! $this->schema->sellerPickupBatchesReady()) {
            throw new RuntimeException('Seller rider pickups require the seller-specific local-trip schema.');
        }

        $assignment->loadMissing('shipment.sellerOrder');
        $item = RiderLocalTripItem::query()
            ->with('trip')
            ->where('rider_assignment_id', $assignment->id)
            ->whereHas('trip', fn ($query) => $query
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->whereNotNull('seller_profile_id'))
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if (! $item
            || (int) $item->trip?->seller_profile_id !== (int) $assignment->shipment?->sellerOrder?->seller_profile_id) {
            throw new RuntimeException('This parcel is not part of an active seller-specific pickup assignment.');
        }
    }

    public function closeTrip(User $actor, RiderLocalTrip $trip, bool $exception = false, ?string $notes = null): RiderLocalTrip
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $trip, $exception, $notes): RiderLocalTrip {
            $trip = RiderLocalTrip::query()->whereKey($trip->id)->with('items')->lockForUpdate()->firstOrFail();
            $this->assertTripActor($actor, $trip, true);
            if ($trip->trip_type === RiderLocalTrip::TYPE_PICKUP) {
                throw new RuntimeException('A seller pickup batch closes only after every collected parcel is received at its logistics center.');
            }
            if (in_array($trip->status, [RiderLocalTrip::COMPLETED, RiderLocalTrip::CANCELLED], true)) {
                return $trip;
            }
            if ($exception) {
                $trip->update(['status' => RiderLocalTrip::EXCEPTION, 'exception_notes' => $notes, 'completed_at' => now()]);
                return $trip->refresh();
            }
            if ($trip->items->contains(fn (RiderLocalTripItem $item): bool => ! in_array($item->status, [RiderLocalTripItem::COMPLETED, RiderLocalTripItem::FAILED, RiderLocalTripItem::CANCELLED], true))) {
                throw new RuntimeException('A local trip cannot close while an item is still queued or loaded.');
            }
            $trip->update(['status' => RiderLocalTrip::COMPLETED, 'completed_at' => now()]);
            return $trip->refresh();
        });
    }

    public function syncAssignment(RiderAssignment $assignment): void
    {
        $ready = $assignment->assignment_type === RiderAssignment::TYPE_PICKUP
            ? $this->schema->sellerPickupBatchesReady()
            : $this->schema->localTripsReady();
        if (! $ready) {
            return;
        }

        DB::transaction(function () use ($assignment): void {
            $item = RiderLocalTripItem::query()->with('trip')->where('rider_assignment_id', $assignment->id)->lockForUpdate()->latest()->first();
            if (! $item || in_array($item->status, [RiderLocalTripItem::COMPLETED, RiderLocalTripItem::FAILED, RiderLocalTripItem::CANCELLED], true)) {
                return;
            }

            $next = match ($assignment->status) {
                'COMPLETED' => RiderLocalTripItem::COMPLETED,
                'REJECTED' => RiderLocalTripItem::FAILED,
                'CANCELLED' => RiderLocalTripItem::CANCELLED,
                default => null,
            };
            if ($next === null) {
                return;
            }
            $item->update([
                'status' => $next,
                'completed_at' => now(),
                'failure_reason' => $next === RiderLocalTripItem::FAILED ? $assignment->rejection_reason : null,
            ]);

            $trip = RiderLocalTrip::query()->whereKey($item->rider_local_trip_id)->with('items')->lockForUpdate()->first();
            if ($trip && $trip->trip_type !== RiderLocalTrip::TYPE_PICKUP
                && $trip->items->every(fn (RiderLocalTripItem $row): bool => in_array($row->status, [RiderLocalTripItem::COMPLETED, RiderLocalTripItem::FAILED, RiderLocalTripItem::CANCELLED], true))) {
                $trip->update(['status' => RiderLocalTrip::COMPLETED, 'completed_at' => now()]);
            }
        });
    }

    public function syncPickupIntake(Shipment $shipment): void
    {
        if (! $this->schema->sellerPickupBatchesReady()) {
            return;
        }

        DB::transaction(function () use ($shipment): void {
            $item = RiderLocalTripItem::query()
                ->whereHas('riderAssignment', fn ($query) => $query
                    ->where('shipment_id', $shipment->id)
                    ->where('assignment_type', RiderAssignment::TYPE_PICKUP))
                ->whereHas('trip', fn ($query) => $query
                    ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                    ->whereNotIn('status', [RiderLocalTrip::COMPLETED, RiderLocalTrip::CANCELLED, RiderLocalTrip::EXCEPTION]))
                ->with('trip')
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if (! $item) {
                return;
            }

            $trip = RiderLocalTrip::query()
                ->whereKey($item->rider_local_trip_id)
                ->with('items.riderAssignment.shipment')
                ->lockForUpdate()
                ->first();
            if (! $trip || $trip->status !== RiderLocalTrip::EN_ROUTE_TO_HUB) {
                return;
            }

            $collectedItems = $trip->items->filter(fn (RiderLocalTripItem $row): bool => $row->riderAssignment?->status === 'COMPLETED');
            $unresolvedItems = $trip->items->contains(fn (RiderLocalTripItem $row): bool => ! in_array(
                $row->riderAssignment?->status,
                ['COMPLETED', 'REJECTED', 'CANCELLED'],
                true,
            ));
            $allCollectedReceived = $collectedItems->isNotEmpty()
                && $collectedItems->every(fn (RiderLocalTripItem $row): bool => $row->riderAssignment?->shipment?->hasReachedSortingCenter());

            if (! $unresolvedItems && $allCollectedReceived) {
                $trip->update(['status' => RiderLocalTrip::COMPLETED, 'completed_at' => now()]);
            }
        });
    }

    public function assertAssignmentMayStart(RiderAssignment $assignment): void
    {
        // Pickup assignments are route stops on the way to the seller. The parcel
        // is not in the rider's custody yet, so it is not part of the delivery
        // load before departure. Delivery assignments still require a verified trip load.
        if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY) {
            $assignment->loadMissing('shipment');
            if ($this->shipmentRequiresHubTransfer($assignment->shipment)) {
                throw new RuntimeException('This parcel must reach its destination hub before local delivery can start.');
            }
        }
        // Local delivery assignments may start directly. Trip grouping is used
        // to start multiple assigned parcels together, but departure does not
        // require a separate trip load verification.
    }

    private function assignmentCodes(RiderAssignment $assignment): array
    {
        if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP) {
            $address = $assignment->shipment?->sellerOrder?->sellerProfile?->businessAddress;
            return $this->codesFrom($address);
        }

        return [
            trim((string) $assignment->shipment?->destination_province_code),
            trim((string) $assignment->shipment?->destination_municipality_code),
            trim((string) $assignment->shipment?->destination_barangay_code),
        ];
    }

    private function codesFrom(?object $address): array
    {
        return [
            trim((string) $address?->province_code),
            trim((string) $address?->municipality_code),
            trim((string) $address?->barangay_code),
        ];
    }

    private function assertCanonicalBarangay(LogisticsCenter $center, ?string $provinceCode, ?string $municipalityCode, ?string $barangayCode): void
    {
        if (blank($provinceCode) || blank($municipalityCode) || blank($barangayCode)
            || ! $this->eligibility->centerCoversMunicipality($center, $provinceCode, $municipalityCode)) {
            throw new RuntimeException('The trip requires a valid PSGC province, municipality, and barangay owned by the logistics center.');
        }
        $records = PhilippineAddressController::barangayRecordsForMunicipality(trim((string) $municipalityCode));
        $valid = collect($records)->contains(fn (array $record): bool => trim((string) ($record['code'] ?? '')) === trim((string) $barangayCode));
        if (! $valid) {
            throw new RuntimeException('The selected barangay PSGC is not valid for the selected municipality.');
        }
    }

    private function riderHasExactArea(RiderProfile $rider, string $provinceCode, string $municipalityCode, string $barangayCode): bool
    {
        return $rider->areaAssignments->contains(function ($assignment) use ($provinceCode, $municipalityCode, $barangayCode): bool {
            if (! $assignment->is_active || ! $assignment->serviceArea?->is_active) {
                return false;
            }
            return $assignment->serviceArea->locations->contains(fn ($location): bool =>
                trim((string) $location->province_code) === $provinceCode
                && trim((string) $location->municipality_code) === $municipalityCode
                && trim((string) $location->barangay_code) === $barangayCode);
        });
    }

    private function shipmentRequiresHubTransfer(?Shipment $shipment): bool
    {
        return $shipment !== null
            && $this->schema->automaticRoutingReady()
            && filled($shipment->origin_logistics_center_id)
            && filled($shipment->destination_logistics_center_id)
            && (int) $shipment->origin_logistics_center_id !== (int) $shipment->destination_logistics_center_id
            && (int) $shipment->logistics_center_id !== (int) $shipment->destination_logistics_center_id;
    }

    private function assertTripOpenForLoading(RiderLocalTrip $trip): void
    {
        if ($trip->status !== RiderLocalTrip::LOADING) {
            throw new RuntimeException('Only a loading local trip can receive assignments.');
        }
        LocalTripCapacityRules::approvedCapacity((int) $trip->approved_parcel_capacity);
    }

    private function assertTripActor(User $actor, RiderLocalTrip $trip, bool $allowCenterOperator): void
    {
        $trip->loadMissing('rider');
        $actor->loadMissing('logisticsCenter');
        if ((int) $actor->id === (int) $trip->rider?->user_id) {
            return;
        }
        if ($allowCenterOperator && $actor->account_type === User::TYPE_LOGISTICS
            && $actor->status === User::STATUS_ACTIVE
            && (int) $actor->logisticsCenter?->id === (int) $trip->logistics_center_id) {
            return;
        }
        throw new RuntimeException('Only the assigned rider or an authorized home-hub operator can operate this trip.');
    }

    private function assertActorAtCenter(User $actor, int $centerId): void
    {
        if ((int) $actor->logisticsCenter?->id !== $centerId
            || $actor->account_type !== User::TYPE_LOGISTICS
            || $actor->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('The authenticated user is not authorized for this logistics center.');
        }
    }

    private function assertReady(): void
    {
        if (! $this->schema->localTripsReady()) {
            throw new RuntimeException('Local trip schema and Step 4 custody dependencies are not installed; local-trip dispatch is disabled.');
        }
    }

    private function assertSellerPickupReady(): void
    {
        if (! $this->schema->sellerPickupBatchesReady()) {
            throw new RuntimeException('Seller pickup batch schema and rider capacity are not installed; seller pickup dispatch is disabled.');
        }
    }
}
