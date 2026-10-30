<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logistics\TransferManifest;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\TransferManifestService;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RiderTransferApiController extends Controller
{
    private const RELATIONS = [
        'originLogisticsCenter',
        'destinationLogisticsCenter',
        'items.transferLeg.shipment',
    ];

    public function index(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        $rider = $this->interLogisticsRider($request);
        if (! $schema->transferReady()) {
            return $this->schemaUnavailable();
        }

        $rows = TransferManifest::query()
            ->where('assigned_driver_rider_profile_id', $rider->id)
            ->with(self::RELATIONS)
            ->latest()
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()
                ->map(fn (TransferManifest $manifest): array => $this->manifestPayload($manifest))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, int $manifest, LogisticsSchemaReadiness $schema): JsonResponse
    {
        $rider = $this->interLogisticsRider($request);
        if (! $schema->transferReady()) {
            return $this->schemaUnavailable();
        }

        $model = $this->assignedManifest($rider, $manifest)->load(self::RELATIONS);

        return response()->json([
            'success' => true,
            'data' => $this->manifestPayload($model),
        ]);
    }

    public function accept(
        Request $request,
        int $manifest,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $service,
    ): JsonResponse {
        $rider = $this->interLogisticsRider($request);
        if (! $schema->transferReady()) {
            return $this->schemaUnavailable();
        }

        try {
            $result = $service->driverAccept(
                $request->user(),
                $this->assignedManifest($rider, $manifest),
            );
        } catch (RuntimeException $exception) {
            return $this->workflowError($exception);
        }

        return $this->workflowSuccess($result);
    }

    public function decline(
        Request $request,
        int $manifest,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $service,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $rider = $this->interLogisticsRider($request);
        if (! $schema->transferReady()) {
            return $this->schemaUnavailable();
        }

        try {
            $result = $service->driverDecline(
                $request->user(),
                $this->assignedManifest($rider, $manifest),
                $data['reason'],
            );
        } catch (RuntimeException $exception) {
            return $this->workflowError($exception);
        }

        return $this->workflowSuccess($result);
    }

    public function start(
        Request $request,
        int $manifest,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $service,
    ): JsonResponse {
        $data = $request->validate([
            'scanned_codes' => ['required', 'array', 'min:1', 'max:100'],
            'scanned_codes.*' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        $rider = $this->interLogisticsRider($request);
        if (! $schema->transferReady()) {
            return $this->schemaUnavailable();
        }

        try {
            $result = $service->driverStart(
                $request->user(),
                $this->assignedManifest($rider, $manifest),
                $data['scanned_codes'],
                strtoupper((string) ($data['scan_method'] ?? 'MANUAL')),
            );
        } catch (RuntimeException $exception) {
            return $this->workflowError($exception);
        }

        return $this->workflowSuccess($result);
    }

    private function interLogisticsRider(Request $request): RiderProfile
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && $user->isAccountType(User::TYPE_RIDER)
                && $user->status === User::STATUS_ACTIVE,
            403,
            'Active rider account access is required.',
        );

        $rider = $user->riderProfile;
        abort_unless(
            $rider instanceof RiderProfile
                && $rider->status === 'ACTIVE'
                && RiderVehicleType::isInterLogistics($rider->vehicle_type),
            403,
            'An active inter-logistics rider vehicle is required.',
        );

        return $rider;
    }

    private function assignedManifest(RiderProfile $rider, int $manifestId): TransferManifest
    {
        return TransferManifest::query()
            ->where('assigned_driver_rider_profile_id', $rider->id)
            ->findOrFail($manifestId);
    }

    private function manifestPayload(TransferManifest $manifest): array
    {
        $manifest->loadMissing(self::RELATIONS);
        $status = strtoupper((string) $manifest->status);
        $driverAccepted = $manifest->driver_accepted_at !== null;

        return [
            'id' => (int) $manifest->id,
            'manifest_number' => (string) $manifest->manifest_number,
            'origin_hub' => [
                'id' => $manifest->originLogisticsCenter?->id,
                'name' => $manifest->originLogisticsCenter?->business_name ?? 'Origin hub',
            ],
            'destination_hub' => [
                'id' => $manifest->destinationLogisticsCenter?->id,
                'name' => $manifest->destinationLogisticsCenter?->business_name ?? 'Destination hub',
            ],
            'status' => $status,
            'driver_accepted_at' => $manifest->driver_accepted_at?->toIso8601String(),
            'item_count' => $manifest->items->count(),
            'items' => $manifest->items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'tracking_code' => (string) ($item->transferLeg?->shipment?->tracking_number ?? ''),
                'status' => (string) $item->status,
            ])->values(),
            'can_accept' => $status === TransferManifest::SEALED && ! $driverAccepted,
            'can_decline' => in_array($status, [TransferManifest::DRAFT, TransferManifest::SEALED], true) && ! $driverAccepted,
            'can_start' => $status === TransferManifest::RELEASED && $driverAccepted,
        ];
    }

    private function workflowSuccess(TransferManifest $manifest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->manifestPayload($manifest->load(self::RELATIONS)),
        ]);
    }

    private function workflowError(RuntimeException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 422);
    }

    private function schemaUnavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Transfer manifest schema or approved vehicle capacities are not installed.',
        ], 503);
    }
}