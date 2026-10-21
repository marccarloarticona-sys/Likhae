<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Finance\SellerSettlement;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class SellerFinanceController extends Controller
{
    public function index(Request $request, FinanceSchemaReadiness $schema, PayoutDestinationReadiness $destinations): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        $ready = $schema->parcelSettlementReady();
        $settlements = $ready
            ? SellerSettlement::query()
                ->where('seller_profile_id', $seller->id)
                ->with(['allocation.shipment', 'sellerOrder.order'])
                ->latest()->paginate(25)
            : null;

        $payoutBalances = [
            'available' => 0.0,
            'requested' => 0.0,
            'awaiting_ack' => 0.0,
            'paid' => 0.0,
        ];
        if ($ready) {
            $eligibleSettlements = SellerSettlement::query()
                ->where('seller_profile_id', $seller->id)
                ->whereIn('payout_status', ['PENDING', 'FAILED'])
                ->whereNotNull('seller_payable')
                ->where('seller_payable', '>', 0);
            $payoutBalances['available'] = (float) (clone $eligibleSettlements)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('financial_ledger_entries as proceeds')
                        ->whereColumn('proceeds.shipment_financial_allocation_id', 'seller_settlements.shipment_financial_allocation_id')
                        ->where('proceeds.entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
                        ->where(fn ($status) => $status->where('proceeds.status', '<>', 'RECEIVED')->orWhereNull('proceeds.status'));
                })
                ->sum('seller_payable');
            $payoutBalances['requested'] = (float) SellerSettlement::query()
                ->where('seller_profile_id', $seller->id)->where('payout_status', 'PAYOUT_REQUESTED')->sum('seller_payable');
            $payoutBalances['awaiting_ack'] = (float) SellerSettlement::query()
                ->where('seller_profile_id', $seller->id)->where('payout_status', 'PAYOUT_PENDING_ACK')->sum('payout_amount');
            $payoutBalances['paid'] = (float) SellerSettlement::query()
                ->where('seller_profile_id', $seller->id)->where('payout_status', 'PAID')->sum('payout_amount');
        }

        $payoutDestinationReady = $destinations->readyFor('seller_profiles');
        $payoutDestinationSaved = $payoutDestinationReady && $destinations->hasDestination($seller);

        return view('seller.settlements.index', compact('seller', 'ready', 'settlements', 'payoutBalances', 'payoutDestinationReady', 'payoutDestinationSaved'));
    }

    public function updatePayoutDestination(Request $request, PayoutDestinationReadiness $destinations): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        abort_unless($destinations->readyFor('seller_profiles'), 503, 'Payout details are unavailable until the finance migration is installed.');
        $data = $request->validate([
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Other'],
            'payout_bank_name' => ['nullable', 'string', 'max:120', 'required_if:payout_method,Bank Transfer'],
            'payout_account_name' => ['required', 'string', 'max:150'],
            'payout_account_number' => ['required', 'string', 'max:150'],
        ]);
        $seller->forceFill($data)->save();

        return back()->with('status', 'Online payout details saved securely.');
    }

    public function acknowledge(Request $request, SellerSettlement $settlement, SellerSettlementService $service): RedirectResponse
    {
        try {
            $service->acknowledgeBySeller($request->user(), $settlement);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['settlement' => $exception->getMessage()]);
        }

        return back()->with('status', 'Payout acknowledgment recorded for this parcel.');
    }

    public function requestPayout(Request $request, SellerSettlement $settlement, SellerSettlementService $service): RedirectResponse
    {
        try {
            $service->requestBySeller($request->user(), $settlement);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['settlement' => $exception->getMessage()]);
        }

        return back()->with('status', 'Payout request submitted to Admin for review.');
    }
}
