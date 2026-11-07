<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\PayoutDestinationReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderPayoutsApiController extends Controller
{
    public function index(
        Request $request,
        EarningPayoutService $payouts,
        PayoutDestinationReadiness $destinations,
    ): JsonResponse {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        return response()->json([
            'success' => true,
            'data' => [
                'destination_ready' => $destinations->readyFor('rider_profiles'),
                'destination' => $rider->only([
                    'payout_method',
                    'payout_bank_name',
                    'payout_account_name',
                    'payout_account_number',
                ]),
                'eligible' => $payouts->eligibleRiderParticipants($rider)
                    ->map(fn ($row): array => [
                        'id' => $row->id,
                        'amount' => $row->share_amount,
                        'tracking_number' => $row->pool?->shipment?->tracking_number,
                    ])->values(),
                'receipts' => $payouts->riderPayoutReceipts($rider)
                    ->map(fn ($row): array => [
                        'id' => $row->id,
                        'amount' => $row->amount,
                        'status' => $row->status,
                        'paid_at' => $row->paid_at,
                        'proof_available' => filled($row->metadata['payout_proof_path'] ?? null),
                        'reference' => $row->metadata['payout_external_reference'] ?? null,
                    ])->values(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
