<?php

namespace App\Services\Fulfillment;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Buyer\Address;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\LogisticsCoverageRoute;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PsgcLogisticsRoutingService
{
    public function __construct(private readonly LogisticsSchemaReadiness $schema) {}

    /**
     * Resolve one seller parcel. Primary exact PSGC routing remains the first
     * choice and keeps its existing ambiguity behavior. The optional fallback
     * context is used only during an explicit checkout quote/transaction.
     *
     * @param array{lock_capacity?:bool,required_parcels?:int,planned_route_reservations?:array<int,array<string,int>>} $options
     * @return array<string, mixed>
     */
    public function resolve(SellerProfile $seller, Address|OrderAddress $destination, array $options = []): array
    {
        $sellerAddress = $seller->businessAddress;
        $this->assertCompleteAddress($sellerAddress, 'seller pickup');
        $this->assertCompleteAddress($destination, 'buyer delivery');
        $this->assertBarangayBelongsToMunicipality($sellerAddress->municipality_code, $sellerAddress->barangay_code, 'seller pickup');
        $this->assertBarangayBelongsToMunicipality($destination->municipality_code, $destination->barangay_code, 'buyer delivery');

        $origin = $this->uniqueCenterForMunicipality(
            (string) $sellerAddress->province_code,
            (string) $sellerAddress->municipality_code,
            'seller pickup',
            $sellerAddress,
        );
        $originCoverage = $this->coverageForAddress($origin, $sellerAddress, 'seller pickup');

        try {
            $destinationCenter = $this->uniqueCenterForMunicipality(
                (string) $destination->province_code,
                (string) $destination->municipality_code,
                'buyer delivery',
                $destination,
            );
            $destinationCoverage = $this->coverageForAddress($destinationCenter, $destination, 'buyer delivery');

            return $this->primaryDecision($origin, $destinationCenter, $destinationCoverage, $originCoverage);
        } catch (ValidationException $exception) {
            if (! $this->schema->smartFallbackEnabled() || ! $this->mayUseExplicitFallback($exception)) {
                throw $exception;
            }

            return $this->resolveApprovedFallback($origin, $destination, $originCoverage, $options);
        }
    }

    /**
     * Build complete shipment plans from an exact-PSGC-authorized pickup hub.
     * Center proximity is considered by the caller only after this method has
     * proven pickup and destination coverage.
     *
     * @param array{lock_capacity?:bool,required_parcels?:int,planned_route_reservations?:array<int,array<string,int>>,exclude_shipment_ids?:list<int>} $options
     * @return list<array<string,mixed>>
     */
    public function resolveForAutomaticPickup(
        SellerProfile $seller,
        Address $pickupAddress,
        Address|OrderAddress $destination,
        LogisticsCenter $origin,
        array $options = [],
    ): array {
        $this->assertCompleteAddress($pickupAddress, 'seller pickup');
        $this->assertCompleteAddress($destination, 'buyer delivery');
        if ((int) $pickupAddress->user_id !== (int) $seller->user_id) {
            throw ValidationException::withMessages(['address_id' => 'The pickup address does not belong to this Seller.']);
        }
        $this->assertBarangayBelongsToMunicipality((string) $pickupAddress->municipality_code, (string) $pickupAddress->barangay_code, 'seller pickup');
        $this->assertBarangayBelongsToMunicipality((string) $destination->municipality_code, (string) $destination->barangay_code, 'buyer delivery');

        $origin = LogisticsCenter::query()->approvedActive()->whereKey($origin->id)->with('address')->first();
        if (! $origin) {
            throw ValidationException::withMessages(['address_id' => 'The pickup hub is no longer active and approved.']);
        }
        $originCoverage = $this->coverageForAddress($origin, $pickupAddress, 'seller pickup', allowAuthorizedExtended: true);

        $destinationLocations = ServiceAreaLocation::query()
            ->where('province_code', trim((string) $destination->province_code))
            ->where('municipality_code', trim((string) $destination->municipality_code))
            ->where('barangay_code', trim((string) $destination->barangay_code))
            ->when($this->schema->coverageTypeReady(), fn ($query) => $query->where('coverage_type', 'PRIMARY'))
            ->whereHas('serviceArea', fn ($area) => $area->where('is_active', true)
                ->whereHas('logisticsCenter', fn ($center) => $center->approvedActive()))
            ->with('serviceArea.logisticsCenter.address')
            ->orderBy('id')
            ->get()
            ->unique(fn (ServiceAreaLocation $location): string => (string) $location->serviceArea?->logistics_center_id)
            ->values();

        $plans = [];
        foreach ($destinationLocations as $location) {
            $destinationCenter = $location->serviceArea?->logisticsCenter;
            if (! $destinationCenter) {
                continue;
            }
            if ((int) $destinationCenter->id !== (int) $origin->id && ! $this->schema->manifestReady()) {
                continue;
            }
            $plans[] = $this->primaryDecision($origin, $destinationCenter, $location, $originCoverage);
        }

        if ($plans !== []) {
            return $plans;
        }

        if ($this->schema->smartCoverageReady()) {
            try {
                return [$this->resolveApprovedFallback($origin, $destination, $originCoverage, $options)];
            } catch (ValidationException $exception) {
                throw $exception;
            }
        }

        throw ValidationException::withMessages(['address_id' => 'No complete authorized destination route currently serves the Buyer PSGC.']);
    }

