<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Rider\RiderReturnWorkflowController;
use App\Http\Controllers\Rider\RiderTripController;
use App\Services\Fulfillment\ShipmentReturnWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class RiderWorkflowsApiController extends Controller
{
    private function page($rows, callable $map): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => collect($rows->items())->map($map)->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    public function returns(Request $request, RiderReturnWorkflowController $controller, ShipmentReturnWorkflowService $service): JsonResponse
    {
        $data = $controller->index($request, $service)->getData();
        $riderId = (int) $data['rider']->id;

        return $this->page($data['workflows'], function ($row) use ($riderId): array {
            $actions = [];
            $original = (int) $row->originalDeliveryAssignment?->rider_profile_id === $riderId;
            $assigned = (int) $row->return_rider_profile_id === $riderId;

            if ($original && $row->status === 'AWAITING_CENTER_HANDOFF') $actions[] = 'center-handoff';
            if ($assigned && $row->status === 'ASSIGNED_TO_RETURN_RIDER') $actions[] = 'accept';
            if ($assigned && $row->status === 'RETURN_RIDER_ACCEPTED') $actions[] = 'pickup';
            if ($assigned && $row->status === 'IN_TRANSIT_TO_SELLER') $actions[] = 'seller-handoff';
            if ($row->status === 'RETURN_RIDER_HANDOFF_PENDING') {
                if ($assigned && ! $row->handoff_from_confirmed_at) $actions[] = 'handoff/from';
                if ((int) $row->pending_return_rider_profile_id === $riderId && $row->handoff_from_confirmed_at && ! $row->handoff_to_confirmed_at) $actions[] = 'handoff/to';
            }

            return [
                'id' => $row->id,
                'tracking_number' => $row->shipment?->tracking_number,
                'status' => $row->status,
                'reason' => $row->reason,
                'seller' => $row->shipment?->sellerOrder?->sellerProfile?->business_name,
                'seller_address' => $row->shipment?->sellerOrder?->sellerProfile?->businessAddress?->formatted(),
                'center' => $row->logisticsCenter?->business_name,
                'actions' => $actions,
            ];
        });
    }

    private function tripPayload($trip): array
    {
        $active = $trip->items->whereIn('status', ['QUEUED', 'LOADED']);
        $actions = [];
        if ($active->isNotEmpty() && in_array($trip->status, ['LOADING', 'READY'], true)) {
            if ($active->every(fn ($item) => in_array($item->riderAssignment?->status, ['ASSIGNED', 'ACCEPTED'], true)) ) {
                $actions = ['accept', 'reject'];
            }
            if ($active->every(fn ($item) => $item->riderAssignment?->status === 'ACCEPTED')) {
                $actions[] = 'load';
                $actions[] = 'start';
            }
        }

        return [
            'id' => $trip->id,
            'status' => $trip->status,
            'capacity' => $trip->effective_capacity,
            'parcel_count' => $trip->items->count(),
            'actions' => $actions,
            'items' => $trip->items->map(fn ($item) => [
                'id' => $item->id,
                'assignment_id' => $item->rider_assignment_id,
                'status' => $item->status,
                'tracking_number' => $item->riderAssignment?->shipment?->tracking_number,
                'recipient' => $item->riderAssignment?->shipment?->sellerOrder?->order?->address?->recipient_name,
                'address' => $item->riderAssignment?->shipment?->sellerOrder?->order?->address?->formatted(),
            ])->values(),
        ];
    }

    public function trips(Request $request, RiderTripController $controller): JsonResponse
    {
        $data = $controller->index($request)->getData();
        abort_unless($data['localTripsReady'], 503, 'Local trip schema is not installed.');

        return $this->page($data['trips'], fn ($trip) => $this->tripPayload($trip));
    }

    public function trip(Request $request, int $trip, RiderTripController $controller): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->tripPayload($controller->show($request, $trip)->getData()['trip']),
        ]);
    }

    public function profileUpdates(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('rider_profile_update_requests'), 503, 'Profile review is not installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        return $this->page($rider->profileUpdateRequests()->latest()->paginate(20), fn ($row) => $row->only([
            'id', 'vehicle_type', 'plate_number', 'drivers_license_number', 'status', 'review_notes', 'submitted_at', 'reviewed_at',
        ]));
    }
}
