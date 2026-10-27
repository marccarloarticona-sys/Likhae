<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Finance\RiderRemittance;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Finance\PayoutDestinationManager;
use App\Services\Finance\PayoutSupportService;
use App\Services\Finance\ProductProceedsService;
use App\Services\Finance\RemittanceService;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\SimulatedPayoutGateway;
use App\Support\PayoutDestinationInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;

class LogisticsRemittanceController extends Controller
{
    public function wallet(Request $request, FinanceSchemaReadiness $schema, PayoutDestinationReadiness $destinations): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $ready = $schema->parcelSettlementReady();
        $empty = fn (string $page): LengthAwarePaginator => new LengthAwarePaginator([], 0, 15, max(1, $request->integer($page, 1)), [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
        $receivedAllocationIds = FinancialLedgerEntry::query()
            ->select('shipment_financial_allocation_id')
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
            ->where('status', 'RECEIVED');
        $pools = $ready
            ? LogisticsEarningPool::query()
                ->where('logistics_center_id', $center->id)
                ->whereIn('shipment_financial_allocation_id', $receivedAllocationIds)
                ->where('total_amount', '>', 0)
                ->whereIn('status', ['PAYOUT_REQUESTED', 'PENDING', 'APPROVED'])
                ->with(['shipment.sellerOrder.order', 'allocation'])
                ->orderByRaw("CASE WHEN status = 'PAYOUT_REQUESTED' THEN 0 ELSE 1 END")
                ->latest()->paginate(15, ['*'], 'payout_pool_page')
            : $empty('payout_pool_page');
        $receipts = $ready
            ? FinancialLedgerEntry::query()
                ->where('entry_type', 'LOGISTICS_PAYOUT')
                ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
                ->where('metadata->logistics_center_id', $center->id)
                ->with(['shipment.sellerOrder.order'])
                ->latest('paid_at')->paginate(15, ['*'], 'payout_receipt_page')
            : $empty('payout_receipt_page');
        $eligiblePools = LogisticsEarningPool::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('shipment_financial_allocation_id', $receivedAllocationIds);
        $realPayouts = fn () => FinancialLedgerEntry::query()
            ->where('entry_type', 'LOGISTICS_PAYOUT')
            ->where('metadata->logistics_center_id', $center->id)
            ->whereNotNull('paid_at')
            ->where(fn ($query) => $query->whereNull('metadata->is_simulated')->orWhere('metadata->is_simulated', false))
            ->where(fn ($query) => $query->whereNull('metadata->payout_external_reference')->orWhere('metadata->payout_external_reference', 'not like', 'SIM-%'));
        $payoutSummary = [
            'available' => $ready ? (float) (clone $eligiblePools)->whereIn('status', ['PENDING', 'APPROVED'])->sum('total_amount') : 0.0,
            'requested' => $ready ? (float) (clone $eligiblePools)->where('status', 'PAYOUT_REQUESTED')->sum('total_amount') : 0.0,
            'awaiting_ack' => $ready ? (float) $realPayouts()->where('status', 'PAYOUT_PENDING_ACK')->sum('amount') : 0.0,
            'paid' => $ready ? (float) $realPayouts()->where('status', 'PAID')->sum('amount') : 0.0,
            'paid_out' => $ready ? (float) $realPayouts()->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])->sum('amount') : 0.0,
        ];
        $payoutDestinationReady = $destinations->readyFor('logistics_centers');
        $payoutDestinationSaved = $payoutDestinationReady && $destinations->hasDestination($center);

