<?php

namespace App\Services\Communication;

use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\User;
use Illuminate\Support\Collection;

class ConversationStartAuthorizationService
{
    /** @param Collection<int, User> $contacts
     *  @return Collection<int, User>
     */
    public function filterRecipients(User $sender, Collection $contacts): Collection
    {
        return $contacts
            ->filter(fn (User $recipient): bool => $this->contextForNew($sender, $recipient) !== null)
            ->values();
    }

    /**
     * Return trusted context for a new direct conversation, or null when the
     * sender/recipient relationship is not supported by the current workflow.
     *
     * @return array<string, int|string|null>|null
     */
    public function contextForNew(User $sender, User $recipient, ?int $requestedShipmentId = null): ?array
    {
        if (! $sender->isActive() || ! $recipient->isActive() || (int) $sender->id === (int) $recipient->id) {
            return null;
        }

        if ($sender->isAccountType(User::TYPE_ADMIN)) {
            return [];
        }

        if ($sender->isAccountType(User::TYPE_BUYER)) {
            return $recipient->isAccountType(User::TYPE_ADMIN)
                || ($recipient->isAccountType(User::TYPE_SELLER) && $recipient->sellerProfile?->status === 'ACTIVE')
                ? ['type' => 'DIRECT']
                : null;
        }

        if ($sender->isAccountType(User::TYPE_SELLER)) {
            if ($recipient->isAccountType(User::TYPE_ADMIN)) {
                return [];
            }

            if ($recipient->isAccountType(User::TYPE_BUYER)) {
                $sellerOrder = \App\Models\Seller\SellerOrder::query()
                    ->whereHas('sellerProfile', fn ($query) => $query->where('user_id', $sender->id))
                    ->whereHas('order', fn ($query) => $query->where('buyer_user_id', $recipient->id))
                    ->latest('id')
                    ->first();

                return $sellerOrder ? [
                    'type' => 'ORDER_SELLER',
                    'order_id' => (int) $sellerOrder->order_id,
                    'seller_order_id' => (int) $sellerOrder->id,
                ] : null;
            }

            if ($recipient->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER)) {
                $shipment = $this->shipmentForPair($sender, $recipient, $requestedShipmentId);

                return $shipment ? $this->shipmentContext($shipment) : null;
            }

            return null;
        }

        if ($sender->isAccountType(User::TYPE_LOGISTICS)) {
            if ($recipient->isAccountType(User::TYPE_ADMIN)) {
                return [];
            }
            if (! $recipient->isAccountType(User::TYPE_SELLER, User::TYPE_RIDER)) {
                return null;
            }

            $shipment = $this->shipmentForPair($sender, $recipient, $requestedShipmentId);

            return $shipment ? $this->shipmentContext($shipment) : null;
        }

        if ($sender->isAccountType(User::TYPE_RIDER)) {
            if ($recipient->isAccountType(User::TYPE_ADMIN)) {
                return [];
            }
            if (! $recipient->isAccountType(User::TYPE_LOGISTICS, User::TYPE_SELLER, User::TYPE_BUYER)) {
                return null;
            }

            $shipment = $this->shipmentForPair($sender, $recipient, $requestedShipmentId);

            return $shipment ? $this->shipmentContext($shipment) : null;
        }

        return null;
    }

    private function shipmentForPair(User $sender, User $recipient, ?int $requestedShipmentId): ?Shipment
    {
        $query = Shipment::query()
            ->with(['sellerOrder.order', 'sellerOrder.sellerProfile'])
            ->when($requestedShipmentId, fn ($query) => $query->whereKey($requestedShipmentId));

        if ($sender->isAccountType(User::TYPE_SELLER)) {
            $query->whereHas('sellerOrder.sellerProfile', fn ($seller) => $seller->where('user_id', $sender->id));
            if ($recipient->isAccountType(User::TYPE_LOGISTICS)) {
                $this->whereLogisticsOwnsShipment($query, (int) $recipient->id);
            } else {
                $query->whereHas('riderAssignments', fn ($assignment) => $assignment
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'])
                    ->whereHas('riderProfile', fn ($rider) => $rider->where('user_id', $recipient->id)->where('status', 'ACTIVE')));
            }
        } elseif ($sender->isAccountType(User::TYPE_LOGISTICS)) {
            $this->whereLogisticsOwnsShipment($query, (int) $sender->id);
            if ($recipient->isAccountType(User::TYPE_SELLER)) {
                $query->whereHas('sellerOrder.sellerProfile', fn ($seller) => $seller->where('user_id', $recipient->id));
            } else {
                $query->whereHas('riderAssignments', fn ($assignment) => $assignment
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'])
                    ->whereHas('riderProfile', fn ($rider) => $rider->where('user_id', $recipient->id)->where('status', 'ACTIVE')));
            }
        } elseif ($sender->isAccountType(User::TYPE_RIDER)) {
            $riderProfileId = (int) $sender->riderProfile?->id;
            $query->whereHas('riderAssignments', function ($assignment) use ($riderProfileId, $recipient): void {
                $assignment->where('rider_profile_id', $riderProfileId)
                    ->when($recipient->isAccountType(User::TYPE_BUYER), fn ($assignment) => $assignment->where('assignment_type', 'DELIVERY'))
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED']);
            });

            if ($recipient->isAccountType(User::TYPE_BUYER)) {
                $query->whereHas('sellerOrder.order', fn ($order) => $order->where('buyer_user_id', $recipient->id));
            } elseif ($recipient->isAccountType(User::TYPE_SELLER)) {
                $query->whereHas('sellerOrder.sellerProfile', fn ($seller) => $seller->where('user_id', $recipient->id));
            } else {
                $this->whereLogisticsOwnsShipment($query, (int) $recipient->id);
            }
        }

        return $query->latest('id')->first();
    }

    private function whereLogisticsOwnsShipment($query, int $userId): void
    {
        $query->where(function ($shipment) use ($userId): void {
            $shipment->whereHas('logisticsCenter', fn ($center) => $center->where('owner_user_id', $userId))
                ->orWhereHas('originLogisticsCenter', fn ($center) => $center->where('owner_user_id', $userId))
                ->orWhereHas('destinationLogisticsCenter', fn ($center) => $center->where('owner_user_id', $userId))
                ->orWhereHas('serviceArea.logisticsCenter', fn ($center) => $center->where('owner_user_id', $userId));
        });
    }

    /** @return array<string, int|string|null> */
    private function shipmentContext(Shipment $shipment): array
    {
        return [
            'type' => 'SHIPMENT',
            'order_id' => $shipment->sellerOrder?->order_id,
            'seller_order_id' => $shipment->seller_order_id,
            'shipment_id' => $shipment->id,
        ];
    }
}
