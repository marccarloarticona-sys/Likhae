<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Auth\RegistrationApplication;
use App\Models\Admin\AuditLog;
use App\Models\Buyer\Address;
use App\Models\Communication\Conversation;
use App\Models\Finance\CodCollection;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderRemittance;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\TransferManifest;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Account\ProfilePhotoService;
use App\Services\AccountNotificationService;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Services\Fulfillment\LocalRiderEligibilityService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\LogisticsShipmentQuery;
use App\Services\RegistrationWorkflowService;
use App\Services\Reports\WorkspaceExportPdfService;
use App\Services\RiderRatingService;
use App\Services\Maps\MapDataService;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LogisticsPortalController extends Controller
{
    public function __construct(
        private readonly RegistrationWorkflowService $registrationWorkflow,
        private readonly LocalRiderEligibilityService $localRiderEligibility,
    ) {}

    public function dashboard(Request $request, LogisticsShipmentQuery $shipmentQuery): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $filters = $shipmentQuery->filters($request);

        // Related figures come from one read transaction; the next request reads
        // current persisted statuses after any rider workflow transaction commits.
        $shipmentData = DB::transaction(function () use ($center, $filters, $shipmentQuery): array {
            $base = $shipmentQuery->dated($shipmentQuery->operationalFor($center), $filters);
            $counts = $shipmentQuery->counts($base);
            $ready = app(LogisticsSchemaReadiness::class)->automaticRoutingReady();
            $operational = [
                'incoming' => (clone $base)->where(function ($query) use ($center, $ready): void {
                    $query->where(fn ($local) => $local->where('current_status', 'PICKED_UP')
                        ->where('logistics_center_id', $center->id));
                    if ($ready) {
                        $query->orWhereHas('transferLegs', fn ($leg) => $leg
                            ->where('destination_logistics_center_id', $center->id)
                            ->whereIn('status', ['RELEASED', 'IN_TRANSIT']));
                    }
                })->count(),
                'outgoing' => $ready ? (clone $base)->whereHas('transferLegs', fn ($leg) => $leg
                    ->where('origin_logistics_center_id', $center->id)
                    ->whereIn('status', ['READY', 'RELEASED', 'IN_TRANSIT']))->count() : 0,
                'sorting' => (clone $base)->where('current_status', 'AT_SORTING_CENTER')->count(),
                'rider_assignment' => (clone $base)->where('current_status', 'SORTED')
                    ->whereDoesntHave('riderAssignments', fn ($assignment) => $assignment
                        ->where('assignment_type', 'DELIVERY')
                        ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']))->count(),
                'pickup' => (clone $base)->where('current_status', 'ASSIGNED_TO_RIDER')
                    ->whereHas('riderAssignments', fn ($assignment) => $assignment
                        ->where('assignment_type', 'PICKUP')
                        ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']))->count(),
                'last_mile' => (clone $base)->whereIn('current_status', ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'])->count(),
                'manifests' => 0,
            ];
            if (app(LogisticsSchemaReadiness::class)->transferReady()) {
                $manifests = TransferManifest::query()
                    ->where(fn ($query) => $query->where('origin_logistics_center_id', $center->id)
                        ->orWhere('destination_logistics_center_id', $center->id))
                    ->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED]);
                if (! empty($filters['from_date'])) {
                    $manifests->whereDate('created_at', '>=', $filters['from_date']);
                }
                if (! empty($filters['to_date'])) {
                    $manifests->whereDate('created_at', '<=', $filters['to_date']);
                }
                $operational['manifests'] = $manifests->count();
            }

            return [
                'counts' => $counts,
                'operational' => $operational,
                'recentShipments' => (clone $base)
                    ->with(['originLogisticsCenter', 'destinationLogisticsCenter',
                        'latestEvent', 'latestRiderAssignment.riderProfile.user'])
                    ->latest('shipments.updated_at')->limit(10)->get(),
            ];
        });

        $finance = [
            'rider_cod_obligations' => 0.0,
            'remittance_submitted' => 0.0,
            'verified_cash' => 0.0,
            'discrepancies' => 0,
            'logistics_pool' => 0.0,
            'pending_settlement' => 0.0,
        ];
        if (app(FinanceSchemaReadiness::class)->step6Ready()) {
            $remittances = RiderRemittance::query()->where('logistics_center_id', $center->id);
            $finance['remittance_submitted'] = (float) (clone $remittances)->sum('submitted_amount');
            $finance['verified_cash'] = (float) (clone $remittances)->whereIn('status', ['VERIFIED', 'RECONCILED', 'DISCREPANCY'])->sum('verified_amount');
            $finance['discrepancies'] = (int) (clone $remittances)->where('status', 'DISCREPANCY')->count();
            $finance['rider_cod_obligations'] = (float) CodCollection::query()->where('logistics_center_id', $center->id)->whereIn('status', ['COLLECTED', 'REMITTED', 'DISCREPANCY'])->sum('expected_amount');
        }
        if (app(FinanceSchemaReadiness::class)->step7Ready()) {
            $pools = LogisticsEarningPool::query()->where('logistics_center_id', $center->id);
            $finance['logistics_pool'] = (float) (clone $pools)->sum('total_amount');
            $finance['pending_settlement'] = (float) (clone $pools)->whereIn('status', ['PENDING', 'APPROVED'])->sum('unresolved_share_amount');
        }

        return view('Logistics.dashboard.index', [
            'center' => $center,
            'filters' => $filters,
            ...$shipmentData,
            'financeSummary' => $finance,
        ]);
    }

    public function riderApplications(Request $request): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $applications = RegistrationApplication::query()
            ->whereHas('user', fn ($query) => $query->where('account_type', 'RIDER'))
            ->whereHas('riderData', fn ($query) => $query->where('target_logistics_center_id', $center->id))
            ->with(['user', 'documents', 'riderData'])
            ->latest()
            ->paginate(15);

        return view('Logistics.riders.application.index', compact('applications', 'center'));
    }

    public function riderApplicationShow(Request $request, RegistrationApplication $application): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $application->load(['user', 'documents', 'riderData']);
        abort_unless((int) $application->riderData?->target_logistics_center_id === (int) $center->id, 403);

        return view('Logistics.riders.application.show', compact('application', 'center'));
    }

    public function approveRider(Request $request, RegistrationApplication $application): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $application->load(['user', 'riderData']);
        abort_unless((int) $application->riderData?->target_logistics_center_id === (int) $center->id, 403);

        $this->registrationWorkflow->approveRider(
            $application,
            $request->user(),
            $request->input('decision_notes'),
            $request,
        );

        return redirect()->route('logistics.riders.applications')->with('status', 'Rider approved and activated.');
    }

    public function rejectRider(Request $request, RegistrationApplication $application): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $application->load('riderData');
        abort_unless((int) $application->riderData?->target_logistics_center_id === (int) $center->id, 403);

        $this->registrationWorkflow->reject(
            $application,
            $request->user(),
            $data['rejection_reason'],
            $request,
        );

        $application->user?->update(['status' => 'DEACTIVATED']);

        return back()->with('status', 'Rider application rejected.');
    }

    public function riders(Request $request): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $riderQuery = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereHas('user', fn ($query) => $query->where('account_type', User::TYPE_RIDER));

        $riders = (clone $riderQuery)
            ->with(['user', 'areaAssignments.serviceArea'])
            ->withCount([
                'assignments as active_pickup_assignments_count' => fn ($query) => $query
                    ->where('assignment_type', 'PICKUP')
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']),
            ])
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        $stats = [
            ['label' => 'Total Riders', 'value' => (clone $riderQuery)->count()],
            ['label' => 'Active', 'value' => (clone $riderQuery)->whereIn('status', ['ACTIVE', 'WARNED'])->count()],
            ['label' => 'Warned', 'value' => (clone $riderQuery)->where('status', 'WARNED')->count()],
            ['label' => 'Pending', 'value' => (clone $riderQuery)->where('status', 'PENDING')->count()],
            ['label' => 'Suspended / Banned', 'value' => (clone $riderQuery)->whereIn('status', ['SUSPENDED', 'BANNED', 'DEACTIVATED'])->count()],
        ];
        $localRiderCount = (clone $riderQuery)->whereIn('vehicle_type', RiderVehicleType::localValues())->count();
        $interLogisticsDriverCount = (clone $riderQuery)->whereIn('vehicle_type', RiderVehicleType::interLogisticsValues())->count();
        $classificationCounts = [
            'local' => $localRiderCount,
            'inter_logistics' => $interLogisticsDriverCount,
            'other' => max(0, (int) $stats[0]['value'] - $localRiderCount - $interLogisticsDriverCount),
        ];

        return view('Logistics.riders.index', compact('riders', 'center', 'stats', 'classificationCounts'));
    }

    public function riderShow(Request $request, RiderProfile $rider, RiderRatingService $riderRatings): View
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);

        $rider->load(['user', 'areaAssignments.serviceArea', 'assignments.shipment.sellerOrder.sellerProfile.businessAddress', 'assignments.shipment.sellerOrder.order.address', 'assignments.shipment.riderAssignments.liveLocation', 'assignments.shipment.logisticsCenter.address', 'assignments.liveLocation', 'earnings', 'moderationLogs.actor']);
        $rider->setAttribute('active_assignments_count', $rider->assignments
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->count());
        $rider->setAttribute('active_pickup_assignments_count', $rider->assignments
            ->where('assignment_type', 'PICKUP')
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->count());
        $ratingSummary = $riderRatings->summary($rider);
        $rider->setAttribute('rating_average', $ratingSummary['average']);
        $rider->setAttribute('rating_count', $ratingSummary['count']);

        $serviceAreas = $center->address
            ? ServiceArea::query()
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true)
                ->has('locations', '=', 1)
                ->whereHas('locations', fn ($location) => $location
                    ->where('province_code', $center->address->province_code)
                    ->where('municipality_code', $center->address->municipality_code))
                ->with('locations')
                ->orderBy('name')
                ->get()
            : collect();

        $maps = app(MapDataService::class);
        $mapMarkers = $maps->forAssignments($rider->assignments, true);
        if ($centerMarker = $maps->centerMarker($center->loadMissing('address'))) {
            $mapMarkers[] = $centerMarker;
        }
        return view('Logistics.riders.show', [
            'rider' => $rider,
            'center' => $center,
            'mapMarkers' => $mapMarkers,
            'serviceAreas' => $serviceAreas,
            'manifestSchemaReady' => app(LogisticsSchemaReadiness::class)->manifestReady(),
        ]);
    }

    public function updateRiderCapacity(Request $request, RiderProfile $rider, LogisticsSchemaReadiness $schema): RedirectResponse
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 403);
        if (! $schema->manifestReady()) {
            return back()->withErrors(['capacity' => 'Transfer schema is not installed; capacity approval is disabled.']);
        }

        $data = $request->validate([
            'approved_parcel_capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'approved_weight_capacity_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'approved_volume_capacity_liters' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
        ]);
        if (in_array($rider->vehicleRole(), [\App\Support\RiderVehicleType::ROLE_INTER_LOGISTICS], true)
            && (blank($data['approved_parcel_capacity'] ?? null)
                || blank($data['approved_weight_capacity_kg'] ?? null)
                || blank($data['approved_volume_capacity_liters'] ?? null))) {
            return back()->withErrors(['capacity' => 'Inter-logistics riders require all three approved capacity values.'])->withInput();
        }
        if ($rider->vehicleRole() === \App\Support\RiderVehicleType::ROLE_LOCAL_BARANGAY
            && (int) ($data['approved_parcel_capacity'] ?? 0) > 10) {
            return back()->withErrors(['approved_parcel_capacity' => 'Local motorcycle/tricycle trips are limited to 10 parcels.'])->withInput();
        }
        if (\App\Support\RiderVehicleType::isInterLogistics($rider->vehicle_type)
            && (int) ($data['approved_parcel_capacity'] ?? 0) > \App\Support\RiderVehicleType::interLogisticsParcelLimit($rider->vehicle_type)) {
            $limit = \App\Support\RiderVehicleType::interLogisticsParcelLimit($rider->vehicle_type);
            return back()->withErrors(['approved_parcel_capacity' => str($rider->vehicle_type)->headline().' capacity is capped at '.$limit.' parcels.'])->withInput();
        }

        DB::transaction(function () use ($rider, $data): void {
            RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail()->update([
                'approved_parcel_capacity' => $data['approved_parcel_capacity'] ?? null,
                'approved_weight_capacity_kg' => $data['approved_weight_capacity_kg'] ?? null,
                'approved_volume_capacity_liters' => $data['approved_volume_capacity_liters'] ?? null,
            ]);
        });

        return back()->with('status', 'Rider transfer capacity saved as an approved hub setting.');
    }

    public function reassignRiderArea(Request $request, RiderProfile $rider): RedirectResponse
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);

        $data = $request->validate([
            'service_area_id' => ['nullable', 'integer'],
        ]);

        $area = null;
        if ($request->filled('service_area_id')) {
            $area = ServiceArea::query()
                ->whereKey($data['service_area_id'])
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true)
                ->with('locations')
                ->first();

            abort_unless($area, 403);

            try {
                $this->localRiderEligibility->assertRiderCanCoverArea($center, $rider, $area);
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['service_area_id' => $exception->getMessage()])->withInput();
            }
        }

        DB::transaction(function () use ($area, $rider, $request): void {
            $lockedRider = RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $activeAssignments = RiderAreaAssignment::query()
                ->where('rider_profile_id', $lockedRider->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();
            $activeAssignments->each->update(['is_active' => false, 'ended_at' => now()]);

            if ($area) {
                RiderAreaAssignment::create([
                    'rider_profile_id' => $lockedRider->id,
                    'service_area_id' => $area->id,
                    'assigned_by_user_id' => $request->user()->id,
                    'is_active' => true,
                    'assigned_at' => now(),
                    'ended_at' => null,
                ]);
            }
        });

        return back()->with('status', $area
            ? 'Rider delivery area reassigned to '.$area->name.'.'
            : 'Rider delivery area cleared.');
    }

    public function activateRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        return $this->saveRiderModeration($request, $rider, 'ACTIVE', 'Rider reactivated by the logistics center.');
    }

    public function deactivateRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        return $this->saveRiderModeration($request, $rider, 'SUSPENDED', 'Rider suspended by the logistics center.');
    }

    public function moderateRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'WARNED', 'SUSPENDED', 'BANNED'])],
            'reason' => ['required_unless:status,ACTIVE', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->saveRiderModeration(
            $request,
            $rider,
            $data['status'],
            trim((string) ($data['reason'] ?? 'Rider reactivated by the logistics center.')),
        );
    }

    private function saveRiderModeration(Request $request, RiderProfile $rider, string $status, string $reason): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);

        $rider->loadMissing('user');
        $oldStatus = $rider->status;
        $userStatus = match ($status) {
            'SUSPENDED' => 'SUSPENDED',
            'BANNED' => 'DEACTIVATED',
            default => 'ACTIVE',
        };

        DB::transaction(function () use ($request, $rider, $status, $userStatus, $reason, $oldStatus): void {
            $rider->update(['status' => $status]);
            $rider->user?->update(['status' => $userStatus]);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'rider.'.strtolower($status),
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $rider->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => ['status' => $status, 'reason' => $reason],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            app(AccountNotificationService::class)->riderModerationChanged($rider, $status, $reason);
        });

        return back()->with('status', 'Rider status updated to '.str($status)->headline().'.');
    }

    public function deliveryAreas(Request $request): View
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center && $center->status === 'ACTIVE', 403);

        $areas = ServiceArea::query()
            ->where('logistics_center_id', $center->id)
            ->with(['locations', 'riderAssignments.riderProfile.user'])
            ->orderBy('name')
            ->get();

        $riders = $this->localRiderEligibility
            ->activeRidersForCenter($center)
            ->with('user')
            ->orderBy('id')
            ->get();

        $centerCanonicalMunicipality = $this->localRiderEligibility->canonicalMunicipality($center);
        $allBarangays = $centerCanonicalMunicipality
            ? PhilippineAddressController::barangayRecordsForMunicipality($centerCanonicalMunicipality['municipality_code'])
            : [];
        $coveredBarangayCodes = $areas
            ->where('is_active', true)
            ->flatMap(fn (ServiceArea $area) => $area->locations)
            ->filter(fn (ServiceAreaLocation $location): bool =>
                $centerCanonicalMunicipality
                && (string) $location->province_code === $centerCanonicalMunicipality['province_code']
                && (string) $location->municipality_code === $centerCanonicalMunicipality['municipality_code'])
            ->pluck('barangay_code')
            ->filter()
            ->unique()
            ->values();
        $uncoveredBarangays = collect($allBarangays)
            ->reject(fn (array $barangay): bool => $coveredBarangayCodes->contains((string) ($barangay['code'] ?? '')))
            ->values();
        $assignableAreaIds = $areas
            ->filter(fn (ServiceArea $area): bool => $this->localRiderEligibility->singleBarangayLocation($center, $area) !== null)
            ->pluck('id')
            ->all();
        $invalidAreaReasons = $areas->mapWithKeys(function (ServiceArea $area) use ($center): array {
            $locations = $area->locations;
            $reason = null;

            if ($locations->count() !== 1) {
                $reason = 'The existing area has missing or multiple barangay locations.';
            } elseif (! $this->localRiderEligibility->centerCoversMunicipality(
                $center,
                $locations->first()->province_code,
                $locations->first()->municipality_code,
            )) {
                $reason = 'The existing barangay PSGC does not match the center canonical province and municipality.';
            }

            return $reason ? [$area->id => $reason] : [];
        });

        $mapMarkers = array_filter([app(MapDataService::class)->centerMarker($center)]);
        return view('Logistics.delivery-areas.index', compact('areas', 'riders', 'center', 'centerCanonicalMunicipality', 'mapMarkers', 'uncoveredBarangays', 'assignableAreaIds', 'invalidAreaReasons'));
    }

    public function saveDeliveryArea(Request $request): RedirectResponse
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center && $center->status === 'ACTIVE', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50'],
            'region_code' => ['required', 'string', 'max:50'],
            'region_name' => ['required', 'string', 'max:150'],
            'province_code' => ['required', 'string', 'max:50'],
            'province_name' => ['required', 'string', 'max:150'],
            'municipality_code' => ['required', 'string', 'max:50'],
            'municipality_name' => ['required', 'string', 'max:150'],
            'barangay_code' => ['required', 'string', 'max:50'],
            'barangay_name' => ['required', 'string', 'max:150'],
            'rider_profile_id' => ['nullable', 'integer', 'exists:rider_profiles,id'],
        ]);

        if (! PhilippineAddressController::selectionIsValid(
            (string) $data['region_code'],
            (string) $data['region_name'],
            (string) $data['province_code'],
            (string) $data['province_name'],
            (string) $data['municipality_code'],
            (string) $data['municipality_name'],
            (string) $data['barangay_code'],
            (string) $data['barangay_name'],
        )) {
            return back()
                ->withErrors(['barangay_code' => 'Select the province, municipality, and barangay from the address API.'])
                ->withInput();
        }

        if (! empty($data['rider_profile_id'])) {
            abort_unless(
                RiderProfile::query()
                    ->whereKey($data['rider_profile_id'])
                    ->where('logistics_center_id', $center->id)
                    ->whereIn('status', ['ACTIVE', 'WARNED'])
                    ->whereHas('user', fn ($query) => $query
                        ->where('account_type', User::TYPE_RIDER)
                        ->where('status', User::STATUS_ACTIVE))
                    ->exists(),
                403
            );
        }

        try {
            $this->localRiderEligibility->assertCenterCoversMunicipality(
                $center,
                (string) $data['province_code'],
                (string) $data['municipality_code'],
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['municipality_code' => $exception->getMessage()])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $center, $request): void {
                // Serialize coverage changes for this hub so concurrent requests
                // cannot both pass the overlap check.
                LogisticsCenter::query()->whereKey($center->id)->lockForUpdate()->firstOrFail();

                $area = ServiceArea::firstOrCreate(
                    ['logistics_center_id' => $center->id, 'code' => $data['code'] ?: Str::slug($data['name'])],
                    ['name' => $data['name'], 'is_active' => true],
                );
                $area->update(['name' => $data['name'], 'is_active' => true]);

                $overlapExists = ServiceAreaLocation::query()
                    ->where('province_code', $data['province_code'])
                    ->where('municipality_code', $data['municipality_code'])
                    ->where('barangay_code', $data['barangay_code'])
                    ->where('service_area_id', '!=', $area->id)
                    ->whereHas('serviceArea', fn ($query) => $query
                        ->where('logistics_center_id', $center->id)
                        ->where('is_active', true))
                    ->exists();

                if ($overlapExists) {
                    throw new \RuntimeException('This barangay PSGC is already covered by another active service area in this logistics center.');
                }

                $hasOtherLocation = $area->locations()
                    ->where('barangay_code', '!=', $data['barangay_code'])
                    ->exists();
                if ($hasOtherLocation && $area->riderAssignments()->where('is_active', true)->exists()) {
                    throw new \RuntimeException('This service area already has an active rider and multiple barangays. Split or clear the existing assignment before changing its coverage.');
                }

                ServiceAreaLocation::updateOrCreate(
                    ['service_area_id' => $area->id, 'barangay_code' => $data['barangay_code']],
                    [
                        'province_code' => $data['province_code'],
                        'province_name' => $data['province_name'],
                        'municipality_code' => $data['municipality_code'],
                        'municipality_name' => $data['municipality_name'],
                        'barangay_name' => $data['barangay_name'],
                    ],
                );

                if (! empty($data['rider_profile_id'])) {
                    $rider = RiderProfile::query()
                        ->with('user')
                        ->whereKey($data['rider_profile_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $area->load('locations');
                    $this->localRiderEligibility->assertRiderCanCoverArea($center, $rider, $area);

                    RiderAreaAssignment::query()
                        ->where('rider_profile_id', $rider->id)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->get()
                        ->each
                        ->update(['is_active' => false, 'ended_at' => now()]);

                    RiderAreaAssignment::create([
                        'rider_profile_id' => $rider->id,
                        'service_area_id' => $area->id,
                        'assigned_by_user_id' => $request->user()->id,
                        'is_active' => true,
                        'assigned_at' => now(),
                        'ended_at' => null,
                    ]);
                }
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Service area saved.');
    }

    public function toggleDeliveryArea(Request $request, ServiceArea $area): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center && $center->status === 'ACTIVE' && (int) $area->logistics_center_id === (int) $center->id, 403);

        DB::transaction(function () use ($area): void {
            $lockedArea = ServiceArea::query()->whereKey($area->id)->lockForUpdate()->firstOrFail();
            $willDeactivate = (bool) $lockedArea->is_active;
            if ($willDeactivate) {
                $lockedArea->riderAssignments()
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'ended_at' => now()]);
            }
            $lockedArea->update(['is_active' => ! $willDeactivate]);
        });

        return back()->with('status', 'Service area updated.');
    }

    public function reports(Request $request, LogisticsShipmentQuery $shipmentQuery): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        return view('Logistics.reports.index', [
            'center' => $center,
            'shipmentsByStatus' => $shipmentQuery->operationalFor($center)->selectRaw('current_status, COUNT(*) as total')->groupBy('current_status')->pluck('total', 'current_status'),
            'riderCount' => RiderProfile::where('logistics_center_id', $center->id)->count(),
        ]);
    }

    public function exportReport(Request $request, LogisticsShipmentQuery $shipmentQuery, WorkspaceExportPdfService $pdf)
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $rows = $shipmentQuery->operationalFor($center)
            ->orderByDesc('shipments.id')
            ->lazyById(500, 'shipments.id', 'id')
            ->map(fn (Shipment $shipment): array => [
                (string) $shipment->tracking_number,
                (string) $shipment->current_status,
                collect([
                    $shipment->destination_barangay_name,
                    $shipment->destination_municipality_name,
                    $shipment->destination_province_name,
                ])->filter()->implode(', '),
                (string) ($shipment->updated_at?->toDateTimeString() ?? ''),
            ]);
        $filename = 'logistics-report-'.$center->id.'-'.now()->format('Ymd-His').'.pdf';

        return response($pdf->render([
            'workspace' => 'Logistics Portal',
            'title' => 'Shipment operations report',
            'description' => 'Operational shipments visible to '.$center->business_name.'.',
            'generated_at' => now()->format('Y-m-d H:i:s T'),
            'orientation' => 'landscape',
            'columns' => [
                ['label' => 'Tracking', 'width' => 0.25],
                ['label' => 'Status', 'width' => 0.2],
                ['label' => 'Destination', 'width' => 0.35],
                ['label' => 'Updated', 'width' => 0.2],
            ],
            'rows' => $rows,
            'empty_message' => 'No operational shipments are available to this logistics center.',
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function profile(Request $request): View
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center, 403);

        return view('Logistics.profile.index', ['center' => $center, 'user' => $request->user()]);
    }

    public function updateAccount(Request $request, ProfilePhotoService $profilePhotos): RedirectResponse
    {
        abort_unless($request->user()->logisticsCenter, 403);
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_number' => ['required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($user->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $hasNewPhoto = $request->hasFile('profile_photo');
        unset($data['profile_photo']);
        $user->update($data);

        if ($hasNewPhoto) {
            $profilePhotos->replace($user, $request->file('profile_photo'));
        }

        return back()->with('status', 'Account profile updated.');
    }

    public function updateCenterAddress(Request $request): RedirectResponse
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center, 403);

        $data = $request->validate(PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules());
        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);
        AddressCoordinateValidator::assertFreshForAddress($center->address, $data);

        $address = $center->address ?: new Address([
            'user_id' => $request->user()->id,
            'label' => 'Logistics center',
            'is_default' => false,
        ]);
        $address->fill([
            'recipient_name' => $center->business_name,
            'contact_number' => $request->user()->contact_number,
            ...PhilippineAddressValidator::storageAttributes($data),
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
        $address->save();

        if ((int) $center->address_id !== (int) $address->id) {
            $center->update(['address_id' => $address->id]);
        }

        return back()->with('status', 'Sorting center address updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        abort_unless($request->user()->logisticsCenter, 403);
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('status', 'Account password updated.');
    }

    public function messages(Request $request, ConversationService $conversationService): View|RedirectResponse
    {
        $shipmentId = $request->integer('shipment_id');
        $recipientId = $request->integer('recipient_user_id');
        if ($shipmentId > 0 || $recipientId > 0) {
            abort_unless($shipmentId > 0 && $recipientId > 0, 404);
            $recipient = User::query()->whereKey($recipientId)->where('status', User::STATUS_ACTIVE)->firstOrFail();
            $context = app(\App\Services\Communication\ConversationStartAuthorizationService::class)
                ->contextForNew($request->user(), $recipient, $shipmentId);
            abort_if($context === null || empty($context['shipment_id']), 403);
            $conversation = $conversationService->start($request->user(), $recipient->id, $context);

            return redirect()->route('logistics.messages', ['conversation' => $conversation->id]);
        }

        $conversations = $conversationService->workspaceThreads(
            $request->user(),
            $request->integer('conversation') ?: null,
        );
        $contacts = User::query()->where('status', 'ACTIVE')->whereKeyNot($request->user()->id)
            ->orderBy('first_name')->limit(100)
            ->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']);
        $contacts = app(\App\Services\Communication\ConversationStartAuthorizationService::class)
            ->filterRecipients($request->user(), $contacts);

        return view('Logistics.messages.index', [
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageContext' => app(ConversationContextPresenter::class)->forUser(
                $request->user(),
                $conversations->firstWhere('id', $request->integer('conversation')) ?: $conversations->first(),
            ),
        ]);
    }

    public function messageStream(Request $request, ConversationService $conversationService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'messages' => $conversationService->streamPayload(
                $request->user(),
                $request->integer('conversation'),
                $request->integer('after_id') ?: null,
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sendMessage(Request $request, ConversationService $conversationService, ConversationMessageSender $sender): JsonResponse|RedirectResponse
    {
        $recipientData = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $data = $recipientData + $sender->validate($request);
        $recipient = User::query()->whereKey($data['recipient_user_id'])->where('status', User::STATUS_ACTIVE)->firstOrFail();
        $message = $sender->send($request, $data, $recipient, $conversationService);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $conversationService->messagePayload($message),
            ]);
        }

        return back()->with('status', 'Message sent.');
    }
}
