<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use App\Services\Fulfillment\FinalMileDispatchService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Media\ImageOptimizationService;
use App\Services\Marketplace\BuyerOrderActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class WorkflowApiController extends Controller
{
    public function sellerOrder(Request $request, SellerOrder $sellerOrder, ShipmentWorkflowService $workflow): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAccountType(User::TYPE_SELLER), 403);
        abort_unless(
            $user->sellerProfile
                && $user->sellerProfile->status === 'ACTIVE'
                && (int) $sellerOrder->seller_profile_id === (int) $user->sellerProfile->id,
            403,
            'An active seller profile is required.',
        );

        $data = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'prepare', 'ready', 'handover_confirm', 'cancel'])],
            'shipment_ids' => ['sometimes', 'array', 'min:1', 'max:'.\App\Support\LocalTripCapacityRules::MAX_PARCELS_PER_TRIP],
            'shipment_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);
        $result = $workflow->sellerTransition($sellerOrder, $data['action'], $user, null, $data['shipment_ids'] ?? null);

        return response()->json(['success' => true, 'seller_order' => $result->only(['id', 'seller_order_number', 'status'])]);
    }

    public function riderAssignment(Request $request, RiderAssignment $assignment, ShipmentWorkflowService $workflow, ImageOptimizationService $images, LocalRiderTripService $localTrips): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAccountType(User::TYPE_RIDER), 403);
        abort_unless($user->riderProfile && (int) $assignment->rider_profile_id === (int) $user->riderProfile->id, 403);
        if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP) {
            return response()->json([
                'success' => false,
                'message' => 'Seller pickup actions must be performed through the seller-specific pickup batch workflow.',
            ], 422);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'start', 'pickup_complete', 'delivery_success', 'delivery_failed', 'reject'])],
            'scan_method' => ['nullable', Rule::in(['QR', 'BARCODE', 'MANUAL'])],
            'scanned_code' => ['required_if:action,pickup_complete,delivery_success', 'nullable', 'string', 'max:150'],
            'receiver_name' => ['required_if:action,delivery_success', 'nullable', 'string', 'max:200'],
            'failure_reason' => ['required_if:action,delivery_failed', 'nullable', 'string', 'max:1000'],
            'attempt_status' => ['nullable', Rule::in(['FAILED', 'RESCHEDULED'])],
            'next_attempt_at' => ['required_if:attempt_status,RESCHEDULED', 'nullable', 'date', 'after:now'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:1000'],
            'collected_amount' => ['required_if:action,delivery_success', 'nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'collection_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof_path' => ['nullable', 'string', 'max:500'],
            'proof_file' => ['required_if:action,delivery_success', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $proofPath = null;
        if ($request->hasFile('proof_file')) {
            $proofPath = $images->store($request->file('proof_file'), 'delivery-proofs');
            $data['proof_path'] = $proofPath;
        }

        try {
            $result = $workflow->riderTransition($assignment, $data['action'], $user, $data);
            $localTrips->syncAssignment($result);
        } catch (Throwable $exception) {
            if ($proofPath) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'assignment' => $result->only(['id', 'assignment_type', 'status']),
            'shipment_status' => $result->shipment->current_status,
            'receiver_name' => $data['receiver_name'] ?? null,
        ]);
    }

    public function receiveParcel(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): JsonResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->assertCenterShipment($shipment, $center);

        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['required', Rule::in(['QR', 'BARCODE', 'MANUAL'])],
        ]);
        $result = $workflow->receiveAtCenter($shipment, $center, $request->user(), $data['scanned_code'], $data['scan_method']);

        return response()->json(['success' => true, 'tracking_number' => $result->tracking_number, 'current_status' => $result->current_status]);
    }

    public function sortParcel(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): JsonResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->assertCenterShipment($shipment, $center);

        $data = $request->validate(['service_area_id' => ['nullable', 'integer']]);
        $area = isset($data['service_area_id'])
            ? ServiceArea::query()->where('logistics_center_id', $center->id)->where('is_active', true)->findOrFail($data['service_area_id'])
            : null;
        try {
            $result = $workflow->sortShipment($shipment, $center, $request->user(), $area);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['success' => true, 'tracking_number' => $result->tracking_number, 'current_status' => $result->current_status, 'service_area_id' => $result->service_area_id]);
    }

    public function assignRider(Request $request, Shipment $shipment, FinalMileDispatchService $dispatch): JsonResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->assertCenterShipment($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', Rule::exists('rider_profiles', 'id')->where('logistics_center_id', $center->id)],
            'assignment_type' => ['required', Rule::in(['DELIVERY'])],
            'local_trip_id' => ['required', 'integer', 'min:0'],
        ]);
        $rider = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->where('status', 'ACTIVE')
            ->whereHas('user', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->findOrFail($data['rider_profile_id']);
        try {
            $trip = $dispatch->dispatch(
                $request->user(),
                $center,
                $shipment,
                $rider,
                (int) $data['local_trip_id'] === 0 ? null : (int) $data['local_trip_id'],
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $assignment = RiderAssignment::query()
            ->where('shipment_id', $shipment->id)
            ->where('rider_profile_id', $rider->id)
            ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
            ->latest('id')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'assignment' => $assignment->only(['id', 'assignment_type', 'status']),
            'local_trip_id' => $trip->id,
        ]);
    }

    public function confirmReceipt(Request $request, Order $order, BuyerOrderActionService $actions): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAccountType(User::TYPE_BUYER) && (int) $order->buyer_user_id === (int) $user->id, 403);
        $request->validate([
            'confirm_receipt' => ['required', 'accepted'],
        ]);

        abort_unless(
            $actions->confirmReceipt($order, $user, $actions->returnRefundStorageReady()),
            409,
            'Receipt can only be confirmed after every parcel has been delivered and any active return/refund request is resolved.',
        );

        return response()->json(['success' => true, 'order_number' => $order->order_number, 'status' => 'COMPLETED']);
    }

    private function center(Request $request): ?LogisticsCenter
    {
        $user = $request->user();
        abort_unless($user->isAccountType(User::TYPE_LOGISTICS), 403);

        return $user->logisticsCenter()->approvedActive()->first();
    }

    private function assertCenterShipment(Shipment $shipment, LogisticsCenter $center): void
    {
        $shipment->loadMissing('serviceArea');
        $isAssigned = (int) $shipment->logistics_center_id === (int) $center->id;
        $areaAssigned = ! $shipment->logistics_center_id && (int) $shipment->serviceArea?->logistics_center_id === (int) $center->id;
        abort_unless($isAssigned || $areaAssigned, 403);
    }
}
