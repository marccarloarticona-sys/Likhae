<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Admin\Dispute;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Seller\RefundPayout;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\RefundProofStorageService;
use App\Services\ReturnRefundWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BuyerReturnRefundController extends Controller
{
    public function index(Request $request): View
    {
        $requests = ReturnRefundRequest::query()
            ->where('buyer_user_id', $request->user()->id)
            ->with([
                'order',
                'sellerRequests.sellerProfile',
                'sellerRequests.sellerOrder',
                'sellerRequests.items.orderItem',
                'sellerRequests.payouts',
            ])
            ->latest('submitted_at')
            ->paginate(10)
            ->withQueryString();

        return view('Buyer.returns', compact('requests'));
    }

    public function show(Request $request, SellerReturnRefundRequest $sellerRequest): View
    {
        $sellerRequest->load([
            'request.order',
            'request.evidence',
            'request.events.actor',
            'sellerProfile.user',
            'sellerOrder',
            'items.orderItem',
            'payouts.submittedBy',
            'dispute.evidence',
        ]);
        abort_unless((int) $sellerRequest->request?->buyer_user_id === (int) $request->user()->id, 403);

        return view('Buyer.return-refund-show', compact('sellerRequest'));
    }

    public function parentShow(Request $request, ReturnRefundRequest $returnRequest): View
    {
        abort_unless((int) $returnRequest->buyer_user_id === (int) $request->user()->id, 403);
        $returnRequest->load(['order', 'sellerRequests.sellerProfile', 'sellerRequests.sellerOrder', 'sellerRequests.items.orderItem', 'sellerRequests.payouts']);

        return view('Buyer.returns-parent', compact('returnRequest'));
    }

    public function confirm(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundWorkflowService $workflow): RedirectResponse|JsonResponse
    {
        $sellerRequest->loadMissing('request', 'sellerOrder.sellerProfile.user', 'payouts');
        abort_unless((int) $sellerRequest->request?->buyer_user_id === (int) $request->user()->id, 403);
        $request->validate(['confirm_receipt' => ['required', 'accepted']]);

        DB::transaction(function () use ($request, $sellerRequest, $workflow): void {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with(['request', 'payouts'])->firstOrFail();
            abort_unless($locked->status === SellerReturnRefundRequest::STATUS_REFUND_SENT, 409, 'This refund is not waiting for receipt confirmation.');
            abort_unless($locked->payouts->isNotEmpty(), 409, 'A payment report is required before confirmation.');

            $from = $locked->status;
            $locked->update(['status' => SellerReturnRefundRequest::STATUS_CONFIRMED]);
            $workflow->recordEvent($locked->request, 'BUYER_CONFIRMED_RECEIPT', $request->user(), $locked, $from, $locked->status, 'Buyer confirmed receiving the reported GCash refund.');
            $workflow->syncParentStatus($locked->request);

            $sellerUser = $locked->sellerOrder?->sellerProfile?->user;
            if ($sellerUser) {
                Notification::create([
                    'user_id' => $sellerUser->id,
                    'type' => 'RETURN_REFUND',
                    'title' => 'Buyer confirmed refund receipt',
                    'message' => 'The Buyer confirmed receiving the reported refund for request '.$locked->request->request_number.'.',
                    'reference_type' => SellerReturnRefundRequest::class,
                    'reference_id' => $locked->id,
                    'action_url' => route('seller.orders.refunds.show', $locked),
                ]);
            }
        });

        return $request->expectsJson()
            ? response()->json(['success' => true, 'status' => SellerReturnRefundRequest::STATUS_CONFIRMED])
            : back()->with('status', 'Refund receipt confirmed.');
    }

    public function escalate(Request $request, SellerReturnRefundRequest $sellerRequest, ReturnRefundWorkflowService $workflow, RefundProofStorageService $proofs): RedirectResponse|JsonResponse
    {
        $sellerRequest->loadMissing('request', 'sellerOrder.sellerProfile.user');
        abort_unless((int) $sellerRequest->request?->buyer_user_id === (int) $request->user()->id, 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:150'],
            'explanation' => ['required', 'string', 'min:10', 'max:4000'],
            'confirm_escalation' => ['required', 'accepted'],
            'evidence' => ['nullable', 'array', 'max:6'],
            'evidence.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $dispute = DB::transaction(function () use ($request, $sellerRequest, $data, $workflow, $proofs): Dispute {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with(['request', 'sellerOrder'])->firstOrFail();
            abort_unless(in_array($locked->status, [SellerReturnRefundRequest::STATUS_REJECTED, SellerReturnRefundRequest::STATUS_REFUND_SENT, SellerReturnRefundRequest::STATUS_APPROVED], true), 409, 'This refund cannot be escalated in its current state.');
            abort_if($locked->dispute_id, 409, 'An Admin escalation already exists for this refund.');

            $dispute = Dispute::create([
                'dispute_number' => 'DP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'opened_by_user_id' => $request->user()->id,
                'order_id' => $locked->request->order_id,
                'seller_order_id' => $locked->seller_order_id,
                'type' => 'RETURN_REFUND_ESCALATION',
                'subject' => 'Refund escalation '.$locked->request->request_number,
                'description' => "Reason: {$data['reason']}\n\n".trim($data['explanation']),
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);

            $from = $locked->status;
            $locked->update([
                'status' => SellerReturnRefundRequest::STATUS_ESCALATED,
                'escalation_reason' => $data['reason'],
                'escalation_description' => trim($data['explanation']),
                'escalated_by_user_id' => $request->user()->id,
                'escalated_at' => now(),
                'dispute_id' => $dispute->id,
            ]);

            $workflow->recordEvent($locked->request, 'BUYER_ESCALATED_TO_ADMIN', $request->user(), $locked, $from, $locked->status, 'Buyer filed an Admin escalation.', ['dispute_id' => $dispute->id]);
            $workflow->syncParentStatus($locked->request);

            foreach ((array) $request->file('evidence', []) as $file) {
                $stored = $proofs->storeImage($file, 'return-refund-evidence/'.$locked->request->id.'/escalation');
                ReturnRefundEvidence::create([
                    'return_refund_request_id' => $locked->request->id,
                    'seller_return_refund_request_id' => $locked->id,
                    'uploaded_by_user_id' => $request->user()->id,
                    'disk' => $stored['disk'],
                    'file_path' => $stored['path'],
                    'original_name' => $stored['original_name'],
                    'mime_type' => $stored['mime'],
                    'evidence_type' => 'BUYER_ESCALATION',
                    'encrypted' => true,
                ]);
            }

            User::query()->where('account_type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get()->each(function (User $admin) use ($dispute, $locked): void {
                Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'RETURN_REFUND_ESCALATION',
                    'title' => 'Refund escalation requires review',
                    'message' => 'Buyer escalated request '.$locked->request->request_number.' for Admin review.',
                    'reference_type' => Dispute::class,
                    'reference_id' => $dispute->id,
                    'action_url' => route('admin.complaints'),
                ]);
            });

            return $dispute;
        });

        return $request->expectsJson()
            ? response()->json(['success' => true, 'dispute_number' => $dispute->dispute_number], 201)
            : back()->with('status', 'Your complaint was sent to Admin for review.');
    }

    public function payoutProof(Request $request, RefundPayout $payout, RefundProofStorageService $proofs)
    {
        $payout->load('sellerRequest.request');
        abort_unless((int) $payout->sellerRequest?->request?->buyer_user_id === (int) $request->user()->id, 403);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'event' => 'REFUND_PROOF_VIEWED', 'auditable_type' => RefundPayout::class, 'auditable_id' => $payout->id, 'new_values' => ['scope' => 'buyer'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($proofs->read($payout->proof_disk, $payout->proof_path), 200, [
            'Content-Type' => $payout->proof_mime ?: 'image/webp',
            'Content-Disposition' => 'inline; filename="refund-proof.webp"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function evidence(Request $request, ReturnRefundEvidence $evidence, RefundProofStorageService $proofs)
    {
        $evidence->load('request');
        abort_unless((int) $evidence->request?->buyer_user_id === (int) $request->user()->id, 403);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'event' => 'REFUND_EVIDENCE_VIEWED', 'auditable_type' => ReturnRefundEvidence::class, 'auditable_id' => $evidence->id, 'new_values' => ['scope' => 'buyer'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($proofs->read($evidence->disk, $evidence->file_path), 200, [
            'Content-Type' => $evidence->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="refund-evidence"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
