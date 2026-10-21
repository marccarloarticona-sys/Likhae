<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Finance\SellerSettlement;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\CodCollection;
use App\Models\Finance\LogisticsEarningPool;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\FinancialAllocationService;
use App\Services\Finance\ProductProceedsService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\SimulatedPayoutGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminFinanceController extends Controller
{
    public function payLogisticsShare(Request $request, LogisticsEarningPool $pool, EarningPayoutService $service, SimulatedPayoutGateway $simulatedPayouts): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Manual,Other'],
            'payout_external_reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $service->recordLogisticsPayout(
                $request->user(),
                $pool,
                $data['amount'],
                $data['payout_method'],
                $data['payout_external_reference'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        $message = $simulatedPayouts->isOnlineMethod($data['payout_method'])
            ? 'Demo payout simulated. No money was transferred; the Logistics center can acknowledge the simulated receipt.'
            : 'Logistics shipping share payout recorded. The Logistics center must acknowledge its receipt.';

        return back()->with('status', $message);
    }

    public function authorizeSellerSettlement(Request $request, SellerSettlement $settlement, SellerSettlementService $service): RedirectResponse
    {
        try {
            $service->authorize($request->user(), $settlement, $request->input('notes'));
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['settlement' => $exception->getMessage()]);
        }

        return back()->with('status', 'Seller settlement authorized. Payment remains a separate action.');
    }

    public function paySeller(Request $request, SellerSettlement $settlement, SellerSettlementService $service, SimulatedPayoutGateway $simulatedPayouts): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Manual,Other'],
            'payout_external_reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $service->pay($request->user(), $settlement, $data['amount'], $data['payout_method'] ?? null, $data['payout_external_reference'] ?? null, $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        $message = $simulatedPayouts->isOnlineMethod($data['payout_method'])
            ? 'Demo payout simulated. No money was transferred; the seller can acknowledge the simulated receipt.'
            : 'Seller payout recorded. It remains pending seller acknowledgment.';

        return back()->with('status', $message);
    }

    public function failSellerPayout(Request $request, SellerSettlement $settlement, SellerSettlementService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $service->failPayout($request->user(), $settlement, $data['reason']);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('status', 'Payout failure recorded. The seller payable remains outstanding.');
    }

    public function acknowledgeProductProceeds(Request $request, FinancialLedgerEntry $entry, ProductProceedsService $service): RedirectResponse
    {
        $data = $request->validate(['received_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        try {
            $service->acknowledgeByAdmin($request->user(), $entry, $data['received_amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['received_amount' => $exception->getMessage()]);
        }

        return back()->with('status', 'Product proceeds receipt recorded. Any amount discrepancy remains open.');
    }

    public function resolveProductProceeds(Request $request, FinancialLedgerEntry $entry, ProductProceedsService $service): RedirectResponse
    {
        $data = $request->validate(['final_received_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'resolution' => ['required', 'string', 'max:2000']]);
        try {
            $service->resolveAdminDiscrepancy($request->user(), $entry, $data['final_received_amount'], $data['resolution']);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['resolution' => $exception->getMessage()]);
        }

        return back()->with('status', 'Product proceeds discrepancy resolution recorded.');
    }

    public function resolveBuyerCodDiscrepancy(Request $request, CodCollection $collection, ProductProceedsService $service, FinancialAllocationService $allocations): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:2000']]);
        try {
            $resolved = $service->resolveBuyerCollectionDiscrepancy($request->user(), $collection, $data['resolution']);
            if ($resolved->shipment) {
                $allocations->initializeForShipment($resolved->shipment->refresh());
            }
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['resolution' => $exception->getMessage()]);
        }

        return back()->with('status', 'Buyer COD discrepancy resolution recorded for this parcel.');
    }
}
