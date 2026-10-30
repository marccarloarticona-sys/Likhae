<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Services\Fulfillment\ShipmentWorkflowService;
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

    public function receiveScan(Request $request, ShipmentWorkflowService $workflow): JsonResponse
    {
        $center = $this->center($request);
        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['required', Rule::in(['QR', 'BARCODE', 'MANUAL'])],
        ]);

        $shipment = Shipment::query()
            ->where('tracking_number', trim($data['scanned_code']))
            ->firstOrFail();
        $this->assertCenterShipment($shipment, $center);

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

        return response()->json([
            'success' => true,
            'tracking_number' => $received->tracking_number,
            'current_status' => $received->current_status,
        ]);
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

    private function assertCenterShipment(Shipment $shipment, LogisticsCenter $center): void
    {
        $shipment->loadMissing('serviceArea');
        $isAssigned = (int) $shipment->logistics_center_id === (int) $center->id;
        $areaAssigned = ! $shipment->logistics_center_id
            && (int) $shipment->serviceArea?->logistics_center_id === (int) $center->id;

        abort_unless($isAssigned || $areaAssigned, 403, 'This parcel is not assigned to your logistics center.');
    }
}
