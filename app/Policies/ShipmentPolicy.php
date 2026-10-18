<?php

namespace App\Policies;

use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Services\Fulfillment\LogisticsShipmentQuery;

class ShipmentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAccountType(User::TYPE_ADMIN) ? true : null;
    }

    public function view(User $user, Shipment $shipment): bool
    {
        $shipment->loadMissing([
            'sellerOrder.order',
            'sellerOrder.sellerProfile',
            'logisticsCenter',
            'riderAssignments.riderProfile',
        ]);

        if ($user->isAccountType(User::TYPE_BUYER)) {
            return $shipment->sellerOrder?->order?->buyer_user_id === $user->id;
        }

        if ($user->isAccountType(User::TYPE_SELLER)) {
            return $shipment->sellerOrder?->sellerProfile?->user_id === $user->id;
        }

        if ($user->isAccountType(User::TYPE_LOGISTICS)) {
            $center = $user->logisticsCenter;

            return $user->isActive()
                && $center !== null
                && $center->status === 'ACTIVE'
                && $center->approved_by_user_id !== null
                && $center->approved_at !== null
                && app(LogisticsShipmentQuery::class)->visibleTo($center)->whereKey($shipment->id)->exists();
        }

        if ($user->isAccountType(User::TYPE_RIDER)) {
            return $shipment->riderAssignments->contains(
                fn ($assignment) => $assignment->riderProfile?->user_id === $user->id
            );
        }

        return false;
    }

    public function viewProof(User $user, Shipment $shipment): bool
    {
        return $this->view($user, $shipment);
    }
}
