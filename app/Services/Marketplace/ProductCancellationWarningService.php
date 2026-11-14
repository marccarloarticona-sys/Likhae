<?php

namespace App\Services\Marketplace;

use App\Mail\AccountAlert;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProductCancellationWarningService
{
    private const CANCELLATION_THRESHOLD = 5;

    private const THRESHOLD_EVENT = 'PRODUCT_CANCELLATION_THRESHOLD_REACHED';

    public function checkThreshold(User $account): void
    {
        if (! $account->isAccountType(User::TYPE_BUYER, User::TYPE_SELLER)
            || ! Schema::hasTable('audit_logs')
            || ! Schema::hasTable('notifications')
            || ! Schema::hasTable('shipment_events')
            || ! Schema::hasTable('shipments')
            || ! Schema::hasTable('seller_orders')
            || ! Schema::hasTable('order_items')
            || ! Schema::hasTable('orders')) {
            return;
        }

        try {
            DB::transaction(function () use ($account): void {
                $lockedAccount = User::query()->whereKey($account->id)->lockForUpdate()->first();
                if (! $lockedAccount) {
                    return;
                }

                $cancellationCount = $lockedAccount->isAccountType(User::TYPE_BUYER)
                    ? $this->buyerCancellationCount($lockedAccount)
                    : $this->sellerCancellationCount($lockedAccount);
                if ($cancellationCount < self::CANCELLATION_THRESHOLD) {
                    return;
                }

                $alreadyNotified = AuditLog::query()
                    ->where('event', self::THRESHOLD_EVENT)
                    ->where('auditable_type', User::class)
                    ->where('auditable_id', $lockedAccount->id)
                    ->exists();
                if ($alreadyNotified) {
                    return;
                }

                $isBuyer = $lockedAccount->isAccountType(User::TYPE_BUYER);
                $roleLabel = $isBuyer ? 'Buyer' : 'Seller';
                $category = $isBuyer ? 'REPEATED_CANCELLATIONS' : 'SELLER_CANCELLATIONS';
                $categoryLabel = $isBuyer ? 'Repeated product cancellations' : 'Repeated seller product cancellations';
                $reference = 'USR-'.$lockedAccount->id.'-CANCEL-'.Str::upper((string) Str::ulid());
                $reason = 'The account reached the automatic reminder threshold after '.$cancellationCount.' product cancellations.';
                $correctiveAction = $isBuyer
                    ? 'Review order details, quantities, and delivery information before placing an order. Contact support if you need help.'
                    : 'Review stock and fulfillment readiness before accepting orders. Contact support if you need help.';
                $userNotificationMessage = 'Your account has reached the automatic reminder threshold after '.$cancellationCount.' product cancellations. This notice is a reminder to review your cancellation pattern; cancellation reasons can vary. '.$correctiveAction.' Reference: '.$reference;
                $warningIssued = AuditLog::query()
                    ->where('event', 'ACCOUNT_WARNING')
                    ->where('auditable_type', User::class)
                    ->where('auditable_id', $lockedAccount->id)
                    ->count() < User::MAX_ACCOUNT_WARNINGS;

                if ($warningIssued) {
                    AuditLog::query()->create([
                        'actor_user_id' => null,
                        'event' => 'ACCOUNT_WARNING',
                        'auditable_type' => User::class,
                        'auditable_id' => $lockedAccount->id,
                        'new_values' => [
                            'account_type' => $lockedAccount->account_type,
                            'category' => $category,
                            'category_label' => $categoryLabel,
                            'reason' => $reason,
                            'corrective_action' => $correctiveAction,
                            'reference' => $reference,
                            'evidence' => $cancellationCount.' product cancellation(s) recorded; automatic reminder threshold: '.self::CANCELLATION_THRESHOLD.'.',
                            'status' => 'OPEN',
                            'automated_trigger' => self::THRESHOLD_EVENT,
                        ],
                        'created_at' => now(),
                    ]);
                }

                $userNotificationType = $warningIssued ? 'ACCOUNT_WARNING' : 'PRODUCT_CANCELLATION_THRESHOLD';
                $userNotificationTitle = $warningIssued ? 'Account reminder from LIKHAE' : 'Product cancellation reminder';
                Notification::query()->create([
                    'user_id' => $lockedAccount->id,
                    'type' => $userNotificationType,
                    'title' => $userNotificationTitle,
                    'message' => $userNotificationMessage,
                    'reference_type' => User::class,
                    'reference_id' => $lockedAccount->id,
                    'action_url' => route('notifications.index'),
                ]);

                if (filled($lockedAccount->email) && $lockedAccount->email_verified_at !== null) {
                    DB::afterCommit(function () use ($lockedAccount, $userNotificationMessage, $userNotificationType, $userNotificationTitle): void {
                        try {
                            Mail::to($lockedAccount->email)->send(new AccountAlert(
                                type: $userNotificationType,
                                title: $userNotificationTitle,
                                body: $userNotificationMessage,
                                actionUrl: route('notifications.index'),
                                userName: $lockedAccount->name,
                            ));
                        } catch (Throwable $exception) {
                            Log::warning('Automatic product cancellation warning email could not be sent.', [
                                'user_id' => $lockedAccount->id,
                                'exception' => $exception->getMessage(),
                            ]);
                        }
                    });
                }

                AuditLog::query()->create([
                    'actor_user_id' => null,
                    'event' => self::THRESHOLD_EVENT,
                    'auditable_type' => User::class,
                    'auditable_id' => $lockedAccount->id,
                    'new_values' => [
                        'account_type' => $lockedAccount->account_type,
                        'cancellation_count' => $cancellationCount,
                        'threshold' => self::CANCELLATION_THRESHOLD,
                        'automated_warning_issued' => $warningIssued,
                        'reference' => $reference,
                        'status' => 'OPEN',
                    ],
                    'created_at' => now(),
                ]);

                $adminMessage = $roleLabel.' '.$lockedAccount->name.' (account #'.$lockedAccount->id.') reached '.$cancellationCount.' product cancellations. '.($warningIssued
                    ? 'An automatic account warning was issued.'
                    : 'The account is at the warning limit, so no additional account warning was issued.'). ' Review the account and cancellation history.';

                User::query()
                    ->where('account_type', User::TYPE_ADMIN)
                    ->where('status', User::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->pluck('id')
                    ->each(fn ($adminId) => Notification::query()->create([
                        'user_id' => $adminId,
                        'type' => self::THRESHOLD_EVENT,
                        'title' => $roleLabel.' cancellation threshold reached',
                        'message' => $adminMessage,
                        'reference_type' => User::class,
                        'reference_id' => $lockedAccount->id,
                        'action_url' => route('admin.users.show', $lockedAccount),
                    ]));
            });
        } catch (Throwable $exception) {
            Log::warning('Automatic product cancellation warning could not be recorded.', [
                'user_id' => $account->id,
                'account_type' => $account->account_type,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function buyerCancellationCount(User $buyer): int
    {
        return (int) DB::table('shipment_events as events')
            ->join('shipments', 'shipments.id', '=', 'events.shipment_id')
            ->join('seller_orders', 'seller_orders.id', '=', 'shipments.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->join('order_items', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->where('events.actor_user_id', $buyer->id)
            ->where('orders.buyer_user_id', $buyer->id)
            ->where('events.notes', 'like', 'BUYER_ORDER_CANCELLED:%')
            ->distinct()
            ->count('order_items.id');
    }

    private function sellerCancellationCount(User $seller): int
    {
        $wholeOrderItems = DB::table('shipment_events as events')
            ->join('shipments', 'shipments.id', '=', 'events.shipment_id')
            ->join('seller_orders', 'seller_orders.id', '=', 'shipments.seller_order_id')
            ->join('order_items', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->where('events.actor_user_id', $seller->id)
            ->where('events.notes', 'like', 'SELLER_ORDER_CANCELLED:%')
            ->select('order_items.id as order_item_id');

        if (! Schema::hasColumn('shipments', 'order_item_id')) {
            return (int) DB::query()
                ->fromSub($wholeOrderItems, 'cancelled_products')
                ->distinct()
                ->count('order_item_id');
        }

        $individualItems = DB::table('shipment_events as events')
            ->join('shipments', 'shipments.id', '=', 'events.shipment_id')
            ->where('events.actor_user_id', $seller->id)
            ->where('events.notes', 'like', 'SELLER_ORDER_ITEM_CANCELLED:%')
            ->whereNotNull('shipments.order_item_id')
            ->select('shipments.order_item_id');

        return (int) DB::query()
            ->fromSub($wholeOrderItems->union($individualItems), 'cancelled_products')
            ->distinct()
            ->count('order_item_id');
    }
}
