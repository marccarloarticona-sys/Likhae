<?php

namespace App\Services;

use App\Models\Buyer\ReturnRefundEvent;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Admin\Notification;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReturnRefundWorkflowService
{
    public const SELLER_RESPONSE_DAYS = 3;

    public function autoApproveExpiredSellerResponses(int $limit = 250): int
    {
        if (! Schema::hasTable('seller_return_refund_requests') || ! Schema::hasTable('return_refund_events')) {
            return 0;
        }

        $cutoff = now()->subDays(self::SELLER_RESPONSE_DAYS);
        $ids = SellerReturnRefundRequest::query()
            ->where('status', SellerReturnRefundRequest::STATUS_SELLER_REVIEW)
            ->where('created_at', '<=', $cutoff)
            ->whereNull('dispute_id')
            ->whereDoesntHave('payouts')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');

        $approved = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id): bool {
                $sellerRequest = SellerReturnRefundRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $sellerRequest || $sellerRequest->status !== SellerReturnRefundRequest::STATUS_SELLER_REVIEW) {
                    return false;
                }

                $parent = ReturnRefundRequest::query()
                    ->whereKey($sellerRequest->return_refund_request_id)
                    ->lockForUpdate()
                    ->first();
                if (! $parent) {
                    return false;
                }

                $deadline = $sellerRequest->created_at?->copy()->addDays(self::SELLER_RESPONSE_DAYS);
                if (! $deadline || now()->lt($deadline)
                    || $sellerRequest->dispute_id !== null
                    || $parent->dispute_id !== null
                    || in_array(strtoupper((string) $parent->status), ['ESCALATED', 'RESOLVED', 'COMPLETED', 'REJECTED'], true)
                    || $sellerRequest->payouts()->exists()
                    || (Schema::hasTable('cash_refund_obligations') && $sellerRequest->cashRefundObligation()->exists())) {
                    return false;
                }

                $from = $sellerRequest->status;
                $reason = 'Automatically approved because Seller did not respond within the required period.';
                $sellerRequest->update([
                    'status' => SellerReturnRefundRequest::STATUS_APPROVED,
                    'approved_amount' => $sellerRequest->requested_amount,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => now(),
                    'decision_reason' => $reason,
                ]);
                $this->recordEvent(
                    $parent,
                    'SELLER_AUTO_APPROVED_TIMEOUT',
                    null,
                    $sellerRequest,
                    $from,
                    SellerReturnRefundRequest::STATUS_APPROVED,
                    $reason,
                    ['response_deadline' => $deadline->toIso8601String(), 'actor' => 'system'],
                );
                $this->syncParentStatus($parent);

                if (Schema::hasTable('notifications')) {
                    Notification::query()->create([
                        'user_id' => $parent->buyer_user_id,
                        'type' => 'RETURN_REFUND',
                        'title' => 'Refund request automatically approved',
                        'message' => 'The Seller did not respond within three days, so your request '.$parent->request_number.' was automatically approved. The existing return and refund steps still apply.',
                        'reference_type' => SellerReturnRefundRequest::class,
                        'reference_id' => $sellerRequest->id,
                        'action_url' => route('buyer.returns.show', $sellerRequest),
                    ]);
                }

                return true;
            }, attempts: 3);

            $approved += (int) $changed;
        }

        return $approved;
    }

    public function normalizeGcashMobile(string $value): string
    {
        $mobile = preg_replace('/[\s()-]+/', '', trim($value));
        if (! is_string($mobile)) {
            throw ValidationException::withMessages(['gcash_mobile' => 'Enter a valid Philippine GCash mobile number.']);
        }

        if (str_starts_with($mobile, '+639')) {
            $mobile = '0'.substr($mobile, 3);
        } elseif (str_starts_with($mobile, '639')) {
            $mobile = '0'.substr($mobile, 2);
        }

        if (! preg_match('/^09\d{9}$/', $mobile)) {
            throw ValidationException::withMessages(['gcash_mobile' => 'Enter a valid 09XXXXXXXXX or +639XXXXXXXXX number.']);
        }

        return $mobile;
    }

    public function maskMobile(?string $mobile): string
    {
        $mobile = (string) $mobile;
        return $mobile === '' ? 'Not provided' : str_repeat('*', max(0, strlen($mobile) - 4)).substr($mobile, -4);
    }

    public function recordEvent(
        ReturnRefundRequest $request,
        string $eventType,
        ?User $actor = null,
        ?SellerReturnRefundRequest $sellerRequest = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $details = null,
        ?array $metadata = null,
    ): ReturnRefundEvent {
        return ReturnRefundEvent::create([
            'return_refund_request_id' => $request->id,
            'seller_return_refund_request_id' => $sellerRequest?->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'details' => $details,
            'metadata' => $metadata,
        ]);
    }

    public function syncParentStatus(ReturnRefundRequest $request): void
    {
        $children = $request->sellerRequests()->get();
        if ($children->isEmpty()) {
            return;
        }

        $statuses = $children->pluck('status');
        $status = $statuses->every(fn (string $value): bool => in_array($value, SellerReturnRefundRequest::FINAL_STATUSES, true))
            ? 'COMPLETED'
            : ($statuses->contains(SellerReturnRefundRequest::STATUS_ESCALATED) ? 'ESCALATED'
                : ($statuses->contains(fn (string $value): bool => in_array($value, [SellerReturnRefundRequest::STATUS_APPROVED, SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED, SellerReturnRefundRequest::STATUS_REFUND_SENT], true))
                    ? 'PARTIALLY_PROCESSED'
                    : ($statuses->contains(SellerReturnRefundRequest::STATUS_SELLER_REVIEW) ? 'UNDER_SELLER_REVIEW' : 'REQUEST_SUBMITTED')));

        if ($request->status !== $status) {
            $request->update(['status' => $status]);
        }
    }

    public function activeClaimedQuantity(int $orderItemId): int
    {
        if (! DB::getSchemaBuilder()->hasTable('seller_return_refund_requests')) {
            return 0;
        }

        return (int) DB::table('return_refund_request_items as items')
            ->leftJoin('seller_return_refund_requests as seller_requests', 'seller_requests.id', '=', 'items.seller_return_refund_request_id')
            ->join('return_refund_requests as requests', 'requests.id', '=', 'items.return_refund_request_id')
            ->where('items.order_item_id', $orderItemId)
            ->where(function ($query): void {
                $query
                    ->where('seller_requests.status', '<>', SellerReturnRefundRequest::STATUS_REJECTED)
                    ->orWhere(function ($legacy): void {
                        $legacy
                            ->whereNull('items.seller_return_refund_request_id')
                            ->where('requests.status', '<>', 'REJECTED');
                    });
            })
            ->sum('items.quantity');
    }
}
