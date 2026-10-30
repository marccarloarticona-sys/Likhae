<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Maps\MapDataService;
use App\Services\Marketplace\BuyerOrderActionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrackingController extends Controller
{
    public function show(Request $request, string $tracking): View
    {
        $shipmentQuery = Shipment::query()
            ->where('tracking_number', $tracking)
            ->with([
                'sellerOrder.order.buyer',
                'sellerOrder.order.address',
                'orderItem',
                'events.actor',
                'scans',
                'deliveryAttempts',
                'riderAssignments.liveLocation',
                'logisticsCenter.address',
            ]);
        if (app(LogisticsSchemaReadiness::class)->shipmentReturnsReady()) {
            $shipmentQuery->with('returnWorkflow');
        }
        $shipment = $shipmentQuery->firstOrFail();

        $user = $request->user();
        if ($user !== null) {
            abort_unless($this->canView($shipment, $user), 403);
        }

        $order = $shipment->sellerOrder?->order;
        if ($order && app(BuyerOrderActionService::class)->returnRefundStorageReady()) {
            $order->loadMissing('returnRefundRequests');
        }
        $hasReturnRefundRequest = $order?->relationLoaded('returnRefundRequests')
            && $order->returnRefundRequests->isNotEmpty();
        $hasCancellationEvent = $shipment->events->contains(fn ($event): bool =>
            str_starts_with((string) $event->notes, 'BUYER_ORDER_CANCELLED:')
            || str_starts_with((string) $event->notes, 'SELLER_ORDER_CANCELLED:')
        );
        $returnWorkflow = $shipment->relationLoaded('returnWorkflow') ? $shipment->getRelation('returnWorkflow') : null;
        $hasSellerReturnInProgress = $returnWorkflow
            && $returnWorkflow->return_disposition === ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN
            && (in_array($returnWorkflow->status, ShipmentReturnWorkflow::OPEN_STATUSES, true)
                || $returnWorkflow->status === ShipmentReturnWorkflow::SELLER_RECEIVED);
        $hideMap = in_array($order?->status, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)
            || $shipment->sellerOrder?->status === 'CANCELLED'
            || in_array($shipment->current_status, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)
            || $hasReturnRefundRequest
            || $hasSellerReturnInProgress
            || $hasCancellationEvent;

        $mapMarkers = app(MapDataService::class)->forShipment($shipment);

        return view('buyer.tracking.show', compact('shipment', 'mapMarkers', 'hideMap'));
    }

    public function live(Request $request, string $trackingCode): JsonResponse
    {
        $shipment = Shipment::query()
            ->where('tracking_number', $trackingCode)
            ->with([
                'sellerOrder.order.buyer',
                'sellerOrder.order.address',
                'sellerOrder.sellerProfile',
                'sellerOrder.sellerProfile.businessAddress',
                'orderItem',
                'riderAssignments.riderProfile',
                'riderAssignments.liveLocation',
                'logisticsCenter.address',
                'serviceArea.logisticsCenter.address',
            ])
            ->firstOrFail();

        $user = $request->user();
        if ($user && ! $this->canView($shipment, $user)) {
            abort(403);
        }

        return response()->json([
            'success' => true,
            ...app(MapDataService::class)->trackingPayload($shipment, $user),
        ]);
    }

    private function canView(Shipment $shipment, \App\Models\User $user): bool
    {
        if ($user->isAccountType('ADMIN')) {
            return true;
        }

        if ($user->isAccountType('BUYER')) {
            return (int) $shipment->sellerOrder?->order?->buyer_user_id === (int) $user->id;
        }

        if ($user->isAccountType('SELLER')) {
            return (int) $shipment->sellerOrder?->sellerProfile?->user_id === (int) $user->id;
        }

        if ($user->isAccountType('RIDER')) {
            return $shipment->riderAssignments
                ->contains(fn ($assignment) => (int) $assignment->riderProfile?->user_id === (int) $user->id);
        }

        if ($user->isAccountType('LOGISTICS')) {
            $center = $shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter;
            return (int) $center?->owner_user_id === (int) $user->id;
        }

        return false;
    }

    public function proof(Request $request, int|string $event): BinaryFileResponse
    {
        $attempt = DeliveryAttempt::query()
            ->with(['shipment.sellerOrder.order', 'riderAssignment.riderProfile.user'])
            ->findOrFail($event);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $isBuyer = $user->account_type === 'BUYER'
            && (int) $attempt->shipment->sellerOrder->order->buyer_user_id === (int) $user->id;
        $isAssignedRider = $user->account_type === 'RIDER'
            && (int) optional(optional($attempt->riderAssignment)->riderProfile)->user_id === (int) $user->id;
        $isStaff = in_array($user->account_type, ['ADMIN', 'LOGISTICS'], true);

        abort_unless($isBuyer || $isAssignedRider || $isStaff, 403);
        abort_unless(filled($attempt->proof_path), 404, 'No proof file is attached to this delivery attempt.');

        $path = (string) $attempt->proof_path;
        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404, 'Proof file was not found.');

        return response()->file($disk->path($path));
    }
}
