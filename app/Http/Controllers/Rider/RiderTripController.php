<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderLocalTrip;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ? RiderLocalTrip::query()->where('rider_profile_id', $rider->id)->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])->latest()->paginate(20)
            : collect();

        return view('rider.trips', compact('rider', 'trips', 'localTripsReady'));
    }

    public function show(Request $request, int $trip): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless($this->schema->localTripsReady(), 503, 'Local trip schema is not installed.');
        $trip = RiderLocalTrip::query()->where('rider_profile_id', $rider->id)->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)->with('items.riderAssignment.shipment')->findOrFail($trip);
        return view('rider.trip-show', compact('rider', 'trip'));
    }

    public function load(Request $request, int $trip): RedirectResponse
    {
        return $this->run($request, $trip, fn (RiderLocalTrip $model) => $this->trips->loadTrip($request->user(), $model), 'Trip load verified.');
    }

    public function accept(Request $request, int $trip, ShipmentWorkflowService $workflow): RedirectResponse
    {
        return $this->run($request, $trip, function (RiderLocalTrip $model) use ($request, $workflow): void {
            DB::transaction(function () use ($model, $request, $workflow): void {
                $trip = RiderLocalTrip::query()
                    ->where('rider_profile_id', $request->user()->riderProfile->id)
                    ->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)
                    ->with('items.riderAssignment')
                    ->findOrFail($model->id);
                if (! in_array($trip->status, [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS], true)) {
                    throw new RuntimeException('Only an active delivery trip can be accepted.');
                }
                $activeItems = $trip->items->whereIn('status', ['QUEUED', 'LOADED']);
                if ($activeItems->isEmpty()) {
                    throw new RuntimeException('This trip has no active parcels to accept.');
                }
                foreach ($activeItems as $item) {
                    $assignment = $item->riderAssignment;
                    if (! $assignment || $assignment->assignment_type !== 'DELIVERY') {
                        throw new RuntimeException('Every parcel on this trip must have a delivery assignment.');
                    }
                    if ($assignment->status === 'ASSIGNED') {
                        $workflow->riderTransition($assignment, 'accept', $request->user());
                    } elseif ($assignment->status !== 'ACCEPTED') {
                        throw new RuntimeException('Every active parcel must still be assigned or accepted before accepting the trip.');
                    }
                }
            });
        }, 'All delivery parcels on this trip have been accepted.');
    }

    public function start(Request $request, int $trip, ShipmentWorkflowService $workflow): RedirectResponse
    {
        return $this->run($request, $trip, function (RiderLocalTrip $model) use ($request, $workflow): void {
            DB::transaction(function () use ($model, $request, $workflow): void {
                $trip = RiderLocalTrip::query()
                    ->where('rider_profile_id', $request->user()->riderProfile->id)
                    ->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)
                    ->with('items.riderAssignment')
                    ->findOrFail($model->id);
                if (! in_array($trip->status, [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS], true)) {
                    throw new RuntimeException('This trip is not available to start.');
                }
                $activeItems = $trip->items->whereIn('status', ['QUEUED', 'LOADED']);
                if ($activeItems->isEmpty() || $activeItems->contains(fn ($item): bool =>
                    ! $item->riderAssignment || ! in_array($item->riderAssignment->status, ['ACCEPTED', 'IN_PROGRESS'], true))) {
                    throw new RuntimeException('Accept every delivery on this trip before starting the route.');
                }
                foreach ($activeItems as $item) {
                    if ($item->riderAssignment->status === 'ACCEPTED') {
                        $workflow->riderTransition($item->riderAssignment, 'start', $request->user());
                    }
                }
            });
        }, 'Trip started. All parcels are now out for delivery; open each parcel to record its delivery.');
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
