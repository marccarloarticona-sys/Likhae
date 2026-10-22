<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Finance\SellerSettlement;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\CodCollection;
use App\Models\Finance\LogisticsEarningPool;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\FinancialAllocationService;
use App\Services\Finance\ProductProceedsService;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminFinanceController extends Controller
{
    public function payLogisticsShare(Request $request, LogisticsEarningPool $pool, EarningPayoutService $service, PayoutDestinationReadiness $destinations): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'payout_external_reference' => ['required', 'string', 'max:150'],
            'payout_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $pool->loadMissing('logisticsCenter');
        $center = $pool->logisticsCenter;
        if (! $center || ! $destinations->readyFor('logistics_centers') || ! $destinations->hasDestination($center)) {
            throw ValidationException::withMessages(['payout' => 'The Logistics center must save complete GCash, Maya, or bank details before Admin can record its payout.']);
        }
        $proofPath = $data['payout_proof']->store('payout-proofs', 'local');
        if (! $proofPath) {
            throw ValidationException::withMessages(['payout_proof' => 'The payout proof could not be saved. Please retry.']);
        }
        try {
            $recorded = $service->recordLogisticsPayout(
                $request->user(),
                $pool,
                $data['amount'],
                $center->payout_method,
                $data['payout_external_reference'],
                $data['notes'] ?? null,
                $proofPath,
                $data['payout_proof']->getClientOriginalName(),
            );
            if (data_get($recorded->metadata, 'payout_proof_path') !== $proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
        } catch (RuntimeException $exception) {
            Storage::disk('local')->delete($proofPath);
            throw ValidationException::withMessages(['payout' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($proofPath);
            throw $exception;
        }

        return back()->with('status', 'Manual Logistics payout recorded with its transfer reference and proof. The Logistics center can confirm receipt.');
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

    public function paySeller(Request $request, SellerSettlement $settlement, SellerSettlementService $service, PayoutDestinationReadiness $destinations, FinanceSchemaReadiness $schema): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'payout_external_reference' => ['required', 'string', 'max:150'],
            'payout_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! $schema->sellerPayoutProofReady()) {
            throw ValidationException::withMessages(['payout_proof' => 'Install the additive payout proof migration before recording a seller transfer.']);
        }
        $settlement->loadMissing('sellerProfile');
        $seller = $settlement->sellerProfile;
        if (! $seller || ! $destinations->readyFor('seller_profiles') || ! $destinations->hasDestination($seller)) {
            throw ValidationException::withMessages(['payout' => 'The seller must save complete GCash, Maya, or bank details before Admin can record this payout.']);
        }
        $proofPath = $data['payout_proof']->store('payout-proofs', 'local');
        if (! $proofPath) {
            throw ValidationException::withMessages(['payout_proof' => 'The payout proof could not be saved. Please retry.']);
        }
        try {
            $recorded = $service->pay(
                $request->user(),
                $settlement,
                $data['amount'],
                $seller->payout_method,
                $data['payout_external_reference'],
                $data['notes'] ?? null,
                $proofPath,
                $data['payout_proof']->getClientOriginalName(),
            );
            if ($recorded->payout_proof_path !== $proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
        } catch (RuntimeException $exception) {
            Storage::disk('local')->delete($proofPath);
            throw ValidationException::withMessages(['payout' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($proofPath);
            throw $exception;
        }

        return back()->with('status', 'Manual seller payout recorded with its transfer reference and proof. The seller can confirm receipt.');
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
