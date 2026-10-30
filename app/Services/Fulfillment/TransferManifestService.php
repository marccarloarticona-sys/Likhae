<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

final class TransferManifestService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly ShipmentTransferService $transfers,
        private readonly TransferCapacityService $capacity,
    ) {}

    public function create(
        User $actor,
        LogisticsCenter $origin,
        LogisticsCenter $destination,
        ?string $notes = null,
        ?RiderProfile $driver = null,
    ): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $origin, $destination, $notes, $driver): TransferManifest {
            $this->assertActorAtCenter($actor, (int) $origin->id);
            $origin->loadMissing('address');
            $destination->loadMissing('address');
            if (! $origin->address || blank($origin->address->province_code) || blank($origin->address->municipality_code)
                || ! $destination->address || blank($destination->address->province_code) || blank($destination->address->municipality_code)) {
                throw new RuntimeException('A transfer manifest requires valid canonical municipality PSGC codes for both hubs.');
            }
            if ((int) $origin->id === (int) $destination->id) {
                throw new RuntimeException('A transfer manifest requires different origin and destination hubs.');
            }

            $manifest = TransferManifest::query()->create([
                'manifest_number' => $this->uniqueManifestNumber(),
                'origin_logistics_center_id' => $origin->id,
                'destination_logistics_center_id' => $destination->id,
                'status' => TransferManifest::DRAFT,
                'created_by_user_id' => $actor->id,
                'notes' => $notes,
            ]);

            return $driver
                ? $this->assignDriverWithinTransaction($actor, $manifest, $driver)
                : $manifest;
        });
    }

    /** @return Collection<int, RiderProfile> */
    public function eligibleDriversForCenter(int $centerId, ?int $manifestId = null): Collection
    {
        return RiderProfile::query()
            ->where('rider_profiles.logistics_center_id', $centerId)
            ->where('rider_profiles.status', 'ACTIVE')
            ->whereNotNull('rider_profiles.approved_by_user_id')
            ->whereNotNull('rider_profiles.approved_at')
            ->whereIn('rider_profiles.vehicle_type', \App\Support\RiderVehicleType::interLogisticsValues())
            ->whereNotNull('rider_profiles.approved_parcel_capacity')
            ->where('rider_profiles.approved_parcel_capacity', '>', 0)
            ->whereNotNull('rider_profiles.approved_weight_capacity_kg')
            ->where('rider_profiles.approved_weight_capacity_kg', '>', 0)
            ->whereNotNull('rider_profiles.approved_volume_capacity_liters')
            ->where('rider_profiles.approved_volume_capacity_liters', '>', 0)
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->whereNotExists(function ($query) use ($manifestId): void {
                $query->selectRaw('1')
                    ->from('transfer_manifests')
                    ->whereColumn('transfer_manifests.assigned_driver_rider_profile_id', 'rider_profiles.id')
                    ->whereNotIn('transfer_manifests.status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED]);
                if ($manifestId !== null) {
                    $query->where('transfer_manifests.id', '<>', $manifestId);
                }
            })
            ->with('user')
            ->orderBy('rider_profiles.id')
            ->get();
    }

    public function addLeg(User $actor, TransferManifest $manifest, ShipmentTransferLeg $leg): TransferManifestItem
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest, $leg): TransferManifestItem {
            $manifest = TransferManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $leg = ShipmentTransferLeg::query()->whereKey($leg->id)->with('shipment')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $manifest->origin_logistics_center_id);
            if ($manifest->status !== TransferManifest::DRAFT) {
                throw new RuntimeException('Only a draft manifest can be loaded.');
            }
            if ((int) $leg->origin_logistics_center_id !== (int) $manifest->origin_logistics_center_id
                || (int) $leg->destination_logistics_center_id !== (int) $manifest->destination_logistics_center_id) {
                throw new RuntimeException('The transfer leg endpoints do not match this manifest.');
            }
            if ($leg->status !== ShipmentTransferLeg::READY
                || (int) $leg->shipment?->logistics_center_id !== (int) $manifest->origin_logistics_center_id) {
                throw new RuntimeException('Only ready parcels in the origin hub custody may be loaded.');
            }

            $alreadyLoaded = TransferManifestItem::query()
                ->where('transfer_leg_id', $leg->id)
                ->whereHas('manifest', fn ($query) => $query->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED]))
                ->lockForUpdate()
                ->exists();
            if ($alreadyLoaded) {
                throw new RuntimeException('This transfer leg is already loaded on an active manifest.');
            }

            return TransferManifestItem::query()->create([
                'manifest_id' => $manifest->id,
                'transfer_leg_id' => $leg->id,
                'added_by_user_id' => $actor->id,
                'added_at' => now(),
                'status' => TransferManifestItem::EXPECTED,
            ]);
        });
    }

    public function recordItemMeasurements(
        User $actor,
        TransferManifest $manifest,
        TransferManifestItem $item,
        ?string $weightKg,
        ?string $volumeLiters,
    ): TransferManifestItem {
        $this->assertReady();
        $weight = filled($weightKg) ? $this->normalizeMeasurement($weightKg, 9999999.999) : null;
        $volume = filled($volumeLiters) ? $this->normalizeMeasurement($volumeLiters, 999999999.999) : null;
        if ($weight === null && $volume === null) {
            throw new RuntimeException('Record a parcel weight or volume, or skip this optional measurement.');
        }

        return DB::transaction(function () use ($actor, $manifest, $item, $weight, $volume): TransferManifestItem {
            $manifest = TransferManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $manifest->origin_logistics_center_id);
            if ($manifest->status !== TransferManifest::DRAFT) {
                throw new RuntimeException('Parcel measurements can only be recorded on a draft manifest.');
            }

            $item = TransferManifestItem::query()->whereKey($item->id)->where('manifest_id', $manifest->id)
                ->with('transferLeg')->lockForUpdate()->firstOrFail();
            $leg = $item->transferLeg;
            $shipment = $leg?->shipment()->lockForUpdate()->first();
            if ($item->status !== TransferManifestItem::EXPECTED || $leg?->status !== ShipmentTransferLeg::READY
                || (int) $shipment?->logistics_center_id !== (int) $manifest->origin_logistics_center_id) {
                throw new RuntimeException('Only a ready parcel in the origin hub may be measured.');
            }
            if (TransferManifestItem::query()
                ->whereHas('transferLeg', fn ($query) => $query->where('shipment_id', $shipment->id))
                ->whereHas('manifest', fn ($query) => $query->whereNotIn('status', [TransferManifest::DRAFT, TransferManifest::CANCELLED]))
                ->exists()) {
                throw new RuntimeException('Parcel measurements are locked after a transfer manifest is sealed.');
            }

            $beforeWeight = $shipment->parcel_weight_kg;
            $beforeVolume = $shipment->parcel_volume_liters;
            if (($weight === null || (string) $beforeWeight === $weight)
                && ($volume === null || (string) $beforeVolume === $volume)) {
                return $item;
            }
            $measurements = [];
            if ($weight !== null) {
                $measurements['parcel_weight_kg'] = $weight;
            }
            if ($volume !== null) {
                $measurements['parcel_volume_liters'] = $volume;
            }
            $shipment->update($measurements);

            if ($manifest->assigned_driver_rider_profile_id) {
                $manifest->load('items.transferLeg.shipment', 'assignedDriver.user');
                $this->assertManifestCapacity($manifest, null, true);
            }

            $weightNote = $weight === null ? 'weight unchanged' : 'measured packed weight '.$weight.' kg';
            $volumeNote = $volume === null ? 'volume unchanged' : 'volume '.$volume.' L';
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'status' => $shipment->current_status,
                'actor_user_id' => $actor->id,
                'logistics_center_id' => $manifest->origin_logistics_center_id,
                'notes' => 'Origin hub recorded '.$weightNote.' and '.$volumeNote.' for draft manifest '.$manifest->manifest_number.'.',
                'occurred_at' => now(),
            ]);

            return $item->refresh();
        });
    }

    private function normalizeMeasurement(string $value, float $maximum): string
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,3})?$/D', $value)
            || (float) $value <= 0 || (float) $value > $maximum) {
            throw new RuntimeException('Enter a positive measured value with at most three decimal places.');
        }

        return number_format((float) $value, 3, '.', '');
    }

    public function assignDriver(User $actor, TransferManifest $manifest, RiderProfile $driver): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest, $driver): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            return $this->assignDriverWithinTransaction($actor, $locked, $driver);
        });
    }

    public function seal(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg.shipment.orderItem.productVariant')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status !== TransferManifest::DRAFT) {
                throw new RuntimeException('Only a draft manifest can be sealed.');
            }
            $this->fillMissingParcelWeightsFromProduct($locked, $actor);
            $this->assertManifestIntegrity($locked);
            $locked->update([
                'status' => TransferManifest::SEALED,
                'sealed_by_user_id' => $actor->id,
                'sealed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /** @param array<int|string, string> $scannedCodes keyed by transfer manifest item ID */
    public function release(User $actor, TransferManifest $manifest, array $scannedCodes, array $scanMethods = []): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest, $scannedCodes, $scanMethods): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status === TransferManifest::RELEASED) {
                return $locked;
            }
            if ($locked->status !== TransferManifest::SEALED) {
                throw new RuntimeException('Only a sealed manifest can be released.');
            }
            $locked->loadMissing('assignedDriver.user', 'items.transferLeg.shipment');
            if (! $locked->driver_accepted_at
                || (int) $locked->driver_accepted_by_user_id !== (int) $locked->assignedDriver?->user_id) {
                throw new RuntimeException('The assigned transfer driver must accept the sealed manifest before Logistics can release it.');
            }
            $this->assertManifestCapacity($locked, $locked->assignedDriver);
            $this->assertScannedCodes($locked, $scannedCodes);
            foreach ($locked->items as $item) {
                $this->transfers->release(
                    $item->transferLeg,
                    $actor,
                    (string) $scannedCodes[$item->id],
                    (string) ($scanMethods[$item->id] ?? 'MANUAL'),
                );
            }
            $locked->update([
                'status' => TransferManifest::RELEASED,
                'released_by_user_id' => $actor->id,
                'released_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    public function releaseItem(User $actor, TransferManifest $manifest, TransferManifestItem $item, string $scannedCode, string $scanMethod = 'MANUAL'): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest, $item, $scannedCode, $scanMethod): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)
                ->with('assignedDriver.user', 'items.transferLeg.shipment')
                ->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);

            $item = TransferManifestItem::query()->whereKey($item->id)->where('manifest_id', $locked->id)
                ->with('transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $tracking = trim((string) $item->transferLeg?->shipment?->tracking_number);
            $code = trim($scannedCode);
            if ($tracking === '' || $code === '' || strtoupper($code) !== strtoupper($tracking)) {
                throw new RuntimeException('The scanned barcode does not match manifest parcel '.$tracking.'.');
            }
            if ($item->transferLeg?->status === ShipmentTransferLeg::RELEASED) {
                return $locked->refresh()->load('items.transferLeg');
            }
            if ($locked->status !== TransferManifest::SEALED || $item->status !== TransferManifestItem::EXPECTED
                || $item->transferLeg?->status !== ShipmentTransferLeg::READY) {
                throw new RuntimeException('Only an expected parcel on a sealed manifest can be released.');
            }
            if (! $locked->driver_accepted_at
                || (int) $locked->driver_accepted_by_user_id !== (int) $locked->assignedDriver?->user_id) {
                throw new RuntimeException('The assigned transfer driver must accept the sealed manifest before Logistics can release a parcel.');
            }

            $this->assertManifestCapacity($locked, $locked->assignedDriver);
            $this->transfers->release($item->transferLeg, $actor, $code, $scanMethod);
            $locked->load('items.transferLeg');
            if ($locked->items->isNotEmpty() && $locked->items->every(
                fn (TransferManifestItem $manifestItem): bool => $manifestItem->transferLeg?->status === ShipmentTransferLeg::RELEASED
            )) {
                $locked->update([
                    'status' => TransferManifest::RELEASED,
                    'released_by_user_id' => $actor->id,
                    'released_at' => now(),
                ]);
            }

            return $locked->refresh()->load('items.transferLeg');
        });
    }

    public function depart(User $actor, TransferManifest $manifest, array $scannedCodes, string $scanMethod = 'MANUAL'): TransferManifest
    {
        // Kept as a compatibility entry point; departure belongs to the
        // assigned rider and is enforced by driverStart().
        return $this->driverStart($actor, $manifest, $scannedCodes, $scanMethod);
    }

    public function driverAccept(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('assignedDriver.user')->lockForUpdate()->firstOrFail();
            $this->assertAssignedDriver($actor, $locked);
            if ($locked->status !== TransferManifest::SEALED) {
                throw new RuntimeException('Only a sealed transfer manifest can be accepted by its driver.');
            }
            if ($locked->driver_accepted_at) {
                return $locked;
            }
            $locked->update([
                'driver_accepted_by_user_id' => $actor->id,
                'driver_accepted_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    public function driverDecline(User $actor, TransferManifest $manifest, string $reason): TransferManifest
    {
        $this->assertReady();
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required when declining a transfer manifest.');
        }

        return DB::transaction(function () use ($actor, $manifest, $reason): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('assignedDriver.user')->lockForUpdate()->firstOrFail();
            $this->assertAssignedDriver($actor, $locked);
            if (! in_array($locked->status, [TransferManifest::DRAFT, TransferManifest::SEALED], true)
                || $locked->driver_accepted_at) {
                throw new RuntimeException('Only an unaccepted draft or sealed manifest can be declined.');
            }

            $declineNote = 'Assigned driver declined: '.$reason;
            $notes = trim(implode("\n", array_filter([(string) $locked->notes, $declineNote])));
            $locked->update([
                'status' => TransferManifest::DRAFT,
                'assigned_driver_rider_profile_id' => null,
                'driver_accepted_by_user_id' => null,
                'driver_accepted_at' => null,
                'sealed_by_user_id' => null,
                'sealed_at' => null,
                'notes' => $notes,
            ]);

            return $locked->refresh();
        });
    }

    public function driverStart(User $actor, TransferManifest $manifest, array $scannedCodes = [], string $scanMethod = 'MANUAL'): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest, $scannedCodes, $scanMethod): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('assignedDriver.user', 'items.transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $this->assertAssignedDriver($actor, $locked);
            if ($locked->status === TransferManifest::IN_TRANSIT) {
                return $locked;
            }
            if ($locked->status !== TransferManifest::RELEASED) {
                throw new RuntimeException('The origin logistics center must release the manifest before the driver starts transport.');
            }
            if (! $locked->driver_accepted_at) {
                throw new RuntimeException('The assigned driver must accept the manifest before starting transport.');
            }
            $this->assertManifestCapacity($locked, $locked->assignedDriver);
            if ($scannedCodes !== []) {
                $this->assertScannedCodes($locked, $scannedCodes);
            }
            if ($locked->items->isEmpty() || $locked->items->contains(
                fn (TransferManifestItem $item): bool => $item->status !== TransferManifestItem::EXPECTED
                    || $item->transferLeg?->status !== ShipmentTransferLeg::RELEASED,
            )) {
                throw new RuntimeException('Every parcel must be scanned and released by the origin hub before transport starts.');
            }

            foreach ($locked->items as $item) {
                $scannedCode = $scannedCodes[$item->id] ?? null;
                $this->transfers->depart(
                    $item->transferLeg,
                    $actor,
                    $scannedCode === null ? null : (string) $scannedCode,
                    $scanMethod,
                );
            }
            $locked->update(['status' => TransferManifest::IN_TRANSIT, 'departed_at' => now()]);

            return $locked->refresh();
        });
    }

    public function arrive(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->destination_logistics_center_id);
            if ($locked->status === TransferManifest::ARRIVED) {
                return $locked;
            }
            if ($locked->status !== TransferManifest::IN_TRANSIT) {
                throw new RuntimeException('Only an in-transit manifest can arrive.');
            }
            $locked->update(['status' => TransferManifest::ARRIVED, 'arrived_at' => now()]);

            return $locked->refresh();
        });
    }

    public function receiveItem(User $actor, TransferManifestItem $item, string $outcome, ?string $scannedCode = null, ?string $notes = null, string $scanMethod = 'MANUAL'): TransferManifestItem
    {
        $this->assertReady();
        $outcome = strtoupper(trim($outcome));
        if (! in_array($outcome, [TransferManifestItem::RECEIVED, TransferManifestItem::MISSING, TransferManifestItem::DAMAGED], true)) {
            throw new RuntimeException('Unsupported manifest receipt outcome.');
        }

        return DB::transaction(function () use ($actor, $item, $outcome, $scannedCode, $notes, $scanMethod): TransferManifestItem {
            $locked = TransferManifestItem::query()->whereKey($item->id)->with('manifest', 'transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $manifest = TransferManifest::query()->whereKey($locked->manifest_id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $manifest->destination_logistics_center_id);
            if (! in_array($manifest->status, [TransferManifest::IN_TRANSIT, TransferManifest::ARRIVED, TransferManifest::PARTIAL, TransferManifest::EXCEPTION], true)) {
                throw new RuntimeException('The manifest must be in transit or at the destination before parcels can be received.');
            }
            if ($locked->status !== TransferManifestItem::EXPECTED) {
                return $locked;
            }

            if ($manifest->status === TransferManifest::IN_TRANSIT) {
                $manifest->update(['status' => TransferManifest::ARRIVED, 'arrived_at' => now()]);
                $manifest->status = TransferManifest::ARRIVED;
            }

            if ($outcome !== TransferManifestItem::MISSING) {
                $this->transfers->receive(
                    $locked->transferLeg,
                    $actor,
                    (string) $scannedCode,
                    $scanMethod,
                    $outcome === TransferManifestItem::RECEIVED,
                );
            }
            if ($outcome === TransferManifestItem::RECEIVED) {
                $locked->update([
                    'status' => TransferManifestItem::RECEIVED,
                    'received_by_user_id' => $actor->id,
                    'received_at' => now(),
                ]);
            } else {
                $locked->update([
                    'status' => $outcome,
                    'received_by_user_id' => $actor->id,
                    'received_at' => now(),
                    'exception_type' => $outcome,
                    'exception_notes' => $notes,
                ]);
            }

            $statuses = $manifest->items()->pluck('status');
            $hasOutstanding = $statuses->contains(TransferManifestItem::EXPECTED);
            $hasException = $statuses->contains(fn (string $status): bool => in_array($status, [TransferManifestItem::MISSING, TransferManifestItem::DAMAGED], true));
            if (! $hasOutstanding && ! $hasException) {
                $manifest->update([
                    'status' => TransferManifest::RECEIVED,
                    'received_by_user_id' => $actor->id,
                    'received_at' => now(),
                ]);
            } elseif ($hasOutstanding && $statuses->contains(fn (string $status): bool => $status !== TransferManifestItem::EXPECTED)) {
                $manifest->update(['status' => TransferManifest::PARTIAL, 'exception_notes' => $notes ?: $manifest->exception_notes]);
            } elseif (! $hasOutstanding && $hasException) {
                $manifest->update(['status' => TransferManifest::EXCEPTION, 'exception_notes' => $notes ?: $manifest->exception_notes]);
            }

            return $locked->refresh();
        });
    }

    public function cancel(User $actor, TransferManifest $manifest, string $reason): TransferManifest
    {
        $this->assertReady();
        if (blank(trim($reason))) {
            throw new RuntimeException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($actor, $manifest, $reason): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if (! in_array($locked->status, [TransferManifest::DRAFT, TransferManifest::SEALED], true)) {
                throw new RuntimeException('Only draft or sealed manifests can be cancelled before release.');
            }
            if ($locked->items->contains(fn (TransferManifestItem $item): bool => $item->transferLeg?->status === ShipmentTransferLeg::RELEASED)) {
                throw new RuntimeException('This manifest has parcels already handed off to the driver and cannot be cancelled.');
            }
            foreach ($locked->items as $item) {
                if ($item->transferLeg && in_array($item->transferLeg->status, [ShipmentTransferLeg::PLANNED, ShipmentTransferLeg::READY], true)) {
                    $item->transferLeg->update(['status' => ShipmentTransferLeg::CANCELLED]);
                }
                $item->update([
                    'status' => TransferManifestItem::REJECTED,
                    'exception_type' => 'CANCELLED',
                    'exception_notes' => $reason,
                ]);
            }
            $locked->update([
                'status' => TransferManifest::CANCELLED,
                'cancelled_at' => now(),
                'exception_notes' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    private function assertManifestIntegrity(TransferManifest $manifest): void
    {
        if ($manifest->items->isEmpty()) {
            throw new RuntimeException('A manifest must contain at least one transfer leg.');
        }
        if (! $manifest->assigned_driver_rider_profile_id) {
            throw new RuntimeException('Assign an approved inter-logistics driver before sealing.');
        }
        foreach ($manifest->items as $item) {
            if ($item->transferLeg?->status !== ShipmentTransferLeg::READY
                || (int) $item->transferLeg?->origin_logistics_center_id !== (int) $manifest->origin_logistics_center_id
                || (int) $item->transferLeg?->destination_logistics_center_id !== (int) $manifest->destination_logistics_center_id
                || $item->status !== TransferManifestItem::EXPECTED
                || (int) $item->transferLeg?->shipment?->logistics_center_id !== (int) $manifest->origin_logistics_center_id) {
                throw new RuntimeException('Every manifest item must be an eligible ready parcel in origin custody with matching endpoints.');
            }
        }
        $driver = $manifest->assignedDriver ?: RiderProfile::query()->with('user')->findOrFail($manifest->assigned_driver_rider_profile_id);
        $this->assertManifestCapacity($manifest, $driver);
    }

    private function fillMissingParcelWeightsFromProduct(TransferManifest $manifest, User $actor): void
    {
        foreach ($manifest->items as $item) {
            $leg = $item->transferLeg;
            $shipment = $leg?->shipment;
            if (! $shipment) {
                continue;
            }

            $shipment = Shipment::query()
                ->whereKey($shipment->id)
                ->with('orderItem.productVariant')
                ->lockForUpdate()
                ->firstOrFail();
            $leg->setRelation('shipment', $shipment);

            $savedWeight = $shipment->parcel_weight_kg;
            if (is_numeric($savedWeight) && is_finite((float) $savedWeight) && (float) $savedWeight > 0) {
                continue;
            }

            $unitWeight = $shipment->orderItem?->productVariant?->weight_kg;
            $quantity = (int) ($shipment->orderItem?->quantity ?? 0);
            $estimate = is_numeric($unitWeight) && is_finite((float) $unitWeight) && (float) $unitWeight > 0 && $quantity > 0
                ? round((float) $unitWeight * $quantity, 3, PHP_ROUND_HALF_UP)
                : 0;
            if ($estimate <= 0 || $estimate > 9999999.999) {
                throw new RuntimeException('A parcel is missing its packed weight and its product has no usable per-item weight. Record a packed weight during seller preparation before sealing.');
            }

            $formattedEstimate = number_format($estimate, 3, '.', '');
            $shipment->update(['parcel_weight_kg' => $formattedEstimate]);
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'status' => $shipment->current_status,
                'actor_user_id' => $actor->id,
                'logistics_center_id' => $manifest->origin_logistics_center_id,
                'notes' => 'Origin hub estimated parcel weight as '.$unitWeight.' kg per item × '.$quantity.' items = '.$formattedEstimate.' kg for manifest '.$manifest->manifest_number.'. Packaging weight is not included.',
                'occurred_at' => now(),
            ]);
        }
    }

    private function assertManifestCapacity(TransferManifest $manifest, ?RiderProfile $driver = null, bool $allowMissingWeights = false): void
    {
        $driver ??= $manifest->assignedDriver ?: RiderProfile::query()->with('user')->findOrFail($manifest->assigned_driver_rider_profile_id);
        $this->assertEligibleDriver($driver, (int) $manifest->origin_logistics_center_id);
        $this->assertDriverAvailable($driver, (int) $manifest->id);
        $rows = $manifest->items->map(fn (TransferManifestItem $item): array => [
            'weight_kg' => $item->transferLeg?->shipment?->parcel_weight_kg,
            'volume_liters' => $item->transferLeg?->shipment?->parcel_volume_liters,
        ]);
        $this->capacity->assertCapacity($driver, $rows, $allowMissingWeights);
    }

    private function assignDriverWithinTransaction(User $actor, TransferManifest $manifest, RiderProfile $requestedDriver): TransferManifest
    {
        $this->assertActorAtCenter($actor, (int) $manifest->origin_logistics_center_id);
        if ($manifest->status !== TransferManifest::DRAFT) {
            throw new RuntimeException('The driver can only be assigned while the manifest is a draft.');
        }

        $driver = RiderProfile::query()->with('user')->lockForUpdate()->findOrFail($requestedDriver->id);
        $this->assertEligibleDriver($driver, (int) $manifest->origin_logistics_center_id);
        $this->assertDriverAvailable($driver, (int) $manifest->id);

        $manifest->load('items.transferLeg.shipment');
        $manifest->setRelation('assignedDriver', $driver);
        $this->assertManifestCapacity($manifest, $driver, true);
        $manifest->update(['assigned_driver_rider_profile_id' => $driver->id]);

        return $manifest->refresh()->load('assignedDriver.user');
    }

    private function assertEligibleDriver(RiderProfile $driver, int $originCenterId): void
    {
        if ((int) $driver->logistics_center_id !== $originCenterId
            || $driver->status !== 'ACTIVE'
            || ! $driver->approved_by_user_id
            || ! $driver->approved_at
            || $driver->user?->account_type !== User::TYPE_RIDER
            || $driver->user?->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('The driver must be approved, active, and authorized by the origin hub.');
        }
        if (! \App\Support\RiderVehicleType::isInterLogistics($driver->vehicle_type)) {
            throw new RuntimeException('Only an approved four-wheel inter-hub vehicle can be assigned to an inter-hub manifest.');
        }
    }

    private function assertDriverAvailable(RiderProfile $driver, int $manifestId): void
    {
        $conflict = TransferManifest::query()
            ->where('assigned_driver_rider_profile_id', $driver->id)
            ->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])
            ->whereKeyNot($manifestId)
            ->exists();
        if ($conflict) {
            throw new RuntimeException('The driver already has an active transfer manifest and is unavailable.');
        }
    }

    /** @param array<int|string, string> $scannedCodes */
    private function assertScannedCodes(TransferManifest $manifest, array $scannedCodes): void
    {
        if ($manifest->items->isEmpty() || count($scannedCodes) !== $manifest->items->count()) {
            throw new RuntimeException('Scan every manifest parcel before custody changes.');
        }

        foreach ($manifest->items as $item) {
            $code = trim((string) ($scannedCodes[$item->id] ?? ''));
            $tracking = trim((string) $item->transferLeg?->shipment?->tracking_number);
            if ($code === '' || $tracking === '' || strtoupper($code) !== strtoupper($tracking)) {
                throw new RuntimeException('The scanned barcode does not match manifest parcel '.$tracking.'.');
            }
        }
    }

    private function assertActorAtCenter(User $actor, int $centerId): void
    {
        if ($actor->account_type !== User::TYPE_LOGISTICS
            || $actor->status !== User::STATUS_ACTIVE
            || (int) $actor->logisticsCenter?->id !== $centerId) {
            throw new RuntimeException('The authenticated user is not authorized for this logistics center.');
        }
    }

    private function assertAssignedDriver(User $actor, TransferManifest $manifest): void
    {
        $actor->loadMissing('riderProfile');
        $driver = $manifest->assignedDriver;
        if (! $driver || (int) $driver->user_id !== (int) $actor->id) {
            throw new RuntimeException('Only the assigned active inter-logistics driver can operate this manifest.');
        }

        $this->assertEligibleDriver($driver, (int) $manifest->origin_logistics_center_id);
    }

    private function assertReady(): void
    {
        if (! $this->schema->manifestReady()) {
            throw new RuntimeException('Transfer manifest schema or approved capacity fields are not installed.');
        }
    }

    private function uniqueManifestNumber(): string
    {
        do {
            $number = 'MF-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (TransferManifest::query()->where('manifest_number', $number)->exists());

        return $number;
    }
}
