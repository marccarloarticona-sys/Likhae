<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\Notification;
use App\Models\Admin\AuditLog;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Models\Seller\RefundPayout;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Services\Fulfillment\ShipmentReturnWorkflowService;
use App\Services\RefundProofStorageService;
use App\Services\ReturnRefundWorkflowService;
use App\Services\Returns\CashRefundWorkflowService;
use App\Services\Returns\ReturnCustodyService;
use App\Services\Returns\PhysicalReturnCompletionService;
use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Returns\ReturnInspection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SellerReturnRefundController extends Controller
{
    public function show(Request $request, SellerReturnRefundRequest $sellerRequest): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);

        $relations = [
            'request.order.buyer',
            'request.evidence',
            'request.events.actor',
            'sellerOrder',
            'sellerProfile',
            'items.orderItem',
            'payouts.submittedBy',
            'dispute',
        ];
        $sellerRequest->load($relations);
        if (Schema::hasTable('cash_refund_obligations')) {
            $sellerRequest->load('cashRefundObligation');
        } else {
            $sellerRequest->setRelation('cashRefundObligation', null);
        }

        $physicalReturnRequired = trim((string) $sellerRequest->request?->solution) === 'Return & Refund';
        $physicalReturnReady = app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->buyerReturnWaybillsReady()
            && app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->shipmentReturnEnhancementsReady()
            && Schema::hasColumn('seller_return_refund_requests', 'shipment_id')
            && Schema::hasColumn('return_refund_request_items', 'shipment_id');
        $inspectionsByItem = collect();
        if ($physicalReturnRequired && $physicalReturnReady) {
            $sellerRequest->load('shipment.orderItem', 'buyerReturnWorkflow.shipment', 'buyerReturnWorkflow.logisticsCenter.address');
            $inspectionsByItem = ReturnInspection::query()
                ->where('seller_return_refund_request_id', $sellerRequest->id)
                ->latest('id')
                ->get()
                ->keyBy('return_refund_request_item_id');
        } else {
            $sellerRequest->setRelation('buyerReturnWorkflow', null);
            $sellerRequest->setRelation('shipment', null);
        }

        $responseDeadline = $sellerRequest->status === SellerReturnRefundRequest::STATUS_SELLER_REVIEW
            ? $sellerRequest->created_at?->copy()->addDays(ReturnRefundWorkflowService::SELLER_RESPONSE_DAYS)->timezone(config('app.timezone'))
            : null;

        return view('Seller.return-refund-show', compact('sellerRequest', 'responseDeadline', 'physicalReturnRequired', 'physicalReturnReady', 'inspectionsByItem'));
    }

    public function approve(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $data = $request->validate(['confirm_approval' => ['required', 'accepted']]);

        $approvalResult = DB::transaction(function () use ($request, $sellerRequest, $workflow): string {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with('request')->firstOrFail();

            if ($locked->status === SellerReturnRefundRequest::STATUS_APPROVED) {
                return 'already_approved';
            }

            abort_unless($locked->status === SellerReturnRefundRequest::STATUS_SELLER_REVIEW, 409, 'This request is no longer awaiting Seller review.');
            $responseDeadline = $locked->created_at?->copy()->addDays(ReturnRefundWorkflowService::SELLER_RESPONSE_DAYS);
            abort_unless($responseDeadline, 409, 'The Seller response deadline could not be determined. Refresh the request and try again.');

            if (now()->greaterThanOrEqualTo($responseDeadline)) {
                return 'deadline_passed';
            }

            $from = $locked->status;
            $locked->update([
                'status' => SellerReturnRefundRequest::STATUS_APPROVED,
                'approved_amount' => $locked->requested_amount,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_reason' => 'Seller approved the requested eligible amount.',
            ]);
            $workflow->recordEvent($locked->request, 'SELLER_APPROVED', $request->user(), $locked, $from, $locked->status, 'Seller approved the requested refund amount.');
            $workflow->syncParentStatus($locked->request);
            $this->notifyBuyer($locked, 'Refund request approved', 'The Seller approved your request '.$locked->request->request_number.'.');

            return 'approved';
        });

        if ($approvalResult === 'deadline_passed') {
            $autoApproved = $workflow->autoApproveExpiredSellerResponse((int) $sellerRequest->id);
            if ($autoApproved) {
                return back()->with('status', 'The Seller response deadline passed, so the system automatically approved this request.');
            }

            if ($sellerRequest->fresh()?->status === SellerReturnRefundRequest::STATUS_APPROVED) {
                return back()->with('status', 'This request has already been approved. No duplicate approval was recorded.');
            }

            abort(409, 'The Seller response deadline has passed and this request is not eligible for automatic approval. Refresh to review its current status.');
        }

        if ($approvalResult === 'already_approved') {
            return back()->with('status', 'This request has already been approved. No duplicate approval was recorded.');
        }

        return back()->with('status', 'Refund request approved. After the return is received and inspected, you will pay the approved COD cash refund to the Buyer.');
    }

    public function reject(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $reason = $request->input('rejection_reason');
        if (is_string($reason)) {
            $request->merge(['rejection_reason' => trim($reason)]);
        }
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:10', 'max:300']]);

        DB::transaction(function () use ($request, $sellerRequest, $data, $workflow): void {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with('request')->firstOrFail();
            abort_unless($locked->status === SellerReturnRefundRequest::STATUS_SELLER_REVIEW, 409, 'This request is no longer awaiting Seller review.');
            $responseDeadline = $locked->created_at?->copy()->addDays(ReturnRefundWorkflowService::SELLER_RESPONSE_DAYS);
            abort_unless($responseDeadline && now()->lt($responseDeadline), 409, 'The Seller response deadline has passed. The request is awaiting automatic handling.');
            $from = $locked->status;
            $locked->update([
                'status' => SellerReturnRefundRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_reason' => trim($data['rejection_reason']),
            ]);
            $workflow->recordEvent($locked->request, 'SELLER_REJECTED', $request->user(), $locked, $from, $locked->status, trim($data['rejection_reason']));
            $workflow->syncParentStatus($locked->request);
            $this->notifyBuyer($locked, 'Refund request declined', 'The Seller declined your request '.$locked->request->request_number.'. You can review the reason and file an Admin complaint.');
        });

        return back()->with('status', 'Refund request rejected with a recorded reason.');
    }

    public function payout(Request $request, SellerReturnRefundRequest $sellerRequest, RefundProofStorageService $proofs, ReturnRefundWorkflowService $workflow, CashRefundWorkflowService $cashRefunds): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $sellerRequest->loadMissing('request');
        $refundMethod = strtoupper((string) $sellerRequest->request?->refund_method);
        abort_unless($refundMethod !== 'CASH', 409, 'New COD cash refunds must use the cash refund workflow.');
        if ($refundMethod === 'SAVED_DESTINATION') {
            abort_unless(is_array($sellerRequest->request?->refund_destination_snapshot), 409, 'The saved refund destination snapshot is unavailable. Contact Admin before sending payment.');
            $request->validate(['confirm_direct_transfer' => ['required', 'accepted']]);
        }
        $data = $request->validate([
            'amount_sent' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'transaction_reference' => ['required', 'string', 'min:3', 'max:150'],
            'sent_at' => ['required', 'date', 'before_or_equal:now'],
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'seller_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(PhysicalReturnCompletionService::class)->assertComplete($sellerRequest);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        $stored = $proofs->storeImage($request->file('proof'), 'refund-proof/'.$sellerRequest->id);
        try {
            DB::transaction(function () use ($request, $sellerRequest, $data, $stored, $workflow, $cashRefunds): void {
                $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with(['request', 'payouts'])->firstOrFail();
            abort_unless(in_array($locked->status, [SellerReturnRefundRequest::STATUS_APPROVED, SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED], true), 409, 'Only an approved refund can receive payment proof.');
                abort_if($locked->payouts->isNotEmpty(), 409, 'A payout report has already been recorded for this refund.');
                abort_unless(abs((float) $data['amount_sent'] - (float) $locked->approved_amount) < 0.005, 422, 'Amount sent must match the approved refund amount.');
                app(PhysicalReturnCompletionService::class)->assertComplete($locked);

                $reference = trim($data['transaction_reference']);
                $payout = RefundPayout::create([
                    'seller_return_refund_request_id' => $locked->id,
                    'submitted_by_user_id' => $request->user()->id,
                    'amount_sent' => round((float) $data['amount_sent'], 2),
                    'transaction_reference' => $reference,
                    'transaction_reference_hash' => hash('sha256', strtolower($reference)),
                    'sent_at' => $data['sent_at'],
                    'proof_disk' => $stored['disk'],
                    'proof_path' => $stored['path'],
                    'proof_original_name' => $stored['original_name'],
                    'proof_mime' => $stored['mime'],
                    'seller_note' => $data['seller_note'] ?? null,
                    'payment_report_state' => 'REPORTED_SENT',
                ]);
                if (strtoupper((string) $locked->request?->refund_method) === 'SAVED_DESTINATION') {
                    $cashRefunds->recordDirectRefundReversals($locked, $request->user());
                }
                $from = $locked->status;
                $locked->update(['status' => SellerReturnRefundRequest::STATUS_REFUND_SENT]);
                $isSavedDestination = strtoupper((string) $locked->request?->refund_method) === 'SAVED_DESTINATION';
                $workflow->recordEvent($locked->request, 'PAYMENT_PROOF_SUBMITTED', $request->user(), $locked, $from, $locked->status, $isSavedDestination ? 'Seller submitted proof of manual refund transfer to the Buyer’s saved destination.' : 'Seller submitted a manual GCash payment report.', ['payout_id' => $payout->id, 'amount_sent' => (float) $payout->amount_sent]);
                $workflow->syncParentStatus($locked->request);
                $this->notifyBuyer(
                    $locked,
                    $isSavedDestination ? 'Refund sent to your saved destination' : 'Refund payment reported',
                    $isSavedDestination ? 'Your Seller reports sending the approved refund to your saved e-wallet or bank account. Check the account and confirm receipt after the funds arrive.' : 'Your Seller has submitted GCash refund payment details. Please check your GCash account.',
                );
            });
        } catch (\RuntimeException $exception) {
            \Illuminate\Support\Facades\Storage::disk($stored['disk'])->delete($stored['path']);
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Storage::disk($stored['disk'])->delete($stored['path']);
            throw $exception;
        }

        return back()->with('status', 'Payment proof saved. The refund is awaiting Buyer confirmation.');
    }

    public function cashReady(Request $request, SellerReturnRefundRequest $sellerRequest, CashRefundWorkflowService $cashRefunds): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $request->validate(['confirm_cash_ready' => ['required', 'accepted']]);
        try {
            $cashRefunds->markCashReady($sellerRequest, $request->user());
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Cash refund is ready. Pay the approved amount to the Buyer, then record the cash handoff here.');
    }

    public function cashPaid(Request $request, SellerReturnRefundRequest $sellerRequest, CashRefundWorkflowService $cashRefunds): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        abort_unless(Schema::hasTable('cash_refund_obligations'), 409, 'The COD cash-refund workflow is not available yet.');

        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'min:3', 'max:150'],
            'confirm_cash_paid' => ['required', 'accepted'],
        ]);

        $obligation = $sellerRequest->cashRefundObligation()->firstOrFail();
        $alreadyRecordedAsPaid = in_array($obligation->state, [\App\Models\Returns\CashRefundObligation::CASH_PAID, \App\Models\Returns\CashRefundObligation::COMPLETED], true);
        try {
            $cashRefunds->markCashPaid($obligation, $request->user(), $data['payment_reference']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['cash_refund' => $exception->getMessage()])->withInput();
        }

        $sellerRequest->loadMissing('request');
        if (! $alreadyRecordedAsPaid) {
            $this->notifyBuyer($sellerRequest, 'COD cash refund paid', 'Your Seller recorded the approved cash refund as paid. Confirm receipt only after you receive the cash.');
        }

        return back()->with('status', 'Cash refund recorded as paid. The Buyer must confirm receipt.');
    }

    public function receiveReturn(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnCustodyService $custody, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $request->validate(['confirm_received' => ['required', 'accepted'], 'notes' => ['nullable', 'string', 'max:2000']]);
        try {
            if (trim((string) $sellerRequest->request?->solution) === 'Return & Refund') {
                if (! app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->buyerReturnWaybillsReady()) {
                    throw new \RuntimeException('The Buyer return waybill route is not installed yet.');
                }
                $returnWorkflow = \App\Models\Logistics\ShipmentReturnWorkflow::query()
                    ->where('seller_return_refund_request_id', $sellerRequest->id)
                    ->first();
                if (! $returnWorkflow) {
                    throw new \RuntimeException('The Buyer must create a return waybill and hand the parcel to Logistics before Seller receipt can be recorded.');
                }
                $returns->confirmSellerReceipt($returnWorkflow, $request->user(), $request->input('notes'));
                return back()->with('status', 'Seller receipt confirmed through the return-to-seller route. Complete inspection before the refund can be paid.');
            }
            $custody->recordCustodyEvent($sellerRequest, $request->user(), 'SELLER_RECEIVED', ['from_party' => 'LOGISTICS_OR_RIDER', 'to_party' => 'SELLER_OR_FACILITY', 'notes' => $request->input('notes')]);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Seller/facility receipt recorded. Complete inspection before cash can be made ready.');
    }

    public function inspectReturn(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundRequestItem $item, ReturnCustodyService $custody): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $data = $request->validate([
            'inventory_disposition' => ['required', 'in:RESTOCKABLE,DAMAGED,QUARANTINED,NON_RESELLABLE'],
            'received_condition' => ['nullable', 'string', 'max:80'],
            'completeness_status' => ['nullable', 'string', 'max:80'],
            'damage_notes' => ['nullable', 'string', 'max:2000'],
            'missing_accessories' => ['nullable', 'string', 'max:2000'],
            'identifier_result' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $custody->recordInspection($sellerRequest, $item, $request->user(), $data);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Return inspection saved. Restockable items were returned to inventory; other dispositions remain out of saleable stock.');
    }

    public function payoutProof(Request $request, RefundPayout $payout, RefundProofStorageService $proofs)
    {
        $seller = $request->user()->sellerProfile;
        $payout->load('sellerRequest');
        abort_unless($seller && (int) $payout->sellerRequest?->seller_profile_id === (int) $seller->id, 403);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'event' => 'REFUND_PROOF_VIEWED', 'auditable_type' => RefundPayout::class, 'auditable_id' => $payout->id, 'new_values' => ['scope' => 'seller'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($proofs->read($payout->proof_disk, $payout->proof_path), 200, [
            'Content-Type' => $payout->proof_mime ?: 'image/webp',
            'Content-Disposition' => 'inline; filename="refund-proof.webp"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function evidence(Request $request, ReturnRefundEvidence $evidence, RefundProofStorageService $proofs)
    {
        $seller = $request->user()->sellerProfile;
        $evidence->load(['sellerRequest', 'request.sellerRequests']);
        $belongsToSellerRequest = $evidence->sellerRequest
            && (int) $evidence->sellerRequest->seller_profile_id === (int) $seller?->id;
        $belongsToParentRequest = $evidence->request?->sellerRequests
            ->contains(fn (SellerReturnRefundRequest $sellerRequest): bool => (int) $sellerRequest->seller_profile_id === (int) $seller?->id);
        abort_unless($seller && ($belongsToSellerRequest || $belongsToParentRequest), 403);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'event' => 'REFUND_EVIDENCE_VIEWED', 'auditable_type' => ReturnRefundEvidence::class, 'auditable_id' => $evidence->id, 'new_values' => ['scope' => 'seller'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($proofs->read($evidence->disk, $evidence->file_path), 200, [
            'Content-Type' => $evidence->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="refund-evidence"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function notifyBuyer(SellerReturnRefundRequest $sellerRequest, string $title, string $message): void
    {
        $buyerId = $sellerRequest->request?->buyer_user_id;
        if (! $buyerId) {
            return;
        }
        Notification::create([
            'user_id' => $buyerId,
            'type' => 'RETURN_REFUND',
            'title' => $title,
            'message' => $message,
            'reference_type' => SellerReturnRefundRequest::class,
            'reference_id' => $sellerRequest->id,
            'action_url' => route('buyer.returns.show', $sellerRequest),
        ]);
    }
}
