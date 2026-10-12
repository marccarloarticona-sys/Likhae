<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\Notification;
use App\Models\Admin\AuditLog;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Models\Seller\RefundPayout;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Services\RefundProofStorageService;
use App\Services\ReturnRefundWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SellerReturnRefundController extends Controller
{
    public function show(Request $request, SellerReturnRefundRequest $sellerRequest): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);

        $sellerRequest->load([
            'request.order.buyer',
            'request.evidence',
            'request.events.actor',
            'sellerOrder',
            'sellerProfile',
            'items.orderItem',
            'payouts.submittedBy',
            'dispute',
        ]);

        return view('Seller.return-refund-show', compact('sellerRequest'));
    }

    public function approve(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $data = $request->validate(['confirm_approval' => ['required', 'accepted']]);

        DB::transaction(function () use ($request, $sellerRequest, $workflow): void {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with('request')->firstOrFail();
            abort_unless($locked->status === SellerReturnRefundRequest::STATUS_SELLER_REVIEW, 409, 'This request is no longer awaiting Seller review.');
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
        });

        return back()->with('status', 'Refund request approved. Record the manual GCash transfer after sending it.');
    }

    public function reject(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:5', 'max:2000']]);

        DB::transaction(function () use ($request, $sellerRequest, $data, $workflow): void {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with('request')->firstOrFail();
            abort_unless($locked->status === SellerReturnRefundRequest::STATUS_SELLER_REVIEW, 409, 'This request is no longer awaiting Seller review.');
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

    public function payout(Request $request, SellerReturnRefundRequest $sellerRequest, RefundProofStorageService $proofs, ReturnRefundWorkflowService $workflow): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller && (int) $sellerRequest->seller_profile_id === (int) $seller->id, 403);
        $data = $request->validate([
            'amount_sent' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'transaction_reference' => ['required', 'string', 'min:3', 'max:150'],
            'sent_at' => ['required', 'date', 'before_or_equal:now'],
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'seller_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $stored = $proofs->storeImage($request->file('proof'), 'refund-proof/'.$sellerRequest->id);
        try {
            DB::transaction(function () use ($request, $sellerRequest, $data, $stored, $workflow): void {
                $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with(['request', 'payouts'])->firstOrFail();
            abort_unless(in_array($locked->status, [SellerReturnRefundRequest::STATUS_APPROVED, SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED], true), 409, 'Only an approved refund can receive payment proof.');
                abort_if($locked->payouts->isNotEmpty(), 409, 'A payout report has already been recorded for this refund.');
                abort_unless(abs((float) $data['amount_sent'] - (float) $locked->approved_amount) < 0.005, 422, 'Amount sent must match the approved refund amount.');

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
                $from = $locked->status;
                $locked->update(['status' => SellerReturnRefundRequest::STATUS_REFUND_SENT]);
                $workflow->recordEvent($locked->request, 'PAYMENT_PROOF_SUBMITTED', $request->user(), $locked, $from, $locked->status, 'Seller submitted a manual GCash payment report.', ['payout_id' => $payout->id, 'amount_sent' => (float) $payout->amount_sent]);
                $workflow->syncParentStatus($locked->request);
                $this->notifyBuyer($locked, 'Refund payment reported', 'Your Seller has submitted GCash refund payment details. Please check your GCash account.');
            });
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Storage::disk($stored['disk'])->delete($stored['path']);
            throw $exception;
        }

        return back()->with('status', 'Payment proof saved. The refund is awaiting Buyer confirmation.');
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
