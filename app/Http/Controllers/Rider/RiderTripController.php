<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderLocalTrip;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class RiderTripController extends Controller
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly LocalRiderTripService $trips,
    ) {}

    public function index(Request $request): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        $localTripsReady = $this->schema->localTripsReady();
        $trips = $localTripsReady
            ? RiderLocalTrip::query()->where('rider_profile_id', $rider->id)->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])->latest()->paginate(20)
            : collect();

        return view('Rider.trips', compact('rider', 'trips', 'localTripsReady'));
    }

    public function show(Request $request, int $trip): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless($this->schema->localTripsReady(), 503, 'Local trip schema is not installed.');
        $trip = RiderLocalTrip::query()->where('rider_profile_id', $rider->id)->with('items.riderAssignment.shipment')->findOrFail($trip);
        return view('Rider.trip-show', compact('rider', 'trip'));
    }

    public function load(Request $request, int $trip): RedirectResponse
    {
        return $this->run($request, $trip, fn (RiderLocalTrip $model) => $this->trips->loadTrip($request->user(), $model), 'Trip load verified.');
    }

    public function start(Request $request, int $trip): RedirectResponse
    {
        return $this->run($request, $trip, fn (RiderLocalTrip $model) => $this->trips->startTrip($request->user(), $model), 'Trip started. Use each loaded assignment action to begin pickup or delivery.');
    }

    private function run(Request $request, int $id, callable $action, string $message): RedirectResponse
    {
        abort_unless($this->schema->localTripsReady(), 503, 'Local trip schema is not installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        try {
            $action(RiderLocalTrip::query()->where('rider_profile_id', $rider->id)->findOrFail($id));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['trip' => $exception->getMessage()]);
        }
        return back()->with('status', $message);
    }
}
