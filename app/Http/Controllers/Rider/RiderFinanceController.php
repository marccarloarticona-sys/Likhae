<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinancialLedgerEntry;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;


class RiderFinanceController extends Controller
{
    public function updatePayoutDestination(Request $request, PayoutDestinationReadiness $destinations): RedirectResponse|JsonResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless(! RiderVehicleType::isInterLogistics($rider->vehicle_type), 403, 'Interhub drivers are paid through Logistics payroll, not parcel payouts.');
        abort_unless($destinations->readyFor('rider_profiles'), 503, 'Payout details are unavailable until the finance migration is installed.');
        $data = $request->validate([
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Other'],
            'payout_bank_name' => ['nullable', 'string', 'max:120', 'required_if:payout_method,Bank Transfer'],
            'payout_account_name' => ['required', 'string', 'max:150'],
            'payout_account_number' => ['required', 'string', 'max:150'],
            'return_to' => ['nullable', 'string', 'in:wallet,profile'],
        ]);
        $returnTo = $data['return_to'] ?? 'wallet';
        unset($data['return_to']);
        $rider->forceFill($data)->save();

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Online payout details saved securely.']);
        }

        return $returnTo === 'profile'
            ? redirect()->to(route('rider.profile').'#payment')->with('status', 'Online payout details saved securely.')
            : back()->with('status', 'Online payout details saved securely.');
    }

    public function requestPayout(Request $request, EarningPayoutService $payouts): RedirectResponse|JsonResponse
    {
        abort_unless(! RiderVehicleType::isInterLogistics($request->user()->riderProfile?->vehicle_type), 403, 'Interhub drivers are paid through Logistics payroll, not parcel payouts.');
        $data = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        try {
            $payouts->requestRiderPayout($request->user(), $data['participant_ids']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['participant_ids' => $exception->getMessage()]);
        }

        $message = 'Payout request submitted to your Logistics center.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message], 201)
            : back()->with('status', $message);
    }

    public function acknowledgePayout(Request $request, FinancialLedgerEntry $entry, EarningPayoutService $payouts): RedirectResponse|JsonResponse
    {
        abort_unless(! RiderVehicleType::isInterLogistics($request->user()->riderProfile?->vehicle_type), 403, 'Interhub drivers are paid through Logistics payroll, not parcel payouts.');
        $data = $request->validate([
            'amount_received' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $payouts->acknowledgeRiderPayout($request->user(), $entry, $data['amount_received'], $data['notes'] ?? null);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['amount_received' => $exception->getMessage()]);
        }

        $message = 'Rider payout receipt acknowledged.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : back()->with('status', $message);
    }
}