    /** Resolve an exact route after an authorized administrator selects both hubs. */
    public function resolveWithCenters(
        SellerProfile $seller,
        Address|OrderAddress $destination,
        int $originCenterId,
        int $destinationCenterId,
        ?int $coverageRouteId = null,
        ?string $serviceDate = null,
    ): array {
        $sellerAddress = $seller->businessAddress;
        $this->assertCompleteAddress($sellerAddress, 'seller pickup');
        $this->assertCompleteAddress($destination, 'buyer delivery');
        $this->assertBarangayBelongsToMunicipality($sellerAddress->municipality_code, $sellerAddress->barangay_code, 'seller pickup');
        $this->assertBarangayBelongsToMunicipality($destination->municipality_code, $destination->barangay_code, 'buyer delivery');

        $origin = $this->selectedCenterForMunicipality($originCenterId, $sellerAddress, 'seller pickup');
        if ($coverageRouteId !== null) {
            $decision = $this->revalidateStoredFallback($seller, $destination, $coverageRouteId, lock: true, serviceDate: $serviceDate);
            if ((int) $decision['origin']->id !== $originCenterId
                || (int) $decision['destination']->id !== $destinationCenterId) {
                throw ValidationException::withMessages([
                    'destination_logistics_center_id' => 'The selected approved coverage route does not connect these hubs.',
                ]);
            }

            return $decision;
        }

        $destinationCenter = $this->selectedCenterForMunicipality($destinationCenterId, $destination, 'buyer delivery');

        return $this->primaryDecision(
            $origin,
            $destinationCenter,
            $this->coverageForAddress($destinationCenter, $destination, 'buyer delivery'),
            $this->coverageForAddress($origin, $sellerAddress, 'seller pickup'),
        );
    }

    /** Revalidate a stored route without changing the shipment's selected hubs. */
    public function revalidateStoredFallback(
        SellerProfile $seller,
        Address|OrderAddress $destination,
        int $routeId,
        bool $lock = false,
        ?string $serviceDate = null,
    ): array {
        $sellerAddress = $seller->businessAddress;
        $this->assertCompleteAddress($sellerAddress, 'seller pickup');
        $this->assertCompleteAddress($destination, 'buyer delivery');
        $this->assertBarangayBelongsToMunicipality($sellerAddress->municipality_code, $sellerAddress->barangay_code, 'seller pickup');
        $this->assertBarangayBelongsToMunicipality($destination->municipality_code, $destination->barangay_code, 'buyer delivery');

        $origin = $this->uniqueCenterForMunicipality(
            (string) $sellerAddress->province_code,
            (string) $sellerAddress->municipality_code,
            'seller pickup',
            $sellerAddress,
        );

        try {
            return $this->approvedFallbackById(
                $routeId,
                $origin,
                $destination,
                $this->coverageForAddress($origin, $sellerAddress, 'seller pickup'),
                $lock,
                $serviceDate,
            );
        } catch (ValidationException $exception) {
            Log::warning('Stored smart logistics fallback route failed revalidation.', [
                'coverage_route_id' => $routeId,
                'origin_hub_id' => (int) $origin->id,
                'failure_code' => 'STORED_ROUTE_INELIGIBLE',
            ]);
            throw $exception;
        }
    }