        return view('logistics.wallet.index', compact('center', 'ready', 'pools', 'receipts', 'payoutSummary', 'payoutDestinationReady', 'payoutDestinationSaved'));
    }

    public function updatePayoutDestination(Request $request, PayoutDestinationReadiness $destinations, PayoutDestinationManager $manager, PayoutDestinationInput $input): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        abort_unless($destinations->readyFor('logistics_centers'), 503, 'Payout details are unavailable until the finance migration is installed.');

        if ($destinations->supportsMultipleFor('logistics_centers')) {
            $action = (string) $request->input('destination_action', 'add');
            if (in_array($action, ['set_default', 'remove', 'update'], true)) {
                $data = $request->validate(['destination_id' => ['required', 'string', 'max:80']]);
                if ($action === 'set_default') {
                    $manager->setDefault($center, $data['destination_id']);

                    return back()->with('status', 'Default Logistics payout destination updated.');
                }

                if ($action === 'remove') {
                    $manager->remove($center, $data['destination_id']);

                    return back()->with('status', 'Payout destination removed.');
                }

                $destination = $input->validate($request, true);
                $manager->update($center, $data['destination_id'], $destination, (bool) ($destination['make_default'] ?? false));

                return back()->with('status', 'Payout destination updated.');
            }
            abort_unless($action === 'add', 422, 'Unsupported payout destination action.');

            $data = $input->validate($request, true);
            $manager->add($center, $data, (bool) ($data['make_default'] ?? false));

            return back()->with('status', 'Payout destination saved securely. The selected default will be used for future Logistics payouts.');
        }

        abort_unless($request->input('destination_action', 'add') === 'add', 503, 'Multiple payout destinations are unavailable until the migration is installed.');

        $data = $input->validate($request);
        $center->forceFill($data)->save();

        return back()->with('status', 'Payout destination saved securely.');
    }

    public function requestPayout(Request $request, LogisticsEarningPool $pool, EarningPayoutService $service): RedirectResponse
    {
        try {
            $service->requestLogisticsPayout($request->user(), $pool);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payout' => $exception->getMessage()]);
        }

        return back()->with('status', 'Logistics payout request sent to Admin.');
    }

    public function reportPayoutIssue(Request $request, FinancialLedgerEntry $entry, PayoutSupportService $support): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center
            && $entry->entry_type === 'LOGISTICS_PAYOUT'
            && (int) data_get($entry->metadata, 'logistics_center_id') === (int) $center->id, 403);
        abort_unless(filled(data_get($entry->metadata, 'payout_external_reference')), 404);

        $metadata = (array) $entry->metadata;
        $message = implode("\n", [
            'PAYOUT SUPPORT — Logistics reports this transfer has not arrived.',
            'Logistics center: '.$center->business_name,
            'Payout reference: '.($metadata['payout_reference'] ?? 'Unavailable'),
            'Transfer reference: '.$metadata['payout_external_reference'],
            'Parcel: '.($entry->shipment?->tracking_number ?? 'Unavailable'),
            'Amount: PHP '.number_format((float) $entry->amount, 2),
            'Payout date: '.($metadata['paid_at'] ?? 'Not recorded'),
            'Please review the transfer and contact us about the payout status.',
        ]);
        $conversation = $support->contactAdmin($request->user(), $message);

        return redirect()->route('logistics.messages', ['conversation' => $conversation->id])
            ->with('status', 'Payout support request sent to Admin.');
    }

    public function index(Request $request, FinanceSchemaReadiness $schema): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $schemaReady = $schema->step6Ready();
        $remittances = $schemaReady
            ? RiderRemittance::query()->where('logistics_center_id', $center->id)->with(['riderProfile.user', 'items.codCollection.shipment', 'items.codCollection.order'])->latest()->paginate(20)
            : new LengthAwarePaginator([], 0, 20, max(1, $request->integer('page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        $productProceeds = $schema->parcelSettlementReady()
            ? FinancialLedgerEntry::query()
                ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
                ->whereHas('shipment', fn ($query) => $query->where(function ($query) use ($center): void {
                    $query->where('destination_logistics_center_id', $center->id)->orWhere('logistics_center_id', $center->id);
                }))
                ->with(['shipment.sellerOrder.order', 'allocation', 'actor'])
                ->latest()->paginate(20, ['*'], 'product_proceeds_page')
            : new LengthAwarePaginator([], 0, 20, max(1, $request->integer('product_proceeds_page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        $riderPayoutRequests = $schema->parcelSettlementReady()
            ? FinancialLedgerEntry::query()
                ->where('entry_type', 'RIDER_PAYOUT')
                ->where('status', 'PAYOUT_REQUESTED')
                ->whereHas('shipment', fn ($query) => $query->where(function ($query) use ($center): void {
                    $query->where('destination_logistics_center_id', $center->id)->orWhere('logistics_center_id', $center->id);
                }))
                ->with(['shipment.sellerOrder.order', 'allocation', 'actor'])
                ->latest()->paginate(20, ['*'], 'rider_payout_page')
            : new LengthAwarePaginator([], 0, 20, max(1, $request->integer('rider_payout_page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        $logisticsPayoutReceipts = $schema->parcelSettlementReady()
            ? FinancialLedgerEntry::query()
                ->where('entry_type', 'LOGISTICS_PAYOUT')
                ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
                ->where('metadata->logistics_center_id', $center->id)
                ->with(['shipment.sellerOrder.order', 'allocation'])
                ->latest()->paginate(20, ['*'], 'logistics_payout_page')
            : new LengthAwarePaginator([], 0, 20, max(1, $request->integer('logistics_payout_page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        $riderPayoutParticipants = collect();
        if ($schema->parcelSettlementReady()) {
            $participantIds = $riderPayoutRequests->getCollection()
                ->map(fn (FinancialLedgerEntry $entry): int => (int) data_get($entry->metadata, 'participant_id'))
                ->filter()
                ->unique()
                ->values();
            $riderPayoutParticipants = RiderEarningPoolParticipant::query()
                ->whereIn('id', $participantIds)
                ->with('riderProfile')
                ->get()
                ->keyBy('id');
        }

        return view('logistics.remittances.index', [
            'center' => $center,
            'remittances' => $remittances,
            'schemaReady' => $schemaReady,
            'parcelSettlementReady' => $schema->parcelSettlementReady(),
            'productProceeds' => $productProceeds,
            'riderPayoutRequests' => $riderPayoutRequests,
            'riderPayoutParticipants' => $riderPayoutParticipants,
            'logisticsPayoutReceipts' => $logisticsPayoutReceipts,
        ]);
    }

    public function verify(Request $request, RiderRemittance $remittance, RemittanceService $service): RedirectResponse
    {
        $data = $request->validate(['verified_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        try {
            $service->verify($request->user(), $remittance, $data['verified_amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['verified_amount' => $exception->getMessage()]);
        }

        return back()->with('status', 'Remittance verified. Reconcile it after comparing expected and verified cash.');
    }

    public function reconcile(Request $request, RiderRemittance $remittance, RemittanceService $service): RedirectResponse
    {
        try {
            $service->reconcile($request->user(), $remittance, $request->input('notes'));
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['remittance' => $exception->getMessage()]);
        }

        return back()->with('status', 'Remittance reconciliation recorded.');
    }

    public function resolveDiscrepancy(Request $request, RiderRemittance $remittance, RemittanceService $service): RedirectResponse
    {
        $data = $request->validate([
            'final_received_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'resolution' => ['required', 'string', 'max:2000'],
        ]);
        try {
            $service->resolveDiscrepancy($request->user(), $remittance, $data['final_received_amount'], $data['resolution']);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['resolution' => $exception->getMessage()]);
        }

        return back()->with('status', 'Rider handoff discrepancy resolution recorded.');
    }

    public function submitProductProceeds(Request $request, FinancialLedgerEntry $entry, ProductProceedsService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'handoff_method' => ['nullable', 'string', 'max:80'],
            'handoff_external_reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $service->submitToAdmin(
                $request->user(), $entry, $data['amount'], $data['notes'] ?? null,
                $data['handoff_method'] ?? null, $data['handoff_external_reference'] ?? null,
            );
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return back()->with('status', 'Parcel product proceeds handoff recorded for Admin acknowledgment.');
    }

    public function recordRiderPayout(Request $request, FinancialLedgerEntry $entry, EarningPayoutService $service, SimulatedPayoutGateway $simulatedPayouts): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'payout_method' => ['required', 'string', 'in:GCash,Maya,Bank Transfer,Manual,Other'],
            'payout_external_reference' => ['required_if:payout_method,Manual', 'nullable', 'string', 'max:150'],
            'payout_proof' => ['required_if:payout_method,Manual', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $proofPath = null;
        $proofName = null;
        if ($data['payout_method'] === 'Manual') {
            $proofPath = $data['payout_proof']->store('payout-proofs', 'local');
            if (! $proofPath) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payout_proof' => 'The payout proof could not be saved. Please retry.']);
            }
            $proofName = $data['payout_proof']->getClientOriginalName();
        }
        try {
            $recorded = $service->recordRiderPayout(
                $request->user(), $entry, $data['amount'], $data['payout_method'],
                $data['payout_external_reference'] ?? null, $data['notes'] ?? null,
                $proofPath, $proofName,
            );
            if ($proofPath && data_get($recorded->metadata, 'payout_proof_path') !== $proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
        } catch (RuntimeException $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
            throw $exception;
        }

        $message = $simulatedPayouts->isOnlineMethod($data['payout_method'])
            ? 'Demo payout simulated. No money was transferred; the Rider can acknowledge the simulated receipt.'
            : 'Rider payout recorded. The Rider must acknowledge the receipt.';

        return back()->with('status', $message);
    }

    public function acknowledgeLogisticsPayout(Request $request, FinancialLedgerEntry $entry, EarningPayoutService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount_received' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $service->acknowledgeLogisticsPayout($request->user(), $entry, $data['amount_received'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount_received' => $exception->getMessage()]);
        }

        return back()->with('status', 'Logistics payout receipt acknowledged.');
    }
}
