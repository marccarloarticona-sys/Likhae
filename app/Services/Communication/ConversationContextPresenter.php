<?php

namespace App\Services\Communication;

use App\Models\Communication\Conversation;
use App\Models\Logistics\Shipment;
use App\Models\User;

class ConversationContextPresenter
{
    public function __construct(private readonly ConversationAuthorizationService $authorization) {}

    /** @return array<string, mixed>|null */
    public function forUser(User $user, ?Conversation $conversation): ?array
    {
        if (! $conversation) {
            return null;
        }
        $this->authorization->assertCanAccess($user, $conversation);

        if ($conversation->shipment_id) {
            $shipment = Shipment::query()->with([
                'sellerOrder.order.buyer',
                'sellerOrder.items',
                'orderItem',
                'sellerOrder.sellerProfile',
                'logisticsCenter.owner',
                'riderAssignments.riderProfile.user',
            ])->find($conversation->shipment_id);
            if (! $shipment) {
                return null;
            }

            $data = [
                'heading' => 'Shipment context',
                'tracking' => $shipment->tracking_number ?: 'Tracking number unavailable',
                'stage' => str($shipment->current_status ?: 'UNKNOWN')->replace('_', ' ')->title()->toString(),
                'seller' => $shipment->sellerOrder?->sellerProfile?->business_name,
                'items' => collect([$shipment->orderItem?->product_name])
                    ->merge($shipment->sellerOrder?->items?->pluck('product_name') ?? [])
                    ->filter()
                    ->unique()
                    ->take(4)
                    ->values()
                    ->all(),
                'buyer' => null,
                'rider' => null,
            ];

            if ($user->isAccountType(User::TYPE_ADMIN, User::TYPE_LOGISTICS, User::TYPE_RIDER)) {
                $data['buyer'] = $shipment->sellerOrder?->order?->buyer?->name;
            }
            if ($user->isAccountType(User::TYPE_LOGISTICS)) {
                $data['rider'] = $shipment->riderAssignments
                    ->first(fn ($assignment) => $assignment->riderProfile?->status === 'ACTIVE')
                    ?->riderProfile?->user?->name;
            }

            return $data;
        }

        if ($conversation->seller_order_id) {
            $sellerOrder = $conversation->sellerOrder()->with(['order', 'sellerProfile', 'items'])->first();
            if (! $sellerOrder) {
                return null;
            }

            return [
                'heading' => 'Order context',
                'order_number' => $sellerOrder->order?->order_number ?: 'Order',
                'seller' => $sellerOrder->sellerProfile?->business_name,
                'status' => str($sellerOrder->status ?: 'UNKNOWN')->replace('_', ' ')->title()->toString(),
                'items' => $sellerOrder->items
                    ->pluck('product_name')
                    ->filter()
                    ->unique()
                    ->take(4)
                    ->values()
                    ->all(),
            ];
        }

        if ($conversation->order_id) {
            $order = $conversation->order()->with('sellerOrders.items')->first();
            if (! $order) {
                return null;
            }

            return [
                'heading' => 'Support order context',
                'order_number' => $order->order_number ?: 'Order',
                'status' => str($order->status ?: 'UNKNOWN')->replace('_', ' ')->title()->toString(),
                'items' => $order->sellerOrders
                    ->flatMap(fn ($sellerOrder) => $sellerOrder->items->pluck('product_name'))
                    ->filter()
                    ->unique()
                    ->take(6)
                    ->values()
                    ->all(),
            ];
        }

        return null;
    }
}
