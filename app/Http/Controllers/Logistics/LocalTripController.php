<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderLocalTrip;
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
    ) {}

    public function index(Request $request): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        if (! $this->schema->localTripsReady()) {
            return view('logistics.local-trips.index', [
                'center' => $center,
                'ready' => false,
                'trips' => collect(),
            ]);
        }

        return view('logistics.local-trips.index', [
            'center' => $center,
            'ready' => true,
            'trips' => RiderLocalTrip::query()->where('logistics_center_id', $center->id)
                ->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)
                ->with('rider.user')->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])
                ->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, int $trip): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        $this->assertReady();
        $trip = RiderLocalTrip::query()->where('logistics_center_id', $center->id)->with('rider.user', 'items.riderAssignment.shipment')->findOrFail($trip);

        return view('logistics.local-trips.show', compact('center', 'trip'));
    }

    public function load(Request $request, int $trip): RedirectResponse
    {
        $this->assertReady();
        try {
            $this->trips->loadTrip($request->user(), RiderLocalTrip::query()->findOrFail($trip));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['trip' => $exception->getMessage()]);
        }
        return back()->with('status', 'Local trip load verified.');
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
