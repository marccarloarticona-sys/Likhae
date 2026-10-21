<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderLocalTrip;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Maps\MapDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class RiderPickupBatchController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(app(LogisticsSchemaReadiness::class)->sellerPickupBatchesReady(), 503, 'Seller pickup batch workflow is unavailable until its additive schema migration is installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        $batches = RiderLocalTrip::query()
            ->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->whereIn('status', [
                RiderLocalTrip::LOADING,
                RiderLocalTrip::READY,
                RiderLocalTrip::IN_PROGRESS,
                RiderLocalTrip::EN_ROUTE_TO_HUB,
            ])
            ->with(['seller.businessAddress', 'items.riderAssignment.shipment'])
            ->latest()
            ->paginate(15);

        return view('rider.pickups.index', compact('rider', 'batches'));
    }

    public function show(Request $request, int $trip): View
    {
        $pickup = $this->ownedBatch($request, $trip)
            ->load(['seller.user', 'seller.businessAddress', 'rider.user', 'logisticsCenter.address', 'items.riderAssignment.shipment.sellerOrder.items.product.images']);

        $items = $pickup->items;
        $total = $items->count();
        $collectedAssignmentIds = app(SellerPickupBatchService::class)->collectedAssignmentIds($pickup);
        $collected = $collectedAssignmentIds->count();
        $resolved = $items->every(fn ($item): bool => in_array($item->riderAssignment?->status, ['COMPLETED', 'REJECTED', 'CANCELLED'], true));
        $pickupFinished = $pickup->status === RiderLocalTrip::IN_PROGRESS && $resolved;
        $address = $pickup->seller?->businessAddress;
        $centerAddress = $pickup->logisticsCenter?->address;
        $routeItem = $pickup->status === RiderLocalTrip::EN_ROUTE_TO_HUB
            ? $items->first(fn ($item): bool => $item->riderAssignment?->status === 'COMPLETED')
            : $items->first();
        $routeShipment = $routeItem?->riderAssignment?->shipment;
        $mapMarkers = $routeShipment
            ? app(MapDataService::class)->forShipment($routeShipment, false, true, $request->user())
            : collect([
                app(MapDataService::class)->addressMarker($address, 'pickup-seller-'.$pickup->id, 'Seller pickup address'),
                app(MapDataService::class)->centerMarker($pickup->logisticsCenter),
            ])->filter()->map(fn (array $marker): array => array_merge($marker, [
                'target_kind' => $marker['kind'] === 'center' ? 'logistics' : 'seller',
            ]))->values()->all();

        return view('rider.pickups.show', [
            'pickup' => $pickup,
            'rider' => $pickup->rider,
            'items' => $items,
            'total' => $total,
            'collected' => $collected,
            'collectedAssignmentIds' => $collectedAssignmentIds,
            'resolved' => $resolved,
            'pickupFinished' => $pickupFinished,
            'navigationTargetKind' => $pickup->status === RiderLocalTrip::EN_ROUTE_TO_HUB ? 'logistics' : 'seller',
            'sellerAddress' => $address?->formatted() ?? 'Registered seller pickup address unavailable',
            'hubAddress' => $centerAddress?->formatted() ?? $pickup->logisticsCenter?->business_name ?? 'Logistics center unavailable',
            'mapMarkers' => $mapMarkers,
            'notCollectedReasons' => [
                'Seller could not produce parcel',
                'Waybill damaged or unreadable',
                'Parcel not ready',
                'Parcel mismatch',
                'Seller cancelled handoff',
                'Other',
            ],
        ]);
    }

    public function accept(Request $request, int $trip, SellerPickupBatchService $batches): RedirectResponse
    {
        return $this->run($request, $trip, fn (RiderLocalTrip $batch) => $batches->accept($request->user(), $batch), 'Seller pickup accepted. Go to the seller pickup address.');
    }

    public function start(Request $request, int $trip, SellerPickupBatchService $batches): RedirectResponse
    {
        return $this->run($request, $trip, fn (RiderLocalTrip $batch) => $batches->start($request->user(), $batch), 'Pickup started. Scan each seller waybill separately.');
    }

    public function scan(Request $request, int $trip, SellerPickupBatchService $batches): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        $batch = $this->ownedBatch($request, $trip);
        try {
            $result = $batches->scan($request->user(), $batch, $data['scanned_code'], $data['scan_method'] ?? 'MANUAL');
        } catch (RuntimeException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->withErrors(['scanned_code' => $exception->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            $batch = $batch->fresh()->load('items.riderAssignment.shipment');
            $collectedCount = $batches->collectedAssignmentIds($batch)->count();

            return response()->json([
                'success' => true,
                'message' => $result['already_verified'] ? 'Parcel already picked up.' : 'Parcel verified and collected.',
                'tracking' => $result['assignment']->shipment?->tracking_number,
                'assignment_id' => $result['assignment']->id,
                'already_verified' => $result['already_verified'],
                'collected_count' => $collectedCount,
                'total_count' => $batch->items->count(),
                'clear_input' => true,
            ]);
        }

        return back()->with('status', $result['already_verified'] ? 'Parcel already picked up.' : 'Parcel verified and collected.');
    }

    public function notCollected(Request $request, int $trip, SellerPickupBatchService $batches): RedirectResponse
    {
        $data = $request->validate([
            'rider_assignment_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'in:Seller could not produce parcel,Waybill damaged or unreadable,Parcel not ready,Parcel mismatch,Seller cancelled handoff,Other'],
            'other_reason' => ['required_if:reason,Other', 'nullable', 'string', 'max:1000'],
        ]);
        $reason = $data['reason'] === 'Other' ? 'Other: '.trim((string) $data['other_reason']) : $data['reason'];
        $batch = $this->ownedBatch($request, $trip);

        try {
            $batches->markNotCollected($request->user(), $batch, (int) $data['rider_assignment_id'], $reason);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['rider_assignment_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Parcel marked not collected. It remains in the seller pickup queue.');
    }

    public function finish(Request $request, int $trip, SellerPickupBatchService $batches): RedirectResponse
    {
        $batch = $this->ownedBatch($request, $trip);
        try {
            $finished = $batches->finishSellerPickup($request->user(), $batch);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['pickup' => $exception->getMessage()]);
        }

        $message = $finished->status === RiderLocalTrip::EN_ROUTE_TO_HUB
            ? 'Seller pickup complete. Continue to the assigned logistics center.'
            : 'Seller pickup closed. No parcels were collected.';

        return back()->with('status', $message);
    }

    private function run(Request $request, int $trip, callable $action, string $message): RedirectResponse
    {
        $batch = $this->ownedBatch($request, $trip);
        try {
            $action($batch);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['pickup' => $exception->getMessage()]);
        }

        return back()->with('status', $message);
    }

    private function ownedBatch(Request $request, int $id): RiderLocalTrip
    {
        abort_unless(app(LogisticsSchemaReadiness::class)->sellerPickupBatchesReady(), 503, 'Seller pickup batch workflow is unavailable until its additive schema migration is installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        return RiderLocalTrip::query()
            ->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->findOrFail($id);
    }
}
