<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Admin\AuditLog;
use App\Models\Buyer\Address;
use App\Models\Communication\Conversation;
use App\Models\Finance\CodCollection;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderRemittance;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\TransferManifest;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderProfileUpdateDocument;
use App\Models\Rider\RiderProfileUpdateRequest;
use App\Models\User;
use App\Services\Account\ProfilePhotoService;
use App\Services\AccountNotificationService;
use App\Services\Admin\AdminUserSessionManager;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Services\Fulfillment\LocalRiderEligibilityService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Finance\PayoutDestinationManager;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\LogisticsShipmentQuery;
use App\Services\RegistrationWorkflowService;
use App\Services\Reports\WorkspaceExportPdfService;
use App\Services\Reports\LogisticsFinancialReportService;
use App\Services\RiderRatingService;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use App\Support\RiderVehicleType;
use App\Support\LocalTripCapacityRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class LogisticsPortalController extends Controller
{
    private const RIDER_WARNING_CATEGORIES = [
        'UNSAFE_DRIVING' => 'Unsafe or reckless driving',
        'PARCEL_HANDLING' => 'Unsafe or careless parcel handling',
        'CUSTOMER_CONDUCT' => 'Unprofessional conduct toward a customer or seller',
        'DELIVERY_FAILURES' => 'Repeated unexplained delivery failures',
        'COD_REMITTANCE' => 'COD remittance or cash-handling violation',
        'POLICY_VIOLATIONS' => 'Substantiated rider policy violations',
        'OTHER' => 'Other',
    ];

    private const RIDER_SUSPENSION_DURATIONS = [
        'week' => ['label' => '1 week', 'unit' => 'week', 'count' => 1],
        'fortnight' => ['label' => '2 weeks', 'unit' => 'week', 'count' => 2],
        'month' => ['label' => '1 month', 'unit' => 'month', 'count' => 1],
        'quarter' => ['label' => '3 months', 'unit' => 'month', 'count' => 3],
        'half_year' => ['label' => '6 months', 'unit' => 'month', 'count' => 6],
    ];

    public function __construct(
        private readonly RegistrationWorkflowService $registrationWorkflow,
        private readonly LocalRiderEligibilityService $localRiderEligibility,
    ) {}

    public function dashboard(Request $request, LogisticsShipmentQuery $shipmentQuery, InterhubTimekeepingController $interhubTimekeeping): View|JsonResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        // Dashboard data is live operational state and is not restricted by shipment dates.
        abort_if($request->hasAny(['company_id', 'hub_id', 'logistics_center_id']), 403);

        // Related figures come from one read transaction; the next request reads
        // current persisted statuses after any rider workflow transaction commits.
        $shipmentData = DB::transaction(function () use ($center, $shipmentQuery): array {
            $base = $shipmentQuery->operationalFor($center);
            $counts = $shipmentQuery->counts($base);
            $ready = app(LogisticsSchemaReadiness::class)->automaticRoutingReady();
            $operational = [
                'incoming' => (clone $base)->where(function ($query) use ($center, $ready): void {
                    $query->where(fn ($local) => $local->where('current_status', 'PICKED_UP')
                        ->where('logistics_center_id', $center->id));
                    $query->orWhere(fn ($dropoff) => $dropoff
                        ->where('current_status', 'READY_FOR_PICKUP')
                        ->where(fn ($centerQuery) => $centerQuery->where('logistics_center_id', $center->id)
                            ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                                ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id))))
                        ->whereHas('pickupRequests', fn ($pickup) => $pickup
                            ->whereIn('status', ['PENDING', 'APPROVED'])
                            ->where('notes', 'like', 'SELLER_DROPOFF:%')));
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
                'active_riders' => RiderProfile::query()
                    ->where('logistics_center_id', $center->id)
                    ->whereIn('status', ['ACTIVE', 'WARNED'])
                    ->whereHas('user', fn ($user) => $user->where('account_type', User::TYPE_RIDER)->where('status', User::STATUS_ACTIVE))
                    ->count(),
                'suspended_riders' => RiderProfile::query()
                    ->where('logistics_center_id', $center->id)
                    ->where(fn ($riders) => $riders->whereIn('status', ['SUSPENDED', 'BANNED', 'DEACTIVATED'])
                        ->orWhereHas('user', fn ($user) => $user->where('status', User::STATUS_SUSPENDED)))
                    ->count(),
                'manifests' => 0,
            ];
            if (app(LogisticsSchemaReadiness::class)->transferReady()) {
                $manifests = TransferManifest::query()
                    ->where(fn ($query) => $query->where('origin_logistics_center_id', $center->id)
                        ->orWhere('destination_logistics_center_id', $center->id))
                    ->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED]);
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
            'cod_collected' => 0.0,
            'cod_awaiting_remittance' => 0.0,
            'cod_reconciled' => 0.0,
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
            $collections = CodCollection::query()->where('logistics_center_id', $center->id);
            $finance['cod_collected'] = (float) (clone $collections)
                ->whereIn('status', [CodCollection::COLLECTED, CodCollection::REMITTED, CodCollection::REMITTED_RIDER_TOPUP, CodCollection::REMITTED_DISCREPANCY, CodCollection::DISCREPANCY])
                ->sum('collected_amount');
            $finance['cod_awaiting_remittance'] = (float) (clone $collections)
                ->whereIn('status', [CodCollection::COLLECTED, CodCollection::DISCREPANCY])
                ->sum('collected_amount');
            $finance['cod_reconciled'] = (float) (clone $remittances)
                ->where('status', RiderRemittance::RECONCILED)
                ->sum('verified_amount');
        }
        if (app(FinanceSchemaReadiness::class)->step7Ready()) {
            $pools = LogisticsEarningPool::query()->where('logistics_center_id', $center->id);
            $finance['logistics_pool'] = (float) (clone $pools)->sum('total_amount');
            $finance['pending_settlement'] = (float) (clone $pools)->whereIn('status', ['PENDING', 'APPROVED', 'REVIEW_REQUIRED'])->sum('unresolved_share_amount');
        }

        if ($request->expectsJson()) {
            $recentShipments = $shipmentData['recentShipments']->map(fn (Shipment $shipment): array => [
                'tracking_number' => $shipment->tracking_number,
                'status' => (string) str($shipment->current_status)->headline(),
                'origin' => $shipment->originLogisticsCenter?->business_name ?? '—',
                'destination' => $shipment->destinationLogisticsCenter?->business_name ?? '—',
                'last_event' => $shipment->latestEvent?->occurred_at?->format('M d, Y g:i A') ?? 'No event yet',
                'rider' => $shipment->latestRiderAssignment?->riderProfile?->user?->name ?? 'Unassigned',
                'url' => route('logistics.parcels.show', $shipment),
            ])->values();

            return response()->json([
                'counts' => $shipmentData['counts'],
                'operational' => $shipmentData['operational'],
                'financeSummary' => $finance,
                'recentShipments' => $recentShipments,
                'updatedAt' => now()->toIso8601String(),
            ], 200, ['Cache-Control' => 'private, no-store']);
        }

        return view('logistics.dashboard.index', [
            'center' => $center,
            ...$shipmentData,
            'financeSummary' => $finance,
            ...$interhubTimekeeping->logisticsData($request),
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

        return view('logistics.riders.application.index', compact('applications', 'center'));
    }

    public function riderApplicationShow(Request $request, RegistrationApplication $application): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $application->load(['user', 'documents', 'riderData']);
        abort_unless((int) $application->riderData?->target_logistics_center_id === (int) $center->id, 403);

        return view('logistics.riders.application.show', compact('application', 'center'));
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

    public function riders(Request $request, AdminUserSessionManager $sessions): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'local', 'interhub'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $activeTab = $filters['tab'] ?? 'all';
        $search = trim((string) ($filters['q'] ?? ''));

        $riderQuery = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereHas('user', fn ($query) => $query->where('account_type', User::TYPE_RIDER));

        $tabCounts = [
            'all' => (clone $riderQuery)->count(),
            'local' => (clone $riderQuery)->whereIn('vehicle_type', RiderVehicleType::localValues())->count(),
            'interhub' => (clone $riderQuery)->whereIn('vehicle_type', RiderVehicleType::interLogisticsValues())->count(),
        ];

        $filteredRiderQuery = clone $riderQuery;
        if ($activeTab === 'local') {
            $filteredRiderQuery->whereIn('vehicle_type', RiderVehicleType::localValues());
        } elseif ($activeTab === 'interhub') {
            $filteredRiderQuery->whereIn('vehicle_type', RiderVehicleType::interLogisticsValues());
        }
        if ($search !== '') {
            $filteredRiderQuery->where(function ($query) use ($search): void {
                $query->whereHas('user', fn ($user) => $user
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'))
                    ->orWhere('vehicle_type', 'like', '%'.$search.'%')
                    ->orWhere('plate_number', 'like', '%'.$search.'%');
            });
        }

        $riders = $filteredRiderQuery
            ->with(['user', 'approvedRegistrationApplication', 'areaAssignments.serviceArea'])
            ->withCount([
                'assignments as active_pickup_assignments_count' => fn ($query) => $query
                    ->where('assignment_type', 'PICKUP')
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']),
                'assignments as active_assignments_count' => fn ($query) => $query
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']),
                'assignments as completed_deliveries_today_count' => fn ($query) => $query
                    ->where('assignment_type', 'DELIVERY')
                    ->where('status', 'COMPLETED')
                    ->whereDate('completed_at', today()),
            ])
            ->latest()
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        if (Schema::hasTable('rider_profile_update_requests')) {
            $pendingProfileUpdates = RiderProfileUpdateRequest::query()
                ->where('status', 'PENDING')
                ->whereIn('rider_profile_id', $riders->getCollection()->modelKeys())
                ->selectRaw('rider_profile_id, COUNT(*) as pending_count')
                ->groupBy('rider_profile_id')
                ->pluck('pending_count', 'rider_profile_id');
            $riders->getCollection()->each(fn (RiderProfile $rider) => $rider->setAttribute(
                'pending_profile_update_count',
                (int) ($pendingProfileUpdates[$rider->id] ?? 0),
            ));
        }

        $riders->getCollection()->each(function (RiderProfile $rider): void {
            $rider->setAttribute('remaining_parcel_capacity', LocalTripCapacityRules::remainingCapacity(
                $rider->approved_parcel_capacity === null ? null : (int) $rider->approved_parcel_capacity,
                (int) $rider->active_assignments_count,
            ));
        });

        $stats = [
            ['label' => 'Total Riders', 'value' => (clone $riderQuery)->count()],
            ['label' => 'Active', 'value' => (clone $riderQuery)->whereIn('status', ['ACTIVE', 'WARNED'])->count()],
            ['label' => 'Online now', 'value' => (clone $riderQuery)->whereIn('vehicle_type', RiderVehicleType::localValues())->where('is_online', true)->count()],
            ['label' => 'Warned', 'value' => (clone $riderQuery)->where('status', 'WARNED')->count()],
            ['label' => 'Pending', 'value' => (clone $riderQuery)->where('status', 'PENDING')->count()],
            ['label' => 'Suspended / Banned', 'value' => (clone $riderQuery)->whereIn('status', ['SUSPENDED', 'BANNED', 'DEACTIVATED'])->count()],
        ];
        $sessionManagementAvailable = $sessions->available();
        $sessionSummaries = $sessions->summaries($riders->getCollection()->pluck('user')->filter());
        $riders->getCollection()->each(function (RiderProfile $rider) use ($sessionSummaries): void {
            $summary = $sessionSummaries[$rider->user?->id] ?? ['session_count' => 0];
            $rider->setAttribute('active_session_count', (int) ($summary['session_count'] ?? 0));
        });

        return view('logistics.riders.index', compact(
            'riders',
            'center',
            'stats',
            'activeTab',
            'tabCounts',
            'search',
            'sessionManagementAvailable',
        ));
    }

    public function revokeRiderSessions(Request $request, AdminUserSessionManager $sessions): RedirectResponse
    {
        $data = $request->validate([
            'single_rider_id' => ['nullable', 'integer', 'min:1'],
            'rider_ids' => ['required_without:single_rider_id', 'array', 'min:1', 'max:100'],
            'rider_ids.*' => ['integer', 'distinct', 'min:1'],
            'tab' => ['nullable', Rule::in(['all', 'local', 'interhub'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $sessions->available()) {
            return back()->withErrors([
                'sessions' => 'Session controls are unavailable for the configured session driver or database schema.',
            ]);
        }

        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        $single = filled($data['single_rider_id'] ?? null);
        $requestedIds = $single
            ? [(int) $data['single_rider_id']]
            : collect($data['rider_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $riders = RiderProfile::query()
            ->whereIn('id', $requestedIds)
            ->where('logistics_center_id', $center->id)
            ->whereHas('user', fn ($query) => $query->where('account_type', User::TYPE_RIDER))
            ->with('user')
            ->get();
        if ($riders->count() !== count($requestedIds)) {
            return back()->withErrors(['sessions' => 'One or more selected riders are no longer assigned to this Logistics center.']);
        }

        $users = $riders->pluck('user')->filter()->unique('id')->values();
        if ($users->count() !== $riders->count()) {
            return back()->withErrors(['sessions' => 'One or more selected rider accounts are unavailable.']);
        }
        if ($users->contains(fn (User $user): bool => (int) $user->id === (int) $request->user()->id)) {
            return back()->withErrors(['sessions' => 'You cannot suspend your own current session.']);
        }

        $revokedCount = $sessions->revoke(
            $request,
            $users->all(),
            'your assigned Logistics center',
            'LOGISTICS_FORCED_RIDER_SIGN_OUT',
        );
        $riderCount = $users->count();

        return back()->with('status', 'Suspended sessions for '.$riderCount.' rider account'.($riderCount === 1 ? '' : 's').'; revoked '.$revokedCount.' active browser session'.($revokedCount === 1 ? '' : 's').'. Riders must sign in again.');
    }

    public function riderShow(Request $request, RiderProfile $rider, RiderRatingService $riderRatings): View
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);

        $rider->load('user');
        $rider->user?->isSuspended();
        $rider->refresh()->load(['user', 'approvedRegistrationApplication', 'areaAssignments.serviceArea', 'assignments', 'earnings', 'moderationLogs.actor']);
        $warningLogs = $rider->moderationLogs->where('event', 'rider.warned')->values();
        $warningCount = $warningLogs->count();
        $accountEnforcementReady = User::accountEnforcementSchemaReady();
        $profileUpdateSchemaReady = Schema::hasTable('rider_profile_update_requests')
            && Schema::hasTable('rider_profile_update_documents');
        $profileUpdateRequests = $profileUpdateSchemaReady
            ? $rider->profileUpdateRequests()->with(['documents', 'submitter', 'reviewer'])->limit(10)->get()
            : collect();
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

        $isLocalRider = RiderVehicleType::isLocalBarangay($rider->vehicle_type);
        $serviceAreas = $isLocalRider && $center->address
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

        $schema = app(LogisticsSchemaReadiness::class);
        return view('logistics.riders.show', [
            'rider' => $rider,
            'center' => $center,
            'serviceAreas' => $serviceAreas,
            'isLocalRider' => $isLocalRider,
            'manifestSchemaReady' => $schema->manifestReady(),
            'localTripsSchemaReady' => $schema->localTripsReady(),
            'profileUpdateSchemaReady' => $profileUpdateSchemaReady,
            'profileUpdateRequests' => $profileUpdateRequests,
            'riderWarningCategories' => self::RIDER_WARNING_CATEGORIES,
            'riderWarningLogs' => $warningLogs,
            'riderWarningCount' => $warningCount,
            'riderSuspensionDurations' => array_map(
                fn (array $duration): string => $duration['label'],
                self::RIDER_SUSPENSION_DURATIONS,
            ),
            'accountEnforcementReady' => $accountEnforcementReady,
        ]);
    }

    public function riderProfileUpdateDocument(
        Request $request,
        RiderProfile $rider,
        RiderProfileUpdateRequest $profileUpdateRequest,
        RiderProfileUpdateDocument $document,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 404);
        abort_unless((int) $profileUpdateRequest->rider_profile_id === (int) $rider->id, 404);
        abort_unless((int) $document->rider_profile_update_request_id === (int) $profileUpdateRequest->id, 404);
        abort_unless(Storage::disk('registrations')->exists($document->file_path), 404);

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $filename = strtolower($document->document_type).($extension ? '.'.$extension : '');

        return Storage::disk('registrations')->response(
            $document->file_path,
            $filename,
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }

    public function approveRiderProfileUpdate(
        Request $request,
        RiderProfile $rider,
        RiderProfileUpdateRequest $profileUpdateRequest,
    ): RedirectResponse {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 404);
        abort_unless((int) $profileUpdateRequest->rider_profile_id === (int) $rider->id, 404);

        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:1000']]);
        $uniqueness = Validator::make([
            'vehicle_type' => $profileUpdateRequest->vehicle_type,
            'plate_number' => $profileUpdateRequest->plate_number,
            'drivers_license_number' => $profileUpdateRequest->drivers_license_number,
        ], [
            'vehicle_type' => ['required', Rule::in(RiderVehicleType::acceptedValues())],
            'plate_number' => ['required', 'string', Rule::unique('rider_profiles', 'plate_number')->ignore($rider->id)],
            'drivers_license_number' => ['required', 'string', Rule::unique('rider_profiles', 'drivers_license_number')->ignore($rider->id)],
        ]);
        if ($uniqueness->fails()) {
            return back()->withErrors($uniqueness)->withInput();
        }

        DB::transaction(function () use ($request, $rider, $profileUpdateRequest, $data): void {
            $lockedRequest = RiderProfileUpdateRequest::query()
                ->whereKey($profileUpdateRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedRequest->status === 'PENDING', 409, 'This profile update has already been reviewed.');

            $lockedRider = RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $vehicleTypeChanged = (string) $lockedRider->vehicle_type !== (string) $lockedRequest->vehicle_type;
            $profileChanges = [
                'vehicle_type' => $lockedRequest->vehicle_type,
                'plate_number' => mb_strtoupper(trim($lockedRequest->plate_number)),
                'drivers_license_number' => mb_strtoupper(trim($lockedRequest->drivers_license_number)),
            ];
            if ($vehicleTypeChanged) {
                $profileChanges += [
                    'approved_parcel_capacity' => null,
                    'approved_weight_capacity_kg' => null,
                    'approved_volume_capacity_liters' => null,
                    'is_online' => false,
                    'availability_latitude' => null,
                    'availability_longitude' => null,
                    'availability_location_updated_at' => null,
                ];
            }
            $lockedRider->update($profileChanges);

            $lockedRequest->documents()->update([
                'verification_status' => 'VERIFIED',
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);
            $lockedRequest->update([
                'status' => 'APPROVED',
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return back()->with('status', 'Rider profile update approved and verified details applied.');
    }

    public function rejectRiderProfileUpdate(
        Request $request,
        RiderProfile $rider,
        RiderProfileUpdateRequest $profileUpdateRequest,
    ): RedirectResponse {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 404);
        abort_unless((int) $profileUpdateRequest->rider_profile_id === (int) $rider->id, 404);
        $data = $request->validate(['review_notes' => ['required', 'string', 'min:3', 'max:1000']]);

        DB::transaction(function () use ($request, $profileUpdateRequest, $data): void {
            $lockedRequest = RiderProfileUpdateRequest::query()
                ->whereKey($profileUpdateRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedRequest->status === 'PENDING', 409, 'This profile update has already been reviewed.');

            $lockedRequest->documents()->update([
                'verification_status' => 'REJECTED',
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now(),
                'rejection_reason' => trim($data['review_notes']),
            ]);
            $lockedRequest->update([
                'status' => 'REJECTED',
                'review_notes' => trim($data['review_notes']),
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return back()->with('status', 'Rider profile update rejected. The current verified profile remains unchanged.');
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
        ]);
        if (in_array($rider->vehicleRole(), [\App\Support\RiderVehicleType::ROLE_INTER_LOGISTICS], true)
            && (blank($data['approved_parcel_capacity'] ?? null)
                || blank($data['approved_weight_capacity_kg'] ?? null))) {
            return back()->withErrors(['capacity' => 'Inter-logistics riders require approved parcel and weight capacities.'])->withInput();
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
            ]);
        });

        $message = RiderVehicleType::isLocalBarangay($rider->vehicle_type)
            ? 'Rider local-trip parcel capacity saved.'
            : 'Rider transfer capacity saved as an approved hub setting.';

        return back()->with('status', $message);
    }

    public function reassignRiderArea(Request $request, RiderProfile $rider): RedirectResponse
    {
        $center = $request->user()->logisticsCenter?->load('address');
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);
        if (! RiderVehicleType::isLocalBarangay($rider->vehicle_type)) {
            return back()->withErrors(['service_area_id' => 'Four-wheel inter-hub drivers cannot be assigned a local delivery area.']);
        }

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
        $request->merge(['reason' => trim((string) $request->input('reason'))]);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->saveRiderModeration($request, $rider, 'SUSPENDED', trim($data['reason']));
    }

    public function moderateRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'WARNED', 'SUSPENDED', 'BANNED'])],
            'reason' => ['required_unless:status,ACTIVE', 'nullable', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->saveRiderModeration(
            $request,
            $rider,
            $data['status'],
            trim((string) ($data['reason'] ?? 'Rider reactivated by the logistics center.')),
        );
    }

    public function warnRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        $this->ensureRiderInLogisticsCenter($request, $rider);
        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }
        if ($request->has('corrective_action')) {
            $request->merge(['corrective_action' => trim((string) $request->input('corrective_action'))]);
        }
        if ($request->has('evidence')) {
            $request->merge(['evidence' => trim((string) $request->input('evidence'))]);
        }

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(self::RIDER_WARNING_CATEGORIES))],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'corrective_action' => ['required', 'string', 'min:5', 'max:5000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);
        $categoryLabel = self::RIDER_WARNING_CATEGORIES[$data['category']];
        $reason = $data['reason'];
        $correctiveAction = $data['corrective_action'];
        $reference = $this->makeRiderModerationReference($rider, 'WARNING');

        $warningNumber = DB::transaction(function () use ($request, $rider, $data, $categoryLabel, $reason, $correctiveAction, $reference): int {
            $lockedRider = RiderProfile::query()
                ->with('user')
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureRiderInLogisticsCenter($request, $lockedRider);
            abort_unless(
                $lockedRider->user
                    && $lockedRider->user->status === User::STATUS_ACTIVE
                    && ! $lockedRider->user->isPermanentlyBanned()
                    && in_array($lockedRider->status, ['ACTIVE', 'WARNED'], true),
                422,
                'Only an active rider can receive a new warning.',
            );

            $warningQuery = AuditLog::query()
                ->where('event', 'rider.warned')
                ->where('auditable_type', RiderProfile::class)
                ->where('auditable_id', $lockedRider->id);
            $warningCount = (clone $warningQuery)->count();
            if ($warningCount >= User::MAX_ACCOUNT_WARNINGS) {
                throw ValidationException::withMessages([
                    'warning_limit' => 'This rider has reached the three-warning limit.',
                ]);
            }

            $duplicateWarning = (clone $warningQuery)
                ->get(['new_values'])
                ->first(function (AuditLog $warning) use ($data, $reason): bool {
                    $values = $warning->new_values ?? [];

                    return data_get($values, 'category') === $data['category']
                        && trim((string) data_get($values, 'reason')) === $reason
                        && ! in_array(data_get($values, 'warning_status', 'OPEN'), ['RESOLVED', 'CLOSED'], true);
                });
            if ($duplicateWarning) {
                throw ValidationException::withMessages([
                    'duplicate_warning' => 'This warning is already recorded and remains open for review.',
                ]);
            }

            $oldStatus = $lockedRider->status;
            $lockedRider->update(['status' => 'WARNED']);
            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'rider.warned',
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $lockedRider->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => [
                    'status' => 'WARNED',
                    'warning_status' => 'OPEN',
                    'warning_number' => $warningCount + 1,
                    'category' => $data['category'],
                    'category_label' => $categoryLabel,
                    'reason' => $reason,
                    'corrective_action' => $correctiveAction,
                    'reference' => $reference,
                    'evidence' => filled($data['evidence'] ?? null) ? $data['evidence'] : null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return $warningCount + 1;
        });

        $rider->refresh()->load('user');
        app(AccountNotificationService::class)->riderModerationChanged($rider, 'WARNED', $reason, [
            'category_label' => $categoryLabel,
            'corrective_action' => $correctiveAction,
            'reference' => $reference,
        ]);

        return back()->with('status', 'Rider warning '.$warningNumber.' of '.User::MAX_ACCOUNT_WARNINGS.' recorded. Reference: '.$reference);
    }

    public function suspendRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        $this->ensureRiderInLogisticsCenter($request, $rider);
        if (! User::accountEnforcementSchemaReady()) {
            return back()->withErrors(['account_access' => 'Temporary suspension controls are unavailable until the account enforcement migration is applied.']);
        }
        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }
        if ($request->has('evidence')) {
            $request->merge(['evidence' => trim((string) $request->input('evidence'))]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'duration' => ['required', Rule::in(array_keys(self::RIDER_SUSPENSION_DURATIONS))],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);
        $duration = self::RIDER_SUSPENSION_DURATIONS[$data['duration']];
        $suspendedUntil = $duration['unit'] === 'week'
            ? now()->addWeeks($duration['count'])
            : now()->addMonths($duration['count']);
        $reason = $data['reason'];
        $reference = $this->makeRiderModerationReference($rider, 'SUSPENSION');

        DB::transaction(function () use ($request, $rider, $data, $duration, $suspendedUntil, $reason, $reference): void {
            $lockedRider = RiderProfile::query()
                ->with('user')
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureRiderInLogisticsCenter($request, $lockedRider);
            abort_unless(
                $lockedRider->user
                    && $lockedRider->user->status === User::STATUS_ACTIVE
                    && ! $lockedRider->user->isPermanentlyBanned()
                    && in_array($lockedRider->status, ['ACTIVE', 'WARNED'], true),
                422,
                'Only an active rider can be temporarily suspended.',
            );

            $oldValues = [
                'status' => $lockedRider->status,
                'user_status' => $lockedRider->user->status,
                'suspended_until' => $lockedRider->user->suspended_until?->toISOString(),
            ];
            $lockedRider->update(['status' => 'SUSPENDED', 'is_online' => false]);
            $lockedRider->user->forceFill([
                'status' => User::STATUS_SUSPENDED,
                'suspended_until' => $suspendedUntil,
            ])->save();

            $this->releaseUncollectedPickupAssignments($lockedRider);
            $this->releaseUncollectedDeliveryAssignments($lockedRider);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'rider.suspended',
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $lockedRider->id,
                'old_values' => $oldValues,
                'new_values' => [
                    'status' => 'SUSPENDED',
                    'duration' => $data['duration'],
                    'duration_label' => $duration['label'],
                    'suspended_until' => $suspendedUntil->toISOString(),
                    'reason' => $reason,
                    'reference' => $reference,
                    'evidence' => filled($data['evidence'] ?? null) ? $data['evidence'] : null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $rider->refresh()->load('user');
        app(AccountNotificationService::class)->riderModerationChanged($rider, 'SUSPENDED', $reason, [
            'suspended_until' => $suspendedUntil->format('M d, Y h:i A'),
            'reference' => $reference,
        ]);

        return back()->with('status', 'Rider suspended until '.$suspendedUntil->format('M d, Y h:i A').'. Reference: '.$reference);
    }

    public function reactivateRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        $this->ensureRiderInLogisticsCenter($request, $rider);
        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $reason = $data['reason'];
        $reference = $this->makeRiderModerationReference($rider, 'REACTIVATION');

        DB::transaction(function () use ($request, $rider, $reason, $reference): void {
            $lockedRider = RiderProfile::query()
                ->with('user')
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureRiderInLogisticsCenter($request, $lockedRider);
            abort_unless($lockedRider->user, 422, 'The rider account is unavailable.');
            abort_if(
                $lockedRider->user->isPermanentlyBanned() || $lockedRider->status === 'BANNED',
                422,
                'A permanently banned rider cannot be reactivated from this page.',
            );
            abort_unless(
                $lockedRider->status === 'SUSPENDED' || $lockedRider->user->status === User::STATUS_SUSPENDED,
                422,
                'Only a suspended rider can be reactivated.',
            );

            $oldValues = [
                'status' => $lockedRider->status,
                'user_status' => $lockedRider->user->status,
                'suspended_until' => $lockedRider->user->suspended_until?->toISOString(),
            ];
            $lockedRider->update(['status' => 'ACTIVE', 'is_online' => false]);
            $userChanges = ['status' => User::STATUS_ACTIVE];
            if (User::accountEnforcementSchemaReady()) {
                $userChanges['suspended_until'] = null;
            }
            $lockedRider->user->forceFill($userChanges)->save();

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'rider.reactivated',
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $lockedRider->id,
                'old_values' => $oldValues,
                'new_values' => ['status' => 'ACTIVE', 'reason' => $reason, 'reference' => $reference],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $rider->refresh()->load('user');
        app(AccountNotificationService::class)->riderModerationChanged($rider, 'ACTIVE', $reason, ['reference' => $reference]);

        return back()->with('status', 'Rider reactivated. Reference: '.$reference);
    }

    public function permanentlyBanRider(Request $request, RiderProfile $rider): RedirectResponse
    {
        $this->ensureRiderInLogisticsCenter($request, $rider);
        if (! User::accountEnforcementSchemaReady()) {
            return back()->withErrors(['account_access' => 'Permanent ban controls are unavailable until the account enforcement migration is applied.']);
        }
        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }
        if ($request->has('evidence')) {
            $request->merge(['evidence' => trim((string) $request->input('evidence'))]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);
        $reason = $data['reason'];
        $bannedAt = now();
        $reference = $this->makeRiderModerationReference($rider, 'BAN');

        DB::transaction(function () use ($request, $rider, $data, $reason, $bannedAt, $reference): void {
            $lockedRider = RiderProfile::query()
                ->with('user')
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureRiderInLogisticsCenter($request, $lockedRider);
            abort_unless($lockedRider->user, 422, 'The rider account is unavailable.');
            abort_unless(! $lockedRider->user->isPermanentlyBanned(), 422, 'This rider is already permanently banned.');

            $oldValues = [
                'status' => $lockedRider->status,
                'user_status' => $lockedRider->user->status,
                'suspended_until' => $lockedRider->user->suspended_until?->toISOString(),
            ];
            $lockedRider->update(['status' => 'BANNED', 'is_online' => false]);
            $lockedRider->user->forceFill([
                'status' => User::STATUS_DEACTIVATED,
                'suspended_until' => null,
                'permanently_banned_at' => $bannedAt,
                'api_token_hash' => null,
                'api_token_expires_at' => null,
                'remember_token' => null,
            ])->save();

            $this->releaseUncollectedPickupAssignments($lockedRider);
            $this->releaseUncollectedDeliveryAssignments($lockedRider);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'rider.banned',
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $lockedRider->id,
                'old_values' => $oldValues,
                'new_values' => [
                    'status' => 'PERMANENTLY_BANNED',
                    'account_status' => User::STATUS_DEACTIVATED,
                    'permanently_banned_at' => $bannedAt->toISOString(),
                    'reason' => $reason,
                    'reference' => $reference,
                    'evidence' => filled($data['evidence'] ?? null) ? $data['evidence'] : null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $rider->refresh()->load('user');
        app(AccountNotificationService::class)->riderModerationChanged($rider, 'BANNED', $reason, ['reference' => $reference]);

        return back()->with('status', 'Rider permanently banned. Reference: '.$reference);
    }

    private function ensureRiderInLogisticsCenter(Request $request, RiderProfile $rider): void
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);
    }

    private function makeRiderModerationReference(RiderProfile $rider, string $action): string
    {
        return 'RID-'.$rider->id.'-'.$action.'-'.Str::upper((string) Str::ulid());
    }

    private function saveRiderModeration(Request $request, RiderProfile $rider, string $status, string $reason): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center && (int) $rider->logistics_center_id === (int) $center->id, 403);

        $riderId = (int) $rider->id;
        $userStatus = match ($status) {
            'SUSPENDED' => 'SUSPENDED',
            'BANNED' => 'DEACTIVATED',
            default => 'ACTIVE',
        };

        DB::transaction(function () use ($request, $riderId, $status, $userStatus, $reason): void {
            $rider = RiderProfile::query()
                ->with('user')
                ->whereKey($riderId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if(
                $status === 'ACTIVE' && ($rider->status === 'BANNED' || $rider->user?->isPermanentlyBanned()),
                422,
                'A permanently banned rider cannot be reactivated from this page.',
            );
            $oldStatus = $rider->status;
            $rider->update([
                'status' => $status,
                'is_online' => in_array($status, ['ACTIVE', 'WARNED'], true) ? $rider->is_online : false,
            ]);
            $rider->user?->update(['status' => $userStatus]);

            if (in_array($status, ['SUSPENDED', 'BANNED'], true)) {
                $this->releaseUncollectedPickupAssignments($rider);
                $this->releaseUncollectedDeliveryAssignments($rider);
            }

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

    private function releaseUncollectedPickupAssignments(RiderProfile $rider): void
    {
        $assignments = $rider->assignments()
            ->pickup()
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->whereHas('shipment', fn ($query) => $query->where('current_status', 'PICKUP_ASSIGNED'))
            ->with('localTripItems.trip')
            ->lockForUpdate()
            ->get();

        $tripIds = [];
        foreach ($assignments as $assignment) {
            $shipment = $assignment->shipment;
            $assignment->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'rejection_reason' => 'Released to the pickup queue because the rider was suspended.',
            ]);

            if ($shipment?->current_status === 'PICKUP_ASSIGNED') {
                $shipment->update(['current_status' => 'READY_FOR_PICKUP']);
                \App\Models\Logistics\ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'status' => 'READY_FOR_PICKUP',
                    'actor_user_id' => auth()->id(),
                    'logistics_center_id' => $rider->logistics_center_id,
                    'notes' => 'Pickup assignment released because the rider was suspended; parcel returned to the pickup queue.',
                    'occurred_at' => now(),
                ]);
            }

            foreach ($assignment->localTripItems as $item) {
                $item->update([
                    'status' => \App\Models\Rider\RiderLocalTripItem::CANCELLED,
                    'failure_reason' => 'Rider suspended before pickup was completed.',
                ]);
                $tripIds[] = (int) $item->rider_local_trip_id;
            }
        }

        foreach (array_unique($tripIds) as $tripId) {
            $trip = \App\Models\Rider\RiderLocalTrip::query()->with('items')->find($tripId);
            if (! $trip || $trip->items->contains(fn ($item) => ! in_array($item->status, ['CANCELLED', 'COMPLETED', 'FAILED'], true))) {
                continue;
            }

            if ($trip->seller_profile_id && \Illuminate\Support\Facades\Schema::hasTable('seller_pickup_requests')) {
                \App\Models\Seller\SellerPickupRequest::query()
                    ->where('rider_local_trip_id', $trip->id)
                    ->whereIn('status', ['ASSIGNED', 'IN_PROGRESS'])
                    ->update(['status' => 'REQUESTED', 'rider_local_trip_id' => null]);
            }

            if (! in_array($trip->status, ['COMPLETED', 'CANCELLED', 'EXCEPTION'], true)) {
                $trip->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
            }
        }
    }

    private function releaseUncollectedDeliveryAssignments(RiderProfile $rider): void
    {
        $assignments = $rider->assignments()
            ->delivery()
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED'])
            ->whereHas('shipment', fn ($query) => $query->where('current_status', 'ASSIGNED_TO_RIDER'))
            ->with('shipment')
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $shipment = $assignment->shipment;
            $assignment->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'rejection_reason' => 'Released to the sorted dispatch queue because the rider was suspended before taking custody.',
            ]);

            if ($shipment?->current_status !== 'ASSIGNED_TO_RIDER') {
                continue;
            }

            $shipment->update(['current_status' => 'SORTED']);
            \App\Models\Logistics\ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'status' => 'SORTED',
                'actor_user_id' => auth()->id(),
                'logistics_center_id' => $rider->logistics_center_id,
                'notes' => 'Delivery assignment released to the sorted dispatch queue because the Rider was suspended before taking parcel custody.',
                'occurred_at' => now(),
            ]);

            if (Schema::hasTable('cod_collections') && Schema::hasColumn('cod_collections', 'rider_profile_id')) {
                CodCollection::query()
                    ->where('shipment_id', $shipment->id)
                    ->where('rider_profile_id', $rider->id)
                    ->where('status', CodCollection::PENDING)
                    ->update(['rider_profile_id' => null]);
            }
        }
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
            } elseif ($this->localRiderEligibility->singleBarangayLocation($center, $area) === null) {
                $reason = in_array($locations->first()->coverage_type, ['SECONDARY', 'EXTENDED'], true)
                    ? 'This fallback PSGC area has no currently approved active route.'
                    : 'The existing barangay PSGC does not match the center canonical province and municipality.';
            }

            return $reason ? [$area->id => $reason] : [];
        });

        return view('logistics.delivery-areas.index', compact('areas', 'riders', 'center', 'centerCanonicalMunicipality', 'uncoveredBarangays', 'assignableAreaIds', 'invalidAreaReasons'));
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
                allowExplicitRemoteCoverage: (bool) config('likhae.automatic_seller_pickup_matching', false),
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

        $financeReady = app(FinanceSchemaReadiness::class)->parcelSettlementReady();
        $financialReports = app(LogisticsFinancialReportService::class);
        $filters = $this->financialReportFilters($request, $center);
        $analyticsFilters = $this->analyticsReportFilters($request, $center);
        $shipmentStatus = $request->validate([
            'shipment_status' => ['nullable', Rule::in(Shipment::STATUSES)],
        ])['shipment_status'] ?? null;

        $analyticsDateFilters = [
            'from_date' => $analyticsFilters['from_date'],
            'to_date' => $analyticsFilters['to_date'],
        ];
        $analyticsShipments = $shipmentQuery->dated($shipmentQuery->operationalFor($center), $analyticsDateFilters);
        if ($analyticsFilters['area_id'] !== null) {
            $analyticsShipments->where('service_area_id', $analyticsFilters['area_id']);
        }
        if ($analyticsFilters['rider_id'] !== null) {
            $analyticsShipments->whereHas('riderAssignments', fn ($assignments) => $assignments
                ->where('assignment_type', 'DELIVERY')
                ->where('rider_profile_id', $analyticsFilters['rider_id'])
                ->whereHas('riderProfile', fn ($riders) => $riders->where('logistics_center_id', $center->id)));
        }

        $statusTotals = (clone $analyticsShipments)
            ->selectRaw('current_status, COUNT(*) AS total')
            ->groupBy('current_status')
            ->pluck('total', 'current_status')
            ->map(fn ($total): int => (int) $total);
        $categoryTotals = $shipmentQuery->counts($analyticsShipments);
        $analyticsTotal = (clone $analyticsShipments)->count();
        $closedOutcomes = (int) ($categoryTotals['delivered'] ?? 0) + (int) ($categoryTotals['failed'] ?? 0);
        $exceptionTotal = (int) ($categoryTotals['failed'] ?? 0) + (int) ($statusTotals['CANCELLED'] ?? 0);
        $analyticsMetrics = [
            'total' => $analyticsTotal,
            'pending' => (int) ($categoryTotals['pending'] ?? 0),
            'in_transit' => (int) ($categoryTotals['in_transit'] ?? 0),
            'delivered' => (int) ($categoryTotals['delivered'] ?? 0),
            'exceptions' => $exceptionTotal,
            'success_rate' => $closedOutcomes > 0 ? round(((int) ($categoryTotals['delivered'] ?? 0) / $closedOutcomes) * 100, 1) : 0.0,
            'riders' => $center->riders()->count(),
        ];

        $timezone = config('app.timezone', 'Asia/Manila');
        $rangeStart = CarbonImmutable::createFromFormat('!Y-m-d', $analyticsFilters['from_date'], $timezone);
        $rangeEnd = CarbonImmutable::createFromFormat('!Y-m-d', $analyticsFilters['to_date'], $timezone);
        $rangeDays = $rangeStart->diffInDays($rangeEnd) + 1;
        $granularity = $rangeDays <= 90 ? 'day' : ($rangeDays <= 366 ? 'week' : 'month');
        $bucketStart = match ($granularity) {
            'week' => $rangeStart->startOfWeek(),
            'month' => $rangeStart->startOfMonth(),
            default => $rangeStart,
        };
        $dailyTotals = (clone $analyticsShipments)
            ->selectRaw('DATE(shipments.created_at) AS report_day, COUNT(*) AS total')
            ->groupBy('report_day')
            ->orderBy('report_day')
            ->pluck('total', 'report_day');
        $bucketTotals = [];
        foreach ($dailyTotals as $day => $total) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $day, $timezone);
            $bucketDate = match ($granularity) {
                'week' => $date->startOfWeek(),
                'month' => $date->startOfMonth(),
                default => $date,
            };
            $key = $bucketDate->toDateString();
            $bucketTotals[$key] = ($bucketTotals[$key] ?? 0) + (int) $total;
        }

        $analyticsTrend = [];
        $cursor = $bucketStart;
        $stepMethod = match ($granularity) {
            'week' => 'addWeek',
            'month' => 'addMonth',
            default => 'addDay',
        };
        while ($cursor <= $rangeEnd) {
            $key = $cursor->toDateString();
            $analyticsTrend[] = [
                'date' => $key,
                'label' => match ($granularity) {
                    'week' => $cursor->format('M j'),
                    'month' => $cursor->format('M Y'),
                    default => $cursor->format('M j'),
                },
                'total' => (int) ($bucketTotals[$key] ?? 0),
            ];
            $cursor = $cursor->{$stepMethod}();
        }
        $chartMax = max(array_column($analyticsTrend, 'total'));
        $chartScale = max(1, $chartMax);
        $chartPoints = [];
        $pointCount = count($analyticsTrend);
        foreach ($analyticsTrend as $index => $point) {
            $chartPoints[] = [
                'x' => round(34 + ($pointCount > 1 ? ($index / ($pointCount - 1)) * 832 : 416), 1),
                'y' => round(174 - (((int) $point['total'] / $chartScale) * 136), 1),
                'label' => $point['label'],
                'total' => $point['total'],
            ];
        }
        $linePath = implode(' ', array_map(
            fn (array $point, int $index): string => ($index === 0 ? 'M ' : 'L ').$point['x'].' '.$point['y'],
            $chartPoints,
            array_keys($chartPoints),
        ));
        $areaPath = $chartPoints === [] ? '' : 'M '.$chartPoints[0]['x'].' 174 L '.implode(' ', array_map(
            fn (array $point): string => $point['x'].' '.$point['y'],
            $chartPoints,
        )).' L '.$chartPoints[array_key_last($chartPoints)]['x'].' 174 Z';
        $statusBreakdown = $statusTotals->map(fn (int $total, string $status): array => [
            'label' => (string) str($status)->replace('_', ' ')->headline(),
            'total' => $total,
        ])->sortByDesc('total')->values();
        $analyticsFinancialSummary = $financeReady ? $financialReports->summary($center, [
            'date_from' => $analyticsFilters['from_date'],
            'date_to' => $analyticsFilters['to_date'],
            'rider_id' => $analyticsFilters['rider_id'],
            'area_id' => $analyticsFilters['area_id'],
            'status' => null,
        ]) : [];

        return view('logistics.reports.index', [
            'center' => $center,
            'activeTab' => $request->query('tab') === 'reports' ? 'reports' : 'analytics',
            'analyticsFilters' => $analyticsFilters,
            'analyticsMetrics' => $analyticsMetrics,
            'analyticsTrend' => $analyticsTrend,
            'analyticsChartPoints' => $chartPoints,
            'analyticsChartLine' => $linePath,
            'analyticsChartArea' => $areaPath,
            'analyticsChartMax' => $chartMax,
            'analyticsGranularity' => $granularity,
            'analyticsStatusBreakdown' => $statusBreakdown,
            'analyticsFinancialSummary' => $analyticsFinancialSummary,
            'financeReady' => $financeReady,
            'financialFilters' => $filters,
            'financialSummary' => $financeReady ? $financialReports->summary($center, $filters) : [],
            'financialStatuses' => $financeReady ? $financialReports->statuses($center) : [],
            'financialRiders' => $center->riders()->with('user')->orderBy('id')->get(),
            'financialAreas' => $center->serviceAreas()->orderBy('name')->get(),
            'shipmentStatus' => $shipmentStatus,
            'shipmentStatusOptions' => Shipment::STATUSES,
            'shipmentExportFilters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'area_id' => $filters['area_id'] ?? null,
                'rider_id' => $filters['rider_id'] ?? null,
                'shipment_status' => $shipmentStatus,
            ],
            'financialTransactions' => $financeReady
                ? $financialReports->query($center, $filters)->paginate(25)->withQueryString()
                    ->through(fn (FinancialLedgerEntry $entry): array => $financialReports->row($entry))
                : null,
        ]);
    }

    /** @return array{period:string,from_date:string,to_date:string,rider_id:?int,area_id:?int} */
    private function analyticsReportFilters(Request $request, LogisticsCenter $center): array
    {
        $validated = $request->validate([
            'analytics_period' => ['nullable', Rule::in(['7d', '30d', '90d', 'custom'])],
            'analytics_from' => ['nullable', 'date_format:Y-m-d'],
            'analytics_to' => ['nullable', 'date_format:Y-m-d'],
            'analytics_rider_id' => [
                'nullable',
                'integer',
                Rule::exists('rider_profiles', 'id')->where(fn ($query) => $query->where('logistics_center_id', $center->id)),
            ],
            'analytics_area_id' => [
                'nullable',
                'integer',
                Rule::exists('service_areas', 'id')->where(fn ($query) => $query->where('logistics_center_id', $center->id)),
            ],
        ]);

        $period = $validated['analytics_period'] ?? '30d';
        $today = CarbonImmutable::now(config('app.timezone', 'Asia/Manila'))->startOfDay();
        if ($period === 'custom') {
            $from = $validated['analytics_from'] ?? $today->subDays(29)->toDateString();
            $to = $validated['analytics_to'] ?? $today->toDateString();
            if ($to < $from) {
                throw ValidationException::withMessages(['analytics_to' => 'The end date must be on or after the start date.']);
            }
        } else {
            $days = match ($period) {
                '7d' => 7,
                '90d' => 90,
                default => 30,
            };
            $from = $today->subDays($days - 1)->toDateString();
            $to = $today->toDateString();
        }

        return [
            'period' => $period,
            'from_date' => $from,
            'to_date' => $to,
            'rider_id' => filled($validated['analytics_rider_id'] ?? null) ? (int) $validated['analytics_rider_id'] : null,
            'area_id' => filled($validated['analytics_area_id'] ?? null) ? (int) $validated['analytics_area_id'] : null,
        ];
    }

    public function exportFinancialTransactionsCsv(Request $request, LogisticsFinancialReportService $reports)
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        abort_unless(app(FinanceSchemaReadiness::class)->parcelSettlementReady(), 503, 'Financial reporting is unavailable until the reviewed finance migrations are installed.');
        $filters = $this->financialReportFilters($request, $center);
        $filename = 'logistics-transactions-'.$center->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($reports, $center, $filters): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            $reports->writeCsvToStream($stream, $center, $filters);

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportFinancialTransactionsPdf(Request $request, LogisticsFinancialReportService $reports, WorkspaceExportPdfService $pdf)
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        abort_unless(app(FinanceSchemaReadiness::class)->parcelSettlementReady(), 503, 'Financial reporting is unavailable until the reviewed finance migrations are installed.');
        $filters = $this->financialReportFilters($request, $center);
        $summary = $reports->summary($center, $filters);
        $rows = $reports->query($center, $filters)
            ->get()
            ->map(fn (FinancialLedgerEntry $entry): array => $reports->pdfRow($entry));

        $filename = 'logistics-transactions-'.$center->id.'-'.now()->format('Ymd-His').'.pdf';

        return response($pdf->render([
            'workspace' => 'Logistics Portal',
            'title' => 'Parcel transactions and shipping share',
            'description' => 'Persisted Logistics ledger entries for '.$center->business_name.'. Admin product commission is shown separately; no Logistics commission policy is configured.',
            'generated_at' => now()->format('Y-m-d H:i:s T'),
            'orientation' => 'landscape',
            'filters' => [
                'From' => $filters['date_from'] ?? '',
                'To' => $filters['date_to'] ?? '',
                'Status' => $filters['status'] ?? '',
                'Rider ID' => $filters['rider_id'] ?? '',
                'Area ID' => $filters['area_id'] ?? '',
            ],
            'summary' => [
                'Parcels with allocation' => (string) $summary['parcel_count'],
                'Base shipping charges' => 'PHP '.number_format($summary['shipping_fees'], 2),
                'Additional Care charges' => 'PHP '.number_format($summary['additional_care'], 2),
                'Combined delivery charges' => 'PHP '.number_format($summary['delivery_charges'], 2),
                'Logistics delivery share' => 'PHP '.number_format($summary['logistics_share'], 2),
                'Admin product commission' => 'PHP '.number_format($summary['admin_product_commission'], 2),
                'Product proceeds expected / received' => 'PHP '.number_format($summary['proceeds_expected'], 2).' / PHP '.number_format($summary['proceeds_received'], 2),
                'Unresolved proceeds discrepancies' => (string) $summary['proceeds_discrepancies'],
                'Payout requested / pending / paid' => implode(' / ', [
                    'PHP '.number_format($summary['payout_requested'], 2),
                    'PHP '.number_format($summary['payout_pending_ack'], 2),
                    'PHP '.number_format($summary['payout_paid'], 2),
                ]),
            ],
            'columns' => [
                ['label' => 'Date', 'width' => 0.09],
                ['label' => 'Tracking / order', 'width' => 0.14],
                ['label' => 'Transaction', 'width' => 0.10],
                ['label' => 'Amount', 'width' => 0.08],
                ['label' => 'Status', 'width' => 0.09],
                ['label' => 'Base shipping', 'width' => 0.08],
                ['label' => 'Additional Care', 'width' => 0.08],
                ['label' => 'Combined delivery', 'width' => 0.10],
                ['label' => 'Logistics share', 'width' => 0.08],
                ['label' => 'Rider pass-through', 'width' => 0.08],
                ['label' => 'Admin commission', 'width' => 0.08],
            ],
            'rows' => $rows,
            'empty_message' => 'No financial transactions match these filters.',
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array{date_from:?string,date_to:?string,status:?string,rider_id:?int,area_id:?int} */
    private function financialReportFilters(Request $request, LogisticsCenter $center): array
    {
        $filterKeys = ['date_from', 'date_to', 'status', 'rider_id', 'area_id'];
        if (! collect($filterKeys)->contains(fn (string $key): bool => $request->query->has($key))) {
            $request->merge([
                'date_from' => now()->subDays(29)->toDateString(),
                'date_to' => now()->toDateString(),
            ]);
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'max:32'],
            'rider_id' => [
                'nullable',
                'integer',
                Rule::exists('rider_profiles', 'id')->where(fn ($query) => $query->where('logistics_center_id', $center->id)),
            ],
            'area_id' => [
                'nullable',
                'integer',
                Rule::exists('service_areas', 'id')->where(fn ($query) => $query->where('logistics_center_id', $center->id)),
            ],
        ]);

        return [
            'date_from' => filled($validated['date_from'] ?? null) ? $validated['date_from'] : null,
            'date_to' => filled($validated['date_to'] ?? null) ? $validated['date_to'] : null,
            'status' => filled($validated['status'] ?? null) ? $validated['status'] : null,
            'rider_id' => filled($validated['rider_id'] ?? null) ? (int) $validated['rider_id'] : null,
            'area_id' => filled($validated['area_id'] ?? null) ? (int) $validated['area_id'] : null,
        ];
    }

    /** @return array{query:\Illuminate\Database\Eloquent\Builder,filters:array<string,mixed>,shipment_status:?string} */
    private function shipmentReportQuery(Request $request, LogisticsCenter $center, LogisticsShipmentQuery $shipmentQuery): array
    {
        $filters = $this->financialReportFilters($request, $center);
        $shipmentStatus = $request->validate([
            'shipment_status' => ['nullable', Rule::in(Shipment::STATUSES)],
        ])['shipment_status'] ?? null;
        $query = $shipmentQuery->dated($shipmentQuery->operationalFor($center), [
            'from_date' => $filters['date_from'],
            'to_date' => $filters['date_to'],
        ]);
        if ($filters['area_id'] !== null) {
            $query->where('service_area_id', $filters['area_id']);
        }
        if ($filters['rider_id'] !== null) {
            $query->whereHas('riderAssignments', fn ($assignments) => $assignments
                ->where('assignment_type', 'DELIVERY')
                ->where('rider_profile_id', $filters['rider_id'])
                ->whereHas('riderProfile', fn ($riders) => $riders->where('logistics_center_id', $center->id)));
        }
        if ($shipmentStatus !== null) {
            $query->where('current_status', $shipmentStatus);
        }

        return [
            'query' => $query->with([
                'serviceArea',
                'riderAssignments' => fn ($assignments) => $assignments
                    ->where('assignment_type', 'DELIVERY')
                    ->whereHas('riderProfile', fn ($riders) => $riders->where('logistics_center_id', $center->id))
                    ->orderByDesc('id'),
                'riderAssignments.riderProfile.user',
            ]),
            'filters' => $filters,
            'shipment_status' => $shipmentStatus,
        ];
    }

    public function exportShipmentsCsv(Request $request, LogisticsShipmentQuery $shipmentQuery)
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $report = $this->shipmentReportQuery($request, $center, $shipmentQuery);
        $filename = 'logistics-shipments-'.$center->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            fputcsv($stream, ['Tracking number', 'Current status', 'Service area', 'Destination', 'Delivery rider', 'Created at', 'Last updated']);
            $report['query']->reorder()->lazyById(500, 'shipments.id', 'id')->each(function (Shipment $shipment) use ($stream): void {
                fputcsv($stream, [
                    (string) $shipment->tracking_number,
                    (string) $shipment->current_status,
                    (string) ($shipment->serviceArea?->name ?? ''),
                    collect([
                        $shipment->destination_barangay_name,
                        $shipment->destination_municipality_name,
                        $shipment->destination_province_name,
                    ])->filter()->implode(', '),
                    (string) ($shipment->riderAssignments?->first()?->riderProfile?->user?->name ?? ''),
                    (string) ($shipment->created_at?->toDateTimeString() ?? ''),
                    (string) ($shipment->updated_at?->toDateTimeString() ?? ''),
                ]);
            });
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportReport(Request $request, LogisticsShipmentQuery $shipmentQuery, WorkspaceExportPdfService $pdf)
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $report = $this->shipmentReportQuery($request, $center, $shipmentQuery);
        $filters = $report['filters'];
        $shipmentStatus = $report['shipment_status'];
        $rows = $report['query']
            ->reorder()
            ->lazyById(500, 'shipments.id', 'id')
            ->map(fn (Shipment $shipment): array => [
                (string) $shipment->tracking_number,
                (string) $shipment->current_status,
                (string) ($shipment->serviceArea?->name ?? ''),
                collect([
                    $shipment->destination_barangay_name,
                    $shipment->destination_municipality_name,
                    $shipment->destination_province_name,
                ])->filter()->implode(', '),
                (string) ($shipment->riderAssignments?->first()?->riderProfile?->user?->name ?? ''),
                (string) ($shipment->created_at?->toDateTimeString() ?? ''),
                (string) ($shipment->updated_at?->toDateTimeString() ?? ''),
            ]);
        $filename = 'logistics-report-'.$center->id.'-'.now()->format('Ymd-His').'.pdf';

        return response($pdf->render([
            'workspace' => 'Logistics Portal',
            'title' => 'Shipment operations report',
            'description' => 'Operational shipments visible to '.$center->business_name.'.',
            'generated_at' => now()->format('Y-m-d H:i:s T'),
            'filters' => [
                'From' => $filters['date_from'] ?? '',
                'To' => $filters['date_to'] ?? '',
                'Status' => $shipmentStatus ?? '',
                'Delivery rider ID' => $filters['rider_id'] ?? '',
                'Service area ID' => $filters['area_id'] ?? '',
            ],
            'orientation' => 'landscape',
            'columns' => [
                ['label' => 'Tracking', 'width' => 0.15],
                ['label' => 'Status', 'width' => 0.10],
                ['label' => 'Service area', 'width' => 0.14],
                ['label' => 'Destination', 'width' => 0.20],
                ['label' => 'Delivery rider', 'width' => 0.14],
                ['label' => 'Created', 'width' => 0.13],
                ['label' => 'Updated', 'width' => 0.14],
            ],
            'rows' => $rows,
            'empty_message' => 'No operational shipments are available to this logistics center.',
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function profile(Request $request, PayoutDestinationReadiness $destinations, PayoutDestinationManager $manager): View
    {
        $user = $request->user();
        $center = $user->logisticsCenter?->load('address');
        abort_unless($center, 403);
        $verificationApplication = $user->sellerVerificationApplication();
        $verificationApplication?->load('documents');
        $verificationDocuments = $verificationApplication?->documents
            ->groupBy(fn (ApplicationDocument $document): string => strtoupper((string) $document->document_type))
            ->map(fn ($versions): ApplicationDocument => $versions->sortByDesc('id')->first()) ?? collect();
        $adminRequestedDocumentUpdateIds = AuditLog::query()
            ->where('event', 'logistics.document_update_requested_by_admin')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->get(['new_values'])
            ->map(fn (AuditLog $entry): int => (int) data_get($entry->new_values, 'document_id'))
            ->filter()
            ->flip()
            ->all();
        $adminMissingDocumentRequestsByType = AuditLog::query()
            ->where('event', 'logistics.document_requirement_requested_by_admin')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->latest('id')
            ->get()
            ->unique(fn (AuditLog $entry): string => strtoupper((string) data_get($entry->new_values, 'document_type')))
            ->keyBy(fn (AuditLog $entry): string => strtoupper((string) data_get($entry->new_values, 'document_type')));
        $payoutDestinationReady = $destinations->readyFor('logistics_centers');
        $payoutDestinationSaved = $payoutDestinationReady && $destinations->hasDestination($center);
        $payoutMultipleReady = $destinations->supportsMultipleFor('logistics_centers');
        $payoutDestinations = $payoutMultipleReady ? $manager->accounts($center) : [];
        $verificationDocumentTypes = array_values(ApplicationDocument::LOGISTICS_UPLOAD_FIELDS);

        return view('logistics.profile.index', compact(
            'center',
            'user',
            'verificationApplication',
            'verificationDocuments',
            'verificationDocumentTypes',
            'adminRequestedDocumentUpdateIds',
            'adminMissingDocumentRequestsByType',
            'payoutDestinationReady',
            'payoutDestinationSaved',
            'payoutMultipleReady',
            'payoutDestinations',
        ));
    }

    public function submitVerificationDocuments(Request $request): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $request->validate([
            'documents' => ['required', 'array:'.implode(',', array_keys(ApplicationDocument::LOGISTICS_UPLOAD_FIELDS))],
            'documents.*' => [
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'extensions:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
        ]);
        $files = collect($request->file('documents', []))
            ->filter(fn ($file): bool => $file instanceof \Illuminate\Http\UploadedFile)
            ->all();

        if ($files === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'documents' => 'Choose at least one required Logistics Center document to submit for review.',
            ]);
        }

        $storedPaths = [];
        try {
            DB::transaction(function () use ($request, $center, $files, &$storedPaths): void {
                $lockedUser = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $lockedCenter = LogisticsCenter::query()
                    ->whereKey($center->id)
                    ->where('owner_user_id', $lockedUser->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless(
                    $lockedUser->isAccountType(User::TYPE_LOGISTICS)
                        && strtoupper((string) $lockedUser->status) === User::STATUS_ACTIVE
                        && strtoupper((string) $lockedCenter->status) === User::STATUS_ACTIVE,
                    403,
                );

                $application = $lockedUser->sellerVerificationApplication();
                if ($application) {
                    $lockedApplication = RegistrationApplication::query()
                        ->whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                } else {
                    $lockedApplication = RegistrationApplication::create([
                        'application_number' => 'REG-LOGISTICS-LEGACY-'.$lockedUser->id.'-'.Str::upper(Str::random(8)),
                        'user_id' => $lockedUser->id,
                        'status' => RegistrationApplication::STATUS_APPROVED,
                        'submitted_at' => now(),
                        'reviewed_at' => now(),
                        'decision_notes' => 'Verification record created for an existing active Logistics Center because no registration application was available.',
                    ]);
                }

                $submittedDocuments = [];
                foreach ($files as $input => $file) {
                    $type = ApplicationDocument::LOGISTICS_UPLOAD_FIELDS[$input] ?? null;
                    if (! $type) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'documents' => 'Select a supported Logistics Center verification document.',
                        ]);
                    }

                    $latest = $lockedApplication->documents()
                        ->whereRaw('UPPER(document_type) = ?', [$type])
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();
                    if ($latest && strtoupper((string) $latest->verification_status) === 'PENDING') {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'documents' => ApplicationDocument::DOCUMENT_LABELS[$type].' is already waiting for Admin review.',
                        ]);
                    }

                    $directory = 'registration/logistics/'.$lockedApplication->application_number
                        .'/'.strtolower($type).'/updates';
                    $path = $file->store($directory, 'registrations');
                    if (! is_string($path)) {
                        throw new \RuntimeException('The verification document could not be stored.');
                    }
                    $storedPaths[] = $path;

                    $document = $lockedApplication->documents()->create([
                        'document_type' => $type,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'verification_status' => 'PENDING',
                    ]);
                    $submittedDocuments[] = [
                        'document_id' => $document->id,
                        'document_type' => $type,
                        'verification_status' => 'PENDING',
                    ];
                }

                AuditLog::create([
                    'actor_user_id' => $lockedUser->id,
                    'event' => 'logistics.document_update_requested',
                    'auditable_type' => User::class,
                    'auditable_id' => $lockedUser->id,
                    'new_values' => ['application_id' => $lockedApplication->id, 'documents' => $submittedDocuments],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('registrations')->delete($storedPaths);

            throw $exception;
        }

        return back()->with('status', 'Logistics Center documents submitted to Admin for review.');
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

        $data = $request->validate(PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules(allowApproximate: false));
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
            ...AddressCoordinateValidator::precisionAttributes($data, $center->address),
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
        $activeConversation = $conversations->firstWhere('id', $request->integer('conversation'));
        $activeRecipient = $activeConversation?->participants?->first(fn ($participant) => (int) $participant->id !== (int) $request->user()->id);
        $smartHelpBootstrap = $activeConversation && $activeRecipient?->isAccountType(User::TYPE_ADMIN)
            ? app(\App\Services\Communication\SmartMessagingService::class)->bootstrapForRequester($request->user(), $activeConversation)
            : null;

        return view('logistics.messages.index', [
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageContext' => app(ConversationContextPresenter::class)->forUser(
                $request->user(),
                $conversations->firstWhere('id', $request->integer('conversation')),
            ),
            'smartHelpBootstrap' => $smartHelpBootstrap,
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
