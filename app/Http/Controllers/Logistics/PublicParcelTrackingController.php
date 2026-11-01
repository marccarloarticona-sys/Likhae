<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicParcelTrackingController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:100'],
        ]);
        $trackingCode = Str::upper(trim($data['tracking_code']));

        $shipment = Shipment::query()
            ->where('tracking_number', $trackingCode)
            ->with('latestEvent')
            ->first();

        if (! $shipment) {
            return response()->json([
                'success' => false,
                'message' => 'We could not find a parcel with that tracking code. Check the code and try again.',
            ], 404);
        }

        $status = strtoupper((string) $shipment->current_status);
        $statusLabel = in_array($status, Shipment::STATUSES, true)
            ? Str::headline(strtolower($status))
            : 'Processing';
        $statusEvent = $shipment->latestEvent;
        if ($statusEvent?->status !== $status) {
            $statusEvent = $shipment->events()
                ->where('status', $status)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first(['occurred_at']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'tracking_number' => $shipment->tracking_number,
                'status_label' => $statusLabel,
                'updated_at' => $statusEvent?->occurred_at?->toIso8601String(),
            ],
        ]);
    }
}