    private function resolveApprovedFallback(
        LogisticsCenter $origin,
        Address|OrderAddress $destination,
        ServiceAreaLocation $originCoverage,
        array $options,
    ): array {
        if (! $this->schema->smartCoverageReady()) {
            throw ValidationException::withMessages(['address_id' => 'No approved delivery route currently serves this destination.']);
        }

        $query = LogisticsCoverageRoute::query()
            ->approvedActive()
            ->currentlyValid()
            ->where('origin_logistics_center_id', $origin->id)
            ->where('shipment_type', LogisticsCoverageRoute::SHIPMENT_MARKETPLACE)
            ->whereIn('coverage_type', [LogisticsCoverageRoute::TYPE_SECONDARY, LogisticsCoverageRoute::TYPE_EXTENDED])
            ->whereHas('approvedBy', fn ($admin) => $admin
                ->where('account_type', User::TYPE_ADMIN)
                ->where('status', User::STATUS_ACTIVE))
            ->whereHas('originLogisticsCenter', fn ($center) => $center->approvedActive())
            ->whereHas('destinationLocation', function ($location) use ($destination): void {
                $location->where('province_code', trim((string) $destination->province_code))
                    ->where('municipality_code', trim((string) $destination->municipality_code))
                    ->where('barangay_code', trim((string) $destination->barangay_code))
                    ->whereIn('coverage_type', [LogisticsCoverageRoute::TYPE_SECONDARY, LogisticsCoverageRoute::TYPE_EXTENDED])
                    ->whereHas('serviceArea', fn ($area) => $area->where('is_active', true)
                        ->whereHas('logisticsCenter', fn ($center) => $center->approvedActive()));
            })
            ->with(['originLogisticsCenter', 'destinationLocation.serviceArea.logisticsCenter', 'approvedBy'])
            ->orderBy('estimated_sla_hours')
            ->orderBy('id');

        if (($options['lock_capacity'] ?? false) === true) {
            $query->lockForUpdate();
        }

        $requiredParcels = max(1, (int) ($options['required_parcels'] ?? 1));
        $plannedReservations = (array) ($options['planned_route_reservations'] ?? []);
        $candidates = [];

        foreach ($query->get() as $route) {
            if (! $this->routeConfigurationIsComplete($route)) {
                continue;
            }
            $slots = $this->dispatchSlotsWithinWindow($route);
            if ($slots === []) {
                continue;
            }
            $firstDate = $slots[0]['service_date']->toDateString();
            $lastDate = $slots[count($slots) - 1]['service_date']->toDateString();
            $bookingQuery = Shipment::query()
                ->where('smart_coverage_route_id', $route->id)
                ->whereBetween('smart_coverage_service_date', [$firstDate, $lastDate])
                ->where('current_status', '!=', 'CANCELLED');
            $excludeShipmentIds = array_values(array_filter(array_map('intval', (array) ($options['exclude_shipment_ids'] ?? []))));
            if ($excludeShipmentIds !== []) {
                $bookingQuery->whereNotIn('id', $excludeShipmentIds);
            }
            if (($options['lock_capacity'] ?? false) === true) {
                $bookingQuery->lockForUpdate();
            }
            $bookingsByDate = $bookingQuery->get(['smart_coverage_service_date'])
                ->countBy(fn (Shipment $shipment): string => $shipment->smart_coverage_service_date?->toDateString() ?? '');
            $selectedSlot = null;
            $remaining = 0;
            foreach ($slots as $slot) {
                $serviceDate = $slot['service_date']->toDateString();
                $booked = (int) $bookingsByDate->get($serviceDate, 0);
                $planned = (int) ($plannedReservations[$route->id][$serviceDate] ?? 0);
                $slotRemaining = (int) $route->max_parcels_per_day - $booked - $planned;
                if ($slotRemaining >= $requiredParcels) {
                    $selectedSlot = $slot;
                    $remaining = $slotRemaining;
                    break;
                }
            }
            if ($selectedSlot === null) {
                continue;
            }

            $location = $route->destinationLocation;
            $area = $location?->serviceArea;
            $hub = $area?->logisticsCenter;
            if (! $location || ! $area || ! $hub) {
                continue;
            }

            $candidates[] = [
                'route' => $route,
                'destination' => $hub,
                'destination_area' => $location,
                'origin' => $origin,
                'origin_coverage' => $originCoverage,
                'slot' => $selectedSlot,
                'remaining_capacity' => $remaining,
            ];
        }

        if ($candidates === []) {
            Log::info('Smart logistics fallback found no eligible scheduled capacity.', [
                'origin_hub_id' => (int) $origin->id,
                'failure_code' => 'NO_FEASIBLE_APPROVED_ROUTE',
            ]);
            throw ValidationException::withMessages([
                'address_id' => 'No approved secondary or extended delivery route has a feasible dispatch schedule and remaining capacity for this destination.',
            ]);
        }

        // The existing shipping-rate engine prices from seller and buyer
        // regions, so each candidate has the same established fee. There is
        // no trusted road-distance source in this app; use configured SLA,
        // remaining capacity, then stable IDs for deterministic selection.
        usort($candidates, fn (array $left, array $right): int =>
            ($left['route']->estimated_sla_hours <=> $right['route']->estimated_sla_hours)
            ?: ($right['remaining_capacity'] <=> $left['remaining_capacity'])
            ?: ($left['destination']->id <=> $right['destination']->id)
            ?: ($left['route']->id <=> $right['route']->id));

        $selected = $candidates[0];
        $route = $selected['route'];
        $destinationHub = $selected['destination'];
        $location = $selected['destination_area'];
        $area = $location->serviceArea;

        return [
            'origin' => $origin,
            'destination' => $destinationHub,
            'destination_area' => $location,
            'origin_coverage' => $originCoverage,
            'coverage_type' => $route->coverage_type,
            'coverage_route' => $route,
            'service_date' => $selected['slot']['service_date'],
            'scheduled_dispatch_at' => $selected['slot']['dispatch_at'],
            'estimated_sla_hours' => (int) $route->estimated_sla_hours,
            'estimated_delivery_at' => $selected['slot']['delivery_at'],
            'capacity_remaining_at_quote' => $selected['remaining_capacity'],
            'eligibility_version' => $this->eligibilityVersion($route, $location, $area, $destinationHub),
        ];
    }

