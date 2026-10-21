<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinancialLedgerEntry;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\PayoutDestinationReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;


class RiderFinanceController extends Controller
{
    public function updatePayoutDestination(Request $request, PayoutDestinationReadiness $destinations): RedirectResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless($destinations->readyFor('rider_profiles'), 503, 'Payout details are unavailable until the finance migration is installed.');
        $data = $request->validate([
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Other'],
            'payout_bank_name' => ['nullable', 'string', 'max:120', 'required_if:payout_method,Bank Transfer'],
            'payout_account_name' => ['required', 'string', 'max:150'],
            'payout_account_number' => ['required', 'string', 'max:150'],
        ]);
        $rider->forceFill($data)->save();

        return back()->with('status', 'Online payout details saved securely.');
    }

    public function requestPayout(Request $request, EarningPayoutService $payouts): RedirectResponse
    {
        $data = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        try {
            $payouts->requestRiderPayout($request->user(), $data['participant_ids']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['participant_ids' => $exception->getMessage()]);
        }

        return back()->with('status', 'Payout request submitted to your Logistics center.');
    }

    public function acknowledgePayout(Request $request, FinancialLedgerEntry $entry, EarningPayoutService $payouts): RedirectResponse
    {
        $data = $request->validate([
            'amount_received' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $payouts->acknowledgeRiderPayout($request->user(), $entry, $data['amount_received'], $data['notes'] ?? null);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['amount_received' => $exception->getMessage()]);
        }

        return back()->with('status', 'Rider payout receipt acknowledged.');
    }
}
