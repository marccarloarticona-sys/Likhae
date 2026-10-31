<?php

namespace App\Services;

use App\Mail\AccountAlert;
use App\Models\Admin\Notification;
use App\Models\Buyer\Order;
use App\Models\Buyer\Review;
use App\Models\Finance\CodCollection;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AccountNotificationService
{
    public function orderPlaced(Order $order): void
    {
        $order->loadMissing('buyer');
        $this->record(
            $order->buyer,
            'ORDER_PLACED',
            'Order placed',
            'Your order '.$order->order_number.' was placed successfully.',
            'ORDER',
            $order->id,
            '/buyer/orders/'.$order->id,
        );
    }

    public function orderStatusChanged(Order $order): void
    {
        $order->loadMissing('buyer');
        $status = $this->label($order->status);

        $this->record(
            $order->buyer,
            'ORDER_'.strtoupper((string) $order->status),
            'Order '.$status,
            'Your order '.$order->order_number.'.',
            'ORDER_STATUS',
            $order->id,
            '/buyer/orders/'.$order->id,
        );

        if ($order->status === 'COMPLETED') {
            $this->record(
                $order->buyer,
                'REWARD_ORDER_COMPLETED',
                'Rewards added',
                'You earned 50 reward points for completing order '.$order->order_number.'.',
                'ORDER_REWARD',
                $order->id,
                '/buyer/rewards',
            );
        }
    }

    public function sellerOrderCreated(SellerOrder $sellerOrder): void
    {
        $sellerOrder->loadMissing('sellerProfile.user', 'order');
        $this->record(
            $sellerOrder->sellerProfile?->user,
            'SELLER_ORDER_PLACED',
            'New order received',
            'Order '.$sellerOrder->seller_order_number.' is ready to process.',
            'SELLER_ORDER',
            $sellerOrder->id,
            '/seller/orders',
        );
    }

    public function sellerOrderStatusChanged(SellerOrder $sellerOrder): void
    {
        $sellerOrder->loadMissing('sellerProfile.user', 'order.buyer');
        $status = $this->label($sellerOrder->status);

        $this->record(
            $sellerOrder->order?->buyer,
            'SELLER_ORDER_'.strtoupper((string) $sellerOrder->status),
            'Order update: '.$status,
            'Seller order '.$sellerOrder->seller_order_number.'.',
            'SELLER_ORDER_STATUS',
            $sellerOrder->id,
            '/buyer/orders/'.$sellerOrder->order_id,
        );

        $this->record(
            $sellerOrder->sellerProfile?->user,
            'SELLER_ORDER_STATUS_'.strtoupper((string) $sellerOrder->status),
            'Order status: '.$status,
            'Order '.$sellerOrder->seller_order_number.'.',
            'SELLER_ORDER_STATUS_SELLER',
            $sellerOrder->id,
            '/seller/orders',
        );
    }

    public function shipmentStatusChanged(Shipment $shipment): void
    {
        $shipment->loadMissing('sellerOrder.order.buyer', 'sellerOrder.sellerProfile.user', 'logisticsCenter.owner');
        $status = $this->label($shipment->current_status);
        $message = 'Parcel '.$shipment->tracking_number.'.';

        $this->record($shipment->sellerOrder?->order?->buyer, 'SHIPMENT_'.strtoupper((string) $shipment->current_status), 'Delivery update: '.$status, $message, 'SHIPMENT_STATUS', $shipment->id, '/buyer/orders/'.$shipment->sellerOrder?->order_id);
        $this->record($shipment->sellerOrder?->sellerProfile?->user, 'SELLER_SHIPMENT_'.strtoupper((string) $shipment->current_status), 'Delivery update: '.$status, $message, 'SELLER_SHIPMENT_STATUS', $shipment->id, '/seller/orders');
        $this->record($shipment->logisticsCenter?->owner, 'LOGISTICS_SHIPMENT_'.strtoupper((string) $shipment->current_status), 'Parcel status: '.$status, $message, 'LOGISTICS_SHIPMENT_STATUS', $shipment->id, '/logistics/parcels/'.$shipment->id);
    }

    public function riderAssignmentCreated(RiderAssignment $assignment): void
    {
        $assignment->loadMissing('riderProfile.user', 'shipment');
        $type = $this->label($assignment->assignment_type);
        $this->record(
            $assignment->riderProfile?->user,
            'RIDER_ASSIGNMENT',
            'New '.$type.' assignment',
            'You have been assigned parcel '.$assignment->shipment?->tracking_number.'.',
            'RIDER_ASSIGNMENT',
            $assignment->id,
            '/rider/dashboard',
            email: true,
        );
    }

    public function riderModerationChanged(RiderProfile $rider, string $status, string $reason): void
    {
        $rider->loadMissing('user');
        $label = $this->label($status);
        $reasonText = trim($reason);
        $message = 'Your rider account status is now '.$label.'.';
        if ($reasonText !== '') {
            $message .= ' Reason: '.$reasonText;
        }

        $this->record(
            $rider->user,
            'RIDER_MODERATION_'.strtoupper($status),
            'Rider account: '.$label,
            $message,
            'RIDER_MODERATION',
            $rider->id,
            in_array($status, ['SUSPENDED', 'BANNED'], true) ? null : '/notifications',
            email: true,
            allowInactive: true,
        );
    }

    public function riderCodRemittanceOverdue(CodCollection $collection, string $requiredAmount): void
    {
        $collection->loadMissing('riderProfile.user', 'shipment');
        $trackingNumber = $collection->shipment?->tracking_number ?: 'parcel #'.$collection->shipment_id;
        $message = 'The COD cash for parcel '.$trackingNumber.' has not been remitted within 24 hours. '
            .'Your account has been marked Warned. Remit the full COD due of PHP '.$requiredAmount
            .', cover any buyer collection shortfall, and have Logistics verify the cash received.';

        $this->record(
            $collection->riderProfile?->user,
            'RIDER_COD_REMITTANCE_OVERDUE',
            'Account warning: COD remittance overdue',
            $message,
            CodCollection::class,
            (int) $collection->id,
            '/rider/remittances',
            email: true,
        );
    }

    public function riderAssignmentStatusChanged(RiderAssignment $assignment): void
    {
        $assignment->loadMissing('riderProfile.user', 'shipment');
        $status = $this->label($assignment->status);
        $this->record(
            $assignment->riderProfile?->user,
            'RIDER_ASSIGNMENT_'.strtoupper((string) $assignment->status),
            'Assignment '.$status,
            'Your '.$this->label($assignment->assignment_type).' assignment for '.$assignment->shipment?->tracking_number.' is '.$status.'.',
            'RIDER_ASSIGNMENT_STATUS',
            $assignment->id,
            '/rider/dashboard',
            email: true,
        );
    }

    public function sellerPickupBatchAssigned(RiderLocalTrip $trip): void
    {
        $trip->loadMissing('seller.user', 'rider.user', 'items');
        $riderName = $trip->rider?->user?->name ?: 'A local rider';
        $parcelCount = $trip->items->count();

        $this->record(
            $trip->seller?->user,
            'SELLER_PICKUP_ASSIGNED',
            'Pickup rider assigned',
            $riderName.' was assigned to collect '.$parcelCount.' parcel'.($parcelCount === 1 ? '' : 's').' in pickup batch #P-'.$trip->id.'.',
            'SELLER_PICKUP_BATCH',
            $trip->id,
            '/seller/orders',
        );
    }

    public function sellerPickupBatchStarted(RiderLocalTrip $trip): void
    {
        $trip->loadMissing('seller.user', 'rider.user', 'items');
        $riderName = $trip->rider?->user?->name ?: 'The assigned rider';

        $this->record(
            $trip->seller?->user,
            'SELLER_PICKUP_STARTED',
            'Rider started pickup',
            $riderName.' started pickup batch #P-'.$trip->id.'. Follow each parcel scan in Orders.',
            'SELLER_PICKUP_BATCH',
            $trip->id,
            '/seller/orders',
        );
    }

    public function sellerPickupBatchFinished(RiderLocalTrip $trip): void
    {
        $trip->loadMissing('seller.user', 'items.riderAssignment');
        $total = $trip->items->count();
        $collected = $trip->items->filter(fn ($item): bool => $item->riderAssignment?->status === 'COMPLETED')->count();

        $this->record(
            $trip->seller?->user,
            'SELLER_PICKUP_COMPLETED',
            'Seller pickup finished',
            'Pickup batch #P-'.$trip->id.' finished at '.$collected.' / '.$total.' parcels collected.',
            'SELLER_PICKUP_BATCH',
            $trip->id,
            '/seller/orders',
        );
    }

    public function reviewSubmitted(Review $review): void
    {
        $review->loadMissing('buyer', 'orderItem.sellerOrder.sellerProfile.user');
        $sellerOrder = $review->orderItem?->sellerOrder;

        $this->record(
            $review->buyer,
            'REWARD_REVIEW_SUBMITTED',
            'Rewards added',
            'You earned 20 reward points for submitting a product review.',
            'REVIEW_REWARD',
            $review->id,
            '/buyer/rewards',
        );

        $this->record(
            $sellerOrder?->sellerProfile?->user,
            'SELLER_REVIEW_SUBMITTED',
            'New product review',
            'A buyer left a '.$review->rating.'-star review for order '.$sellerOrder?->seller_order_number.'.',
            'SELLER_REVIEW',
            $review->id,
            '/seller/reviews',
        );
    }

    public function riderEarningCreated(RiderEarning $earning): void
    {
        $earning->loadMissing('riderProfile.user');
        $this->record(
            $earning->riderProfile?->user,
            'RIDER_EARNING',
            'Delivery earnings added',
            '₱'.number_format((float) $earning->amount, 2).' was added to your pending earnings.',
            'RIDER_EARNING',
            $earning->id,
            '/rider/earnings',
        );
    }

    private function record(
        ?User $user,
        string $type,
        string $title,
        string $message,
        string $referenceType,
        int $referenceId,
        ?string $actionUrl,
        bool $email = false,
        bool $allowInactive = false,
    ): void
    {
        if (! $user || (! $user->isActive() && ! $allowInactive)) {
            return;
        }

        $preferenceKey = $this->sellerNotificationPreferenceKey($user, $type);
        if ($preferenceKey !== null
            && ! (bool) data_get($user->notification_preferences, $preferenceKey, true)) {
            return;
        }

        $notification = Notification::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ],
            [
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
            ],
        );

        if ($email && $notification->wasRecentlyCreated && $user->email_verified_at !== null && filled($user->email)) {
            DB::afterCommit(function () use ($user, $notification): void {
                try {
                    Mail::to($user->email)->send(new AccountAlert(
                        type: $notification->type,
                        title: $notification->title,
                        body: $notification->message,
                        actionUrl: $notification->action_url ?: '/notifications',
                        actionLabel: 'View notification',
                        userName: $user->name,
                    ));
                } catch (Throwable $exception) {
                    Log::warning('Account alert email could not be sent.', [
                        'notification_id' => $notification->id,
                        'user_id' => $user->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
        }
    }

    private function sellerNotificationPreferenceKey(User $user, string $type): ?string
    {
        if (! $user->isAccountType(User::TYPE_SELLER)) {
            return null;
        }

        return match (true) {
            str_starts_with($type, 'SELLER_ORDER'),
            str_starts_with($type, 'SELLER_SHIPMENT'),
            str_starts_with($type, 'SELLER_PICKUP') => 'seller_order_updates',
            str_starts_with($type, 'SELLER_REVIEW') => 'seller_review_updates',
            default => null,
        };
    }

    private function label(?string $value): string
    {
        return str((string) $value)->replace('_', ' ')->headline()->toString();
    }
}