    private function approvedFallbackById(
        int $routeId,
        LogisticsCenter $origin,
        Address|OrderAddress $destination,
        ?ServiceAreaLocation $originCoverage = null,
        bool $lock = false,
        ?string $serviceDate = null,
    ): array {
        if (! $this->schema->smartCoverageReady()) {
            throw ValidationException::withMessages(['address_id' => 'The approved fallback route configuration is not installed.']);
        }

        $query = LogisticsCoverageRoute::query()
            ->approvedActive()
            ->currentlyValid()
            ->whereKey($routeId)
            ->where('origin_logistics_center_id', $origin->id)
            ->where('shipment_type', LogisticsCoverageRoute::SHIPMENT_MARKETPLACE)
            ->whereIn('coverage_type', [LogisticsCoverageRoute::TYPE_SECONDARY, LogisticsCoverageRoute::TYPE_EXTENDED])
            ->whereHas('approvedBy', fn ($admin) => $admin->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE))
            ->whereHas('destinationLocation', function ($location) use ($destination): void {
                $location->where('province_code', trim((string) $destination->province_code))
                    ->where('municipality_code', trim((string) $destination->municipality_code))
                    ->where('barangay_code', trim((string) $destination->barangay_code))
                    ->whereIn('coverage_type', [LogisticsCoverageRoute::TYPE_SECONDARY, LogisticsCoverageRoute::TYPE_EXTENDED])
                    ->whereHas('serviceArea', fn ($area) => $area->where('is_active', true)
                        ->whereHas('logisticsCenter', fn ($center) => $center->approvedActive()));
            })
            ->with(['originLogisticsCenter', 'destinationLocation.serviceArea.logisticsCenter', 'approvedBy']);
        if ($lock) {
            $query->lockForUpdate();
        }

