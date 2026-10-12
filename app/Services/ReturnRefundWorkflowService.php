<?php

namespace App\Services;

use App\Models\Buyer\ReturnRefundEvent;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnRefundWorkflowService
{
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
                    ->whereNotIn('seller_requests.status', SellerReturnRefundRequest::FINAL_STATUSES)
                    ->orWhere(function ($legacy): void {
                        $legacy
                            ->whereNull('items.seller_return_refund_request_id')
                            ->whereNotIn('requests.status', ['COMPLETED', 'REJECTED', 'RESOLVED']);
                    });
            })
            ->sum('items.quantity');
    }
}
