<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\User;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\TransferManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

class LogisticsApiController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $center = $this->center($request);
        $centerId = (int) $center->id;

        $centerColumns = array_values(array_filter(
            ['logistics_center_id', 'origin_logistics_center_id', 'destination_logistics_center_id'],
            fn (string $column): bool => Schema::hasColumn('shipments', $column),
        ));
        abort_unless($centerColumns !== [], 503, 'Shipment center assignment is not configured.');

        $shipments = Shipment::query()->where(function ($query) use ($centerColumns, $centerId): void {
            foreach ($centerColumns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}($column, $centerId);
            }
        });

        $statusCounts = (clone $shipments)
            ->selectRaw('UPPER(current_status) as status, COUNT(*) as total')
            ->groupBy('current_status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total);

        $events = DB::table('shipment_events as events')
            ->join('shipments', 'shipments.id', '=', 'events.shipment_id')
            ->where('events.logistics_center_id', $centerId);

        $scansToday = (clone $events)->whereDate('events.occurred_at', now()->toDateString())->count();
        $recentScans = (clone $events)
            ->orderByDesc('events.occurred_at')
            ->limit(30)
            ->get([
                'events.id',
                'events.status',
                'events.occurred_at',
                'shipments.id as shipment_id',
                'shipments.tracking_number',
                'shipments.current_status',
            ])
            ->map(fn ($event): array => [
                'id' => $event->id,
                'shipment_id' => $event->shipment_id,
                'tracking_number' => $event->tracking_number,
                'status' => $event->status ?: $event->current_status,
                'scanned_at' => $event->occurred_at,
            ])
            ->values();
        $recentShipments = (clone $shipments)
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get(['id', 'tracking_number', 'current_status', 'updated_at'])
            ->map(fn ($shipment): array => [
                'shipment_id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->current_status,
                'scanned_at' => $shipment->updated_at,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'center' => [
                    'id' => $center->id,
                    'name' => $center->business_name,
                ],
                'summary' => [
                    'shipments_in_center' => (clone $shipments)->count(),
                    'scans_today' => $scansToday,
                ],
                'status_counts' => $statusCounts,
                'recent_scans' => $recentScans,
                'recent_shipments' => $recentShipments,
            ],
        ]);
    }

    public function receiveScan(
        Request $request,
        ShipmentWorkflowService $workflow,
        LocalRiderTripService $localTrips,
    ): JsonResponse
    {
        $center = $this->center($request);
        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['required', Rule::in(['QR', 'BARCODE', 'MANUAL'])],
        ]);

        $shipment = Shipment::query()
            ->where('tracking_number', trim($data['scanned_code']))
            ->firstOrFail();
        $this->assertCenterShipment($shipment, $center, $localTrips);

        try {
            $received = $workflow->receiveAtCenter(
                $shipment,
                $center,
                $request->user(),
                $data['scanned_code'],
                $data['scan_method'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        // Reconcile the seller pickup batch after center intake. This also
        // repairs batches left en route by earlier API versions on retry scans.
        $localTrips->syncPickupIntake($received);

        return response()->json([
            'success' => true,
            'tracking_number' => $received->tracking_number,
            'current_status' => $received->current_status,
        ]);
    }

    public function outgoingTransfers(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        abort_unless($schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $center = $this->center($request);
        $manifests = TransferManifest::query()
            ->where('origin_logistics_center_id', $center->id)
            ->where('status', TransferManifest::SEALED)
            ->whereNotNull('driver_accepted_at')
            ->with(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment'])
            ->latest()
            ->get()
            ->map(fn (TransferManifest $manifest): array => $this->outgoingTransferPayload($manifest))
            ->values();

        return response()->json(['success' => true, 'data' => $manifests]);
    }

    public function outgoingTransfer(Request $request, int $manifest, LogisticsSchemaReadiness $schema): JsonResponse
    {
        abort_unless($schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $center = $this->center($request);
        $transfer = TransferManifest::query()
            ->where('origin_logistics_center_id', $center->id)
            ->whereKey($manifest)
            ->whereIn('status', [TransferManifest::SEALED, TransferManifest::RELEASED])
            ->whereNotNull('driver_accepted_at')
            ->with(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->outgoingTransferPayload($transfer),
        ]);
    }

    public function releaseTransferItem(
        Request $request,
        int $manifest,
        int $item,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $manifests,
    ): JsonResponse {
        abort_unless($schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $center = $this->center($request);
        $transfer = TransferManifest::query()
            ->where('origin_logistics_center_id', $center->id)
            ->whereKey($manifest)
            ->where('status', TransferManifest::SEALED)
            ->whereNotNull('driver_accepted_at')
            ->with(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment'])
            ->firstOrFail();
        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:120'],
            'scan_method' => ['required', Rule::in(['QR', 'BARCODE', 'MANUAL'])],
        ]);
        $manifestItem = TransferManifestItem::query()
            ->where('manifest_id', $transfer->id)
            ->findOrFail($item);

        try {
            $updated = $manifests->releaseItem(
                $request->user(),
                $transfer,
                $manifestItem,
                $data['scanned_code'],
                $data['scan_method'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $updated->load(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment']);

        return response()->json([
            'success' => true,
            'message' => 'Parcel released to the accepted transfer driver.',
            'data' => $this->outgoingTransferPayload($updated),
        ]);
    }

    private function outgoingTransferPayload(TransferManifest $manifest): array
    {
        $items = $manifest->items->map(function (TransferManifestItem $item): array {
            $leg = $item->transferLeg;
            $shipment = $leg?->shipment;

            return [
                'id' => $item->id,
                'status' => $item->status,
                'tracking_number' => $shipment?->tracking_number,
                'transfer_leg_status' => $leg?->status,
                'parcel_status' => $shipment?->current_status,
            ];
        })->values();

        return [
            'id' => $manifest->id,
            'manifest_number' => $manifest->manifest_number,
            'status' => $manifest->status,
            'origin_center_name' => $manifest->originLogisticsCenter?->business_name ?? '',
            'destination_center_name' => $manifest->destinationLogisticsCenter?->business_name ?? 'Destination hub',
            'total_count' => $items->count(),
            'released_count' => $items->where('transfer_leg_status', ShipmentTransferLeg::RELEASED)->count(),
            'pending_count' => $items->where('transfer_leg_status', ShipmentTransferLeg::READY)->count(),
            'items' => $items,
        ];
    }

    public function incomingTransfers(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        abort_unless($schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $center = $this->center($request);
        $manifests = TransferManifest::query()
            ->where('destination_logistics_center_id', $center->id)
            ->whereIn('status', [
                TransferManifest::IN_TRANSIT,
                TransferManifest::ARRIVED,
                TransferManifest::PARTIAL,
                TransferManifest::EXCEPTION,
            ])
            ->with(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment'])
            ->latest()
            ->get()
            ->map(fn (TransferManifest $manifest): array => $this->incomingTransferPayload($manifest))
            ->values();

        return response()->json(['success' => true, 'data' => $manifests]);
    }

    public function incomingTransfer(Request $request, int $manifest, LogisticsSchemaReadiness $schema): JsonResponse
    {
        abort_unless($schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $center = $this->center($request);
        $transfer = TransferManifest::query()
            ->where('destination_logistics_center_id', $center->id)
            ->whereKey($manifest)
            ->whereIn('status', [
                TransferManifest::IN_TRANSIT,
                TransferManifest::ARRIVED,
                TransferManifest::PARTIAL,
                TransferManifest::EXCEPTION,
                TransferManifest::RECEIVED,
            ])
            ->with(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->incomingTransferPayload($transfer),
        ]);
    }

    public function receiveTransferItem(
        Request $request,
        int $manifest,
        int $item,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $manifests,
    ): JsonResponse {
        abort_unless($schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $this->center($request);
        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:120'],
            'scan_method' => ['required', Rule::in(['QR', 'BARCODE', 'MANUAL'])],
        ]);
        $manifestItem = TransferManifestItem::query()
            ->where('manifest_id', $manifest)
            ->findOrFail($item);

        try {
            $manifests->receiveItem(
                $request->user(),
                $manifestItem,
                TransferManifestItem::RECEIVED,
                $data['scanned_code'],
                null,
                $data['scan_method'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $updatedManifest = TransferManifest::query()
            ->whereKey($manifest)
            ->with(['originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Parcel received at the destination hub and moved to sorting.',
            'data' => $this->incomingTransferPayload($updatedManifest),
        ]);
    }

    private function incomingTransferPayload(TransferManifest $manifest): array
    {
        $items = $manifest->items->map(function (TransferManifestItem $item): array {
            $leg = $item->transferLeg;
            $shipment = $leg?->shipment;

            return [
                'id' => $item->id,
                'status' => $item->status,
                'tracking_number' => $shipment?->tracking_number,
                'transfer_leg_status' => $leg?->status,
                'parcel_status' => $shipment?->current_status,
            ];
        })->values();

        return [
            'id' => $manifest->id,
            'manifest_number' => $manifest->manifest_number,
            'status' => $manifest->status,
            'origin_center_name' => $manifest->originLogisticsCenter?->business_name ?? 'Origin hub',
            'destination_center_name' => $manifest->destinationLogisticsCenter?->business_name ?? '',
            'total_count' => $items->count(),
            'received_count' => $items->where('status', TransferManifestItem::RECEIVED)->count(),
            'pending_count' => $items->where('status', TransferManifestItem::EXPECTED)->count(),
            'items' => $items,
        ];
    }

    private function center(Request $request): LogisticsCenter
    {
        $user = $request->user();
        abort_unless($user, 401, 'Authentication is required.');
        abort_unless($user->isAccountType(User::TYPE_LOGISTICS), 403, 'Logistics center access is required.');

        $center = $user->logisticsCenter()->approvedActive()->first();
        abort_unless($center, 403, 'An approved active logistics center is required.');

        return $center;
    }

    private function assertCenterShipment(Shipment $shipment, LogisticsCenter $center, LocalRiderTripService $localTrips): void
    {
        if ($localTrips->isIncomingSellerPickupForCenter($shipment, $center)) {
            return;
        }

        $shipment->loadMissing('serviceArea');
        $isAssigned = (int) $shipment->logistics_center_id === (int) $center->id;
        $areaAssigned = ! $shipment->logistics_center_id
            && (int) $shipment->serviceArea?->logistics_center_id === (int) $center->id;

        abort_unless($isAssigned || $areaAssigned, 403, 'This parcel is not assigned to your logistics center.');
    }
}