        $route = $query->first();
        if (! $route || ! $this->routeConfigurationIsComplete($route)) {
            throw ValidationException::withMessages(['address_id' => 'The approved fallback route is no longer active or eligible for this destination.']);
        }

        $location = $route->destinationLocation;
        $area = $location?->serviceArea;
        $destinationHub = $area?->logisticsCenter;
        if (! $location || ! $area || ! $destinationHub) {
            throw ValidationException::withMessages(['address_id' => 'The approved fallback destination coverage is no longer active.']);
        }

        $slot = $serviceDate !== null
            ? $this->dispatchSlotOnDate($route, $serviceDate)
            : $this->nextAvailableDispatchSlot($route);
        if ($serviceDate !== null && $slot === null) {
            throw ValidationException::withMessages(['address_id' => 'The stored route no longer accepts the parcel’s scheduled service date.']);
        }
        if ($serviceDate === null && $slot === null) {
            throw ValidationException::withMessages(['address_id' => 'The approved route has no scheduled date with remaining parcel capacity.']);
        }

        return [
            'origin' => $origin,
            'destination' => $destinationHub,
            'destination_area' => $location,
            'origin_coverage' => $originCoverage,
            'coverage_type' => $route->coverage_type,
            'coverage_route' => $route,
            'service_date' => $slot['service_date'] ?? null,
            'scheduled_dispatch_at' => $slot['dispatch_at'] ?? null,
            'estimated_sla_hours' => (int) $route->estimated_sla_hours,
            'estimated_delivery_at' => $slot['delivery_at'] ?? null,
            'capacity_remaining_at_quote' => null,
            'eligibility_version' => $this->eligibilityVersion($route, $location, $area, $destinationHub),
        ];
    }

    private function uniqueCenterForMunicipality(string $provinceCode, string $municipalityCode, string $purpose, Address|OrderAddress $coverageAddress): LogisticsCenter
    {
        $centers = LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('serviceAreas', function ($query) use ($coverageAddress): void {
                $query->where('is_active', true)
                    ->whereHas('locations', function ($locationQuery) use ($coverageAddress): void {
                        $locationQuery->where('province_code', trim((string) $coverageAddress->province_code))
                            ->where('municipality_code', trim((string) $coverageAddress->municipality_code))
                            ->where('barangay_code', trim((string) $coverageAddress->barangay_code));
                        if ($this->schema->coverageTypeReady()) {
                            $locationQuery->where('coverage_type', 'PRIMARY');
                        }
                    });
            })
            ->orderBy('id')
            ->get();

        if ($centers->isEmpty()) {
            throw ValidationException::withMessages([
                'address_id' => "No active logistics hub is authorized for the {$purpose} barangay PSGC.",
            ]);
        }

        if ($centers->count() !== 1) {
            throw ValidationException::withMessages([
                'address_id' => "Routing is ambiguous: multiple active logistics hubs match the {$purpose} municipality PSGC.",
            ]);
        }

        return $centers->first();
    }

    private function selectedCenterForMunicipality(int $centerId, Address|OrderAddress $address, string $purpose): LogisticsCenter
    {
        $center = LogisticsCenter::query()->approvedActive()->whereKey($centerId)->first();

        if (! $center) {
            throw ValidationException::withMessages([
                'address_id' => "The selected {$purpose} hub is not active or approved for this exact PSGC address.",
            ]);
        }

        return $center;
    }

    private function coverageForAddress(
        LogisticsCenter $center,
        Address|OrderAddress $address,
        string $purpose,
        bool $allowAuthorizedExtended = false,
    ): ServiceAreaLocation
    {
        $locations = ServiceAreaLocation::query()
            ->where('province_code', trim((string) $address->province_code))
            ->where('municipality_code', trim((string) $address->municipality_code))
            ->where('barangay_code', trim((string) $address->barangay_code))
            ->when($this->schema->coverageTypeReady() && ! $allowAuthorizedExtended, fn ($query) => $query->where('coverage_type', 'PRIMARY'))
            ->whereHas('serviceArea', fn ($query) => $query
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true))
            ->with('serviceArea')
            ->get();

        if ($allowAuthorizedExtended) {
            $locations = $locations->filter(fn (ServiceAreaLocation $location): bool =>
                app(LocalRiderEligibilityService::class)->centerAuthorizesExactLocation($center, $location));
        }

        if ($locations->isEmpty()) {
            throw ValidationException::withMessages([
                'address_id' => "The {$purpose} barangay PSGC is not covered by its authorized logistics hub.",
            ]);
        }

        if ($locations->count() !== 1) {
            throw ValidationException::withMessages([
                'address_id' => "Routing is ambiguous for the {$purpose} barangay PSGC.",
            ]);
        }

        return $locations->first();
    }

    private function routeConfigurationIsComplete(LogisticsCoverageRoute $route): bool
    {
        $weekdays = collect((array) $route->dispatch_weekdays)
            ->map(fn ($day): int => is_numeric($day) ? (int) $day : -1)
            ->unique()
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6);

        return $route->shipment_type === LogisticsCoverageRoute::SHIPMENT_MARKETPLACE
            && in_array($route->coverage_type, [LogisticsCoverageRoute::TYPE_SECONDARY, LogisticsCoverageRoute::TYPE_EXTENDED], true)
            && (int) $route->max_parcels_per_day > 0
            && (int) $route->estimated_sla_hours > 0
            && (int) $route->minimum_pickup_lead_hours > 0
            && $weekdays->isNotEmpty()
            && filled($route->dispatch_cutoff_time)
            && $route->valid_from !== null
            && $route->valid_until !== null
            && $route->valid_until->greaterThan($route->valid_from);
    }

    /** @return list<array{service_date: Carbon, dispatch_at: Carbon, delivery_at: Carbon}> */
    private function dispatchSlotsWithinWindow(LogisticsCoverageRoute $route): array
    {
        $weekdays = collect((array) $route->dispatch_weekdays)
            ->map(fn ($day): int => is_numeric($day) ? (int) $day : -1)
            ->unique()
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->all();
        if ($weekdays === [] || ! filled($route->dispatch_cutoff_time)) {
            return [];
        }

        $cutoff = substr((string) $route->dispatch_cutoff_time, 0, 8);
        $now = now();
        $minimumPickupReadyAt = $now->copy()->addHours((int) $route->minimum_pickup_lead_hours);
        $slots = [];
        for ($offset = 0; $offset <= 30; $offset++) {
            $serviceDate = $now->copy()->startOfDay()->addDays($offset);
            if (! in_array((int) $serviceDate->dayOfWeek, $weekdays, true)) {
                continue;
            }

            $dispatchAt = $serviceDate->copy()->setTimeFromTimeString($cutoff);
            if ($dispatchAt->lt($minimumPickupReadyAt)) {
                continue;
            }
            if ($dispatchAt->lt($route->valid_from)) {
                continue;
            }

            $deliveryAt = $dispatchAt->copy()->addHours((int) $route->estimated_sla_hours);
            if ($deliveryAt->gt($route->valid_until)) {
                continue;
            }

            $slots[] = [
                'service_date' => $serviceDate,
                'dispatch_at' => $dispatchAt,
                'delivery_at' => $deliveryAt,
            ];
        }

        return $slots;
    }

    /** @return array{service_date: Carbon, dispatch_at: Carbon, delivery_at: Carbon}|null */
    private function nextAvailableDispatchSlot(LogisticsCoverageRoute $route): ?array
    {
        $slots = $this->dispatchSlotsWithinWindow($route);
        if ($slots === []) {
            return null;
        }
        $first = $slots[0]['service_date']->toDateString();
        $last = $slots[count($slots) - 1]['service_date']->toDateString();
        $bookingsByDate = Shipment::query()
            ->where('smart_coverage_route_id', $route->id)
            ->whereBetween('smart_coverage_service_date', [$first, $last])
            ->where('current_status', '!=', 'CANCELLED')
            ->lockForUpdate()
            ->get(['smart_coverage_service_date'])
            ->countBy(fn (Shipment $shipment): string => $shipment->smart_coverage_service_date?->toDateString() ?? '');

        foreach ($slots as $slot) {
            if ((int) $bookingsByDate->get($slot['service_date']->toDateString(), 0) < (int) $route->max_parcels_per_day) {
                return $slot;
            }
        }

        return null;
    }

    /** @return array{service_date: Carbon, dispatch_at: Carbon, delivery_at: Carbon}|null */
    private function dispatchSlotOnDate(LogisticsCoverageRoute $route, string $serviceDate): ?array
    {
        try {
            $date = Carbon::parse($serviceDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        $weekdays = collect((array) $route->dispatch_weekdays)
            ->map(fn ($day): int => is_numeric($day) ? (int) $day : -1)
            ->unique()
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->all();
        if (! in_array((int) $date->dayOfWeek, $weekdays, true) || ! filled($route->dispatch_cutoff_time)) {
            return null;
        }
        $dispatchAt = $date->copy()->setTimeFromTimeString(substr((string) $route->dispatch_cutoff_time, 0, 8));
        $deliveryAt = $dispatchAt->copy()->addHours((int) $route->estimated_sla_hours);
        if ($dispatchAt->lt($route->valid_from) || $deliveryAt->gt($route->valid_until)) {
            return null;
        }

        return ['service_date' => $date, 'dispatch_at' => $dispatchAt, 'delivery_at' => $deliveryAt];
    }

    private function eligibilityVersion(
        LogisticsCoverageRoute $route,
        ServiceAreaLocation $location,
        object $area,
        LogisticsCenter $destination,
    ): string {
        return hash('sha256', implode('|', [
            $route->id,
            $route->updated_at?->getTimestamp(),
            $location->id,
            $location->updated_at?->getTimestamp(),
            $area->updated_at?->getTimestamp(),
            $destination->id,
            $destination->updated_at?->getTimestamp(),
        ]));
    }

    private function primaryDecision(
        LogisticsCenter $origin,
        LogisticsCenter $destination,
        ServiceAreaLocation $destinationCoverage,
        ServiceAreaLocation $originCoverage,
    ): array {
        return [
            'origin' => $origin,
            'destination' => $destination,
            'destination_area' => $destinationCoverage,
            'origin_coverage' => $originCoverage,
            'coverage_type' => 'PRIMARY',
            'coverage_route' => null,
            'service_date' => null,
            'scheduled_dispatch_at' => null,
            'estimated_sla_hours' => null,
            'estimated_delivery_at' => null,
            'capacity_remaining_at_quote' => null,
            'eligibility_version' => 'primary:'.$destinationCoverage->id.':'.$destinationCoverage->updated_at?->getTimestamp(),
        ];
    }

    private function mayUseExplicitFallback(ValidationException $exception): bool
    {
        $reason = mb_strtolower((string) (collect($exception->errors())->flatten()->first() ?? ''));

        // Ambiguous exact coverage continues to the existing manual review
        // path. A fallback may only fill a genuine exact-coverage gap.
        return str_contains($reason, 'no active logistics hub')
            || str_contains($reason, 'buyer delivery barangay psgc is not covered');
    }

    private function assertCompleteAddress(Address|OrderAddress|null $address, string $purpose): void
    {
        if (! $address
            || blank($address->province_code)
            || blank($address->municipality_code)
            || blank($address->barangay_code)) {
            throw ValidationException::withMessages([
                'address_id' => "The {$purpose} address is missing a canonical province, municipality, or barangay PSGC.",
            ]);
        }
    }

    private function assertBarangayBelongsToMunicipality(string $municipalityCode, string $barangayCode, string $purpose): void
    {
        $barangays = PhilippineAddressController::barangayRecordsForMunicipality(trim($municipalityCode));
        if ($barangays === [] || ! collect($barangays)->contains(fn (array $record): bool => (string) ($record['code'] ?? '') === trim($barangayCode))) {
            throw ValidationException::withMessages([
                'address_id' => "The {$purpose} barangay PSGC is missing or does not belong to its municipality PSGC.",
            ]);
        }
    }
}
