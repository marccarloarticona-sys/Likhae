<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Communication\Conversation;
use App\Models\Logistics\Shipment;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversations.{conversation}', function ($user, Conversation $conversation) {
    return app(\App\Services\Communication\ConversationAuthorizationService::class)
        ->canAccess($user, $conversation);
});

Broadcast::channel('shipments.{shipment}', function ($user, Shipment $shipment) {
    $shipment->loadMissing([
        'sellerOrder.order',
        'sellerOrder.sellerProfile',
        'logisticsCenter',
        'serviceArea.logisticsCenter',
        'riderAssignments.riderProfile.user',
    ]);

    // A previously authenticated browser session must not retain access after
    // the account has been suspended or deactivated.
    if (! $user->isActive()) {
        return false;
    }

    if ($user->isAccountType('ADMIN')) {
        return true;
    }

    if ($user->isAccountType('BUYER')) {
        return (int) $shipment->sellerOrder?->order?->buyer_user_id === (int) $user->id;
    }

    if ($user->isAccountType('SELLER')) {
        return (int) $shipment->sellerOrder?->sellerProfile?->user_id === (int) $user->id;
    }

    if ($user->isAccountType('LOGISTICS')) {
        $center = $shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter;
        return (int) $center?->owner_user_id === (int) $user->id;
    }

    if ($user->isAccountType('RIDER')) {
        $isDeliveryRider = $shipment->riderAssignments
            ->contains(function ($assignment) use ($shipment, $user): bool {
                if ((int) $assignment->riderProfile?->user_id !== (int) $user->id
                    || $assignment->riderProfile?->status !== 'ACTIVE'
                    || $assignment->riderProfile?->user?->status !== 'ACTIVE') {
                    return false;
                }

                if ($assignment->assignment_type === 'PICKUP') {
                    return ($shipment->current_status === 'READY_FOR_PICKUP'
                            && in_array($assignment->status, ['ACCEPTED', 'IN_PROGRESS'], true))
                        || ($shipment->current_status === 'PICKED_UP'
                            && $assignment->status === 'COMPLETED');
                }

                return $assignment->assignment_type === 'DELIVERY'
                    && in_array($shipment->current_status, ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'], true)
                    && in_array($assignment->status, ['ACCEPTED', 'IN_PROGRESS'], true);
            });
        if ($isDeliveryRider) {
            return true;
        }

        $rider = $user->riderProfile;
        if (! $rider || $rider->status !== 'ACTIVE') {
            return false;
        }
        $schema = app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class);
        if (! $schema->shipmentReturnsReady()) {
            return false;
        }

        $returnWorkflow = \App\Models\Logistics\ShipmentReturnWorkflow::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', \App\Models\Logistics\ShipmentReturnWorkflow::OPEN_STATUSES)
            ->first();
        if (! $returnWorkflow) {
            return false;
        }

        $originalRiderStillHoldsParcel = in_array($returnWorkflow->status, [
            \App\Models\Logistics\ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF,
            \App\Models\Logistics\ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT,
        ], true) && (int) $returnWorkflow->originalDeliveryAssignment()->value('rider_profile_id') === (int) $rider->id;
        if ($originalRiderStillHoldsParcel || (int) $returnWorkflow->return_rider_profile_id === (int) $rider->id) {
            return true;
        }

        return $schema->shipmentReturnEnhancementsReady()
            && (int) $returnWorkflow->pending_return_rider_profile_id === (int) $rider->id;
    }

    return false;
});
