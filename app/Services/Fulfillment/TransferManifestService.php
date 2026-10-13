<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class TransferManifestService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly ShipmentTransferService $transfers,
        private readonly TransferCapacityService $capacity,
    ) {}

    public function create(User $actor, LogisticsCenter $origin, LogisticsCenter $destination, ?string $notes = null): TransferManifest
    {
        $this->assertReady();
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

        return TransferManifest::query()->create([
            'manifest_number' => $this->uniqueManifestNumber(),
            'origin_logistics_center_id' => $origin->id,
            'destination_logistics_center_id' => $destination->id,
            'status' => TransferManifest::DRAFT,
            'created_by_user_id' => $actor->id,
            'notes' => $notes,
        ]);
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

    public function assignDriver(User $actor, TransferManifest $manifest, RiderProfile $driver): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest, $driver): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status !== TransferManifest::DRAFT) {
                throw new RuntimeException('The driver can only be assigned while the manifest is a draft.');
            }

            $driver = RiderProfile::query()->with('user')->lockForUpdate()->findOrFail($driver->id);
            if ((int) $driver->logistics_center_id !== (int) $locked->origin_logistics_center_id
                || $driver->status !== 'ACTIVE'
                || $driver->user?->account_type !== 'RIDER') {
                throw new RuntimeException('The driver is not an active rider authorized by the origin hub.');
            }
            if (! \App\Support\RiderVehicleType::isInterLogistics($driver->vehicle_type)) {
                throw new RuntimeException('Local motorcycle/tricycle riders cannot be assigned to inter-hub manifests.');
            }
            $conflict = TransferManifest::query()
                ->where('assigned_driver_rider_profile_id', $driver->id)
                ->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])
                ->whereKeyNot($locked->id)
                ->exists();
            if ($conflict) {
                throw new RuntimeException('The driver already has an active transfer manifest.');
            }

            $locked->setRelation('assignedDriver', $driver);
            $this->assertManifestCapacity($locked);

            $locked->update(['assigned_driver_rider_profile_id' => $driver->id]);

            return $locked->refresh();
        });
    }

    public function seal(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status !== TransferManifest::DRAFT) {
                throw new RuntimeException('Only a draft manifest can be sealed.');
            }
            $this->assertManifestIntegrity($locked);
            $locked->update([
                'status' => TransferManifest::SEALED,
                'sealed_by_user_id' => $actor->id,
                'sealed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    public function release(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status === TransferManifest::RELEASED) {
                return $locked;
            }
            if ($locked->status !== TransferManifest::SEALED) {
                throw new RuntimeException('Only a sealed manifest can be released.');
            }
            foreach ($locked->items as $item) {
                $this->transfers->release($item->transferLeg, $actor, $item->transferLeg->shipment?->tracking_number ?? '');
            }
            $locked->update([
                'status' => TransferManifest::RELEASED,
                'released_by_user_id' => $actor->id,
                'released_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    public function depart(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
            $locked = TransferManifest::query()->whereKey($manifest->id)->with('items.transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status === TransferManifest::IN_TRANSIT) {
                return $locked;
            }
            if ($locked->status !== TransferManifest::RELEASED) {
                throw new RuntimeException('Only a released manifest can depart.');
            }
            foreach ($locked->items as $item) {
                $this->transfers->depart($item->transferLeg, $actor, $item->transferLeg->shipment?->tracking_number ?? '');
            }
            $locked->update(['status' => TransferManifest::IN_TRANSIT, 'departed_at' => now()]);

            return $locked->refresh();
        });
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

    public function driverStart(User $actor, TransferManifest $manifest): TransferManifest
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $manifest): TransferManifest {
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
            foreach ($locked->items as $item) {
                $this->transfers->depart($item->transferLeg, $actor, $item->transferLeg->shipment?->tracking_number ?? '');
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
            foreach ($locked->items as $item) {
                $this->transfers->recordArrival($item->transferLeg, $actor, $item->transferLeg->shipment?->tracking_number ?? '');
            }
            $locked->update(['status' => TransferManifest::ARRIVED, 'arrived_at' => now()]);

            return $locked->refresh();
        });
    }

    public function receiveItem(User $actor, TransferManifestItem $item, string $outcome, ?string $notes = null): TransferManifestItem
    {
        $this->assertReady();
        $outcome = strtoupper(trim($outcome));
        if (! in_array($outcome, [TransferManifestItem::RECEIVED, TransferManifestItem::MISSING, TransferManifestItem::DAMAGED], true)) {
            throw new RuntimeException('Unsupported manifest receipt outcome.');
        }

        return DB::transaction(function () use ($actor, $item, $outcome, $notes): TransferManifestItem {
            $locked = TransferManifestItem::query()->whereKey($item->id)->with('manifest', 'transferLeg.shipment')->lockForUpdate()->firstOrFail();
            $manifest = TransferManifest::query()->whereKey($locked->manifest_id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $manifest->destination_logistics_center_id);
            if (! in_array($manifest->status, [TransferManifest::IN_TRANSIT, TransferManifest::ARRIVED, TransferManifest::PARTIAL, TransferManifest::EXCEPTION], true)) {
                throw new RuntimeException('The manifest is not ready for receipt.');
            }
            if ($locked->status !== TransferManifestItem::EXPECTED) {
                return $locked;
            }

            if ($outcome === TransferManifestItem::RECEIVED) {
                $this->transfers->receive($locked->transferLeg, $actor, $locked->transferLeg->shipment?->tracking_number ?? '');
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
            if ($statuses->every(fn (string $status): bool => $status === TransferManifestItem::RECEIVED)) {
                $manifest->update([
                    'status' => TransferManifest::RECEIVED,
                    'received_by_user_id' => $actor->id,
                    'received_at' => now(),
                ]);
            } elseif ($statuses->contains(fn (string $status): bool => in_array($status, [TransferManifestItem::MISSING, TransferManifestItem::DAMAGED], true))) {
                $manifest->update(['status' => TransferManifest::PARTIAL, 'exception_notes' => $notes ?: $manifest->exception_notes]);
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
                || (int) $item->transferLeg?->destination_logistics_center_id !== (int) $manifest->destination_logistics_center_id) {
                throw new RuntimeException('Every manifest item must be a ready leg with matching endpoints.');
            }
        }
        $driver = $manifest->assignedDriver ?: RiderProfile::query()->with('user')->findOrFail($manifest->assigned_driver_rider_profile_id);
        $this->assertManifestCapacity($manifest, $driver);
    }

    private function assertManifestCapacity(TransferManifest $manifest, ?RiderProfile $driver = null): void
    {
        $driver ??= $manifest->assignedDriver ?: RiderProfile::query()->with('user')->findOrFail($manifest->assigned_driver_rider_profile_id);
        $rows = $manifest->items->map(fn (TransferManifestItem $item): array => [
            'weight_kg' => $item->transferLeg?->shipment?->parcel_weight_kg,
            'volume_liters' => $item->transferLeg?->shipment?->parcel_volume_liters,
        ]);
        $this->capacity->assertCapacity($driver, $rows);
    }

    private function assertActorAtCenter(User $actor, int $centerId): void
    {
        if ((int) $actor->logisticsCenter?->id !== $centerId) {
            throw new RuntimeException('The authenticated user is not authorized for this logistics center.');
        }
    }

    private function assertAssignedDriver(User $actor, TransferManifest $manifest): void
    {
        $actor->loadMissing('riderProfile');
        $driver = $manifest->assignedDriver;
        if (! $driver || (int) $driver->user_id !== (int) $actor->id
            || $driver->status !== 'ACTIVE'
            || $actor->account_type !== User::TYPE_RIDER
            || $actor->status !== User::STATUS_ACTIVE
            || ! \App\Support\RiderVehicleType::isInterLogistics($driver->vehicle_type)) {
            throw new RuntimeException('Only the assigned active inter-logistics driver can operate this manifest.');
        }
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
