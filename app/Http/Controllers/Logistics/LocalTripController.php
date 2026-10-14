<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ServiceArea;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderProfile;
use App\Services\Fulfillment\LocalRiderEligibilityService;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class LocalTripController extends Controller
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly LocalRiderTripService $trips,
        private readonly LocalRiderEligibilityService $eligibility,
    ) {}

    public function index(Request $request): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        if (! $this->schema->localTripsReady()) {
            return view('Logistics.local-trips.index', [
                'center' => $center,
                'ready' => false,
                'trips' => collect(),
                'riders' => collect(),
                'areas' => collect(),
            ]);
        }

        $riders = $this->eligibility
            ->activeRidersForCenter($center)
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->with(['user', 'areaAssignments'])
            ->get();
        $eligibleRiderIds = $riders->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $areas = ServiceArea::query()
            ->where('logistics_center_id', $center->id)
            ->where('is_active', true)
            ->with([
                'locations',
                'riderAssignments' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereIn('rider_profile_id', $eligibleRiderIds),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (ServiceArea $area): bool =>
                $this->eligibility->singleBarangayLocation($center, $area) !== null
                && $area->riderAssignments->isNotEmpty()
            )
            ->values();

        return view('Logistics.local-trips.index', [
            'center' => $center,
            'ready' => true,
            'trips' => RiderLocalTrip::query()->where('logistics_center_id', $center->id)
                ->with('rider.user')->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])
                ->latest()->paginate(20),
            'riders' => $riders,
            'areas' => $areas,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer'],
            'service_area_id' => ['required', 'integer'],
            'trip_type' => ['required', 'in:DELIVERY'],
        ]);
        try {
            $trip = $this->trips->createTrip(
                $request->user(),
                RiderProfile::query()->findOrFail($data['rider_profile_id']),
                ServiceArea::query()->findOrFail($data['service_area_id']),
                $data['trip_type'],
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['trip' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('logistics.local-trips.show', $trip->id)->with('status', 'Local rider trip created in loading status.');
    }

    public function show(Request $request, int $trip): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        $this->assertReady();
        $trip = RiderLocalTrip::query()->where('logistics_center_id', $center->id)->with('rider.user', 'items.riderAssignment.shipment')->findOrFail($trip);
        $assignments = RiderAssignment::query()->where('rider_profile_id', $trip->rider_profile_id)
            ->where('assignment_type', $trip->trip_type)->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->with('shipment')->latest()->limit(100)->get();

        return view('Logistics.local-trips.show', compact('center', 'trip', 'assignments'));
    }

    public function addAssignment(Request $request, int $trip): RedirectResponse
    {
        $this->assertReady();
        $data = $request->validate(['rider_assignment_id' => ['required', 'integer']]);
        try {
            $tripModel = RiderLocalTrip::query()->findOrFail($trip);
            $this->trips->addAssignment($request->user(), $tripModel, RiderAssignment::query()->findOrFail($data['rider_assignment_id']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['rider_assignment_id' => $exception->getMessage()]);
        }
        return back()->with('status', 'Assignment added to the local trip load.');
    }

    public function load(Request $request, int $trip): RedirectResponse
    {
        $this->assertReady();
        try {
            $this->trips->loadTrip($request->user(), RiderLocalTrip::query()->findOrFail($trip));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['trip' => $exception->getMessage()]);
        }
        return back()->with('status', 'Local trip parcels scanned and load verified.');
    }

    public function close(Request $request, int $trip): RedirectResponse
    {
        $this->assertReady();
        $data = $request->validate(['exception' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $this->trips->closeTrip($request->user(), RiderLocalTrip::query()->findOrFail($trip), (bool) ($data['exception'] ?? false), $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['trip' => $exception->getMessage()]);
        }
        return back()->with('status', 'Local trip closed with an audit status.');
    }

    private function assertReady(): void
    {
        abort_unless($this->schema->localTripsReady(), 503, 'Local trip schema is not installed; local dispatch is disabled.');
    }
}
