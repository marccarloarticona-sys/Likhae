<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Finance\SellerSettlement;
use App\Services\Finance\PayoutSupportService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Finance\PayoutDestinationManager;
use App\Services\Finance\SellerSettlementService;
use App\Support\PayoutDestinationInput;
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

    public function updatePayoutDestination(Request $request, PayoutDestinationReadiness $destinations, PayoutDestinationManager $manager, PayoutDestinationInput $input): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);
        abort_unless($destinations->readyFor('seller_profiles'), 503, 'Payout details are unavailable until the finance migration is installed.');

        if ($destinations->supportsMultipleFor('seller_profiles')) {
            $action = (string) $request->input('destination_action', 'add');
            if (in_array($action, ['set_default', 'remove', 'update'], true)) {
                $data = $request->validate(['destination_id' => ['required', 'string', 'max:80']]);
                if ($action === 'set_default') {
                    $manager->setDefault($seller, $data['destination_id']);

                    return back()->with('status', 'Default Seller payout destination updated.');
                }

                if ($action === 'remove') {
                    $manager->remove($seller, $data['destination_id']);

                    return back()->with('status', 'Payout destination removed.');
                }

                $destination = $input->validate($request, true);
                $manager->update($seller, $data['destination_id'], $destination, (bool) ($destination['make_default'] ?? false));

                return back()->with('status', 'Payout destination updated.');
            }
            abort_unless($action === 'add', 422, 'Unsupported payout destination action.');

            $data = $input->validate($request, true);
            $manager->add($seller, $data, (bool) ($data['make_default'] ?? false));

            return back()->with('status', 'Payout destination saved securely. The selected default will be used for future Seller payouts.');
        }

        abort_unless($request->input('destination_action', 'add') === 'add', 503, 'Multiple payout destinations are unavailable until the migration is installed.');

        $data = $input->validate($request);
        $seller->forceFill($data)->save();

        return back()->with('status', 'Payout destination saved securely.');
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

    public function reportPayoutIssue(Request $request, SellerSettlement $settlement, PayoutSupportService $support): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $settlement->seller_profile_id === (int) $seller->id, 403);
        abort_unless(filled($settlement->payout_external_reference), 404);

        $message = implode("\n", [
            'PAYOUT SUPPORT — seller reports this transfer has not arrived.',
            'Store: '.$seller->business_name,
            'Payout reference: '.$settlement->settlement_reference,
            'Transfer reference: '.$settlement->payout_external_reference,
            'Amount: PHP '.number_format((float) $settlement->payout_amount, 2),
            'Payout date: '.($settlement->paid_at?->toDateTimeString() ?? 'Not recorded'),
            'Please review the transfer and contact me about the payout status.',
        ]);
        $conversation = $support->contactAdmin($request->user(), $message);

        return redirect()->route('seller.messages', ['conversation' => $conversation->id])
            ->with('status', 'Payout support request sent to Admin.');
    }
}
