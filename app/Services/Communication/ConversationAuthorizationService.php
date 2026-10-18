<?php

namespace App\Services\Communication;

use App\Models\Communication\Conversation;
use App\Models\Logistics\Shipment;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ConversationAuthorizationService
{
    public function canAccess(User $user, Conversation $conversation): bool
    {
        try {
            $this->assertCanAccess($user, $conversation);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public function assertCanAccess(User $user, Conversation $conversation): void
    {
        abort_unless($user->isActive(), 403);

        $participants = $conversation->participantRecords()
            ->whereNull('left_at')
            ->with('user')
            ->get();
        abort_unless($participants->contains(fn ($participant): bool => (int) $participant->user_id === (int) $user->id), 403);

        $participantIds = $participants->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        if ($conversation->seller_order_id && ! $conversation->shipment_id) {
            $sellerOrder = $conversation->sellerOrder()->with(['order', 'sellerProfile'])->first();
            if ($sellerOrder) {
                abort_unless(! $conversation->order_id || (int) $sellerOrder->order_id === (int) $conversation->order_id, 403);
                $this->assertParticipantsIn($participants, [
                    (int) $sellerOrder->order?->buyer_user_id,
                    (int) $sellerOrder->sellerProfile?->user_id,
                ], allowAdmin: true);
            }
        } elseif ($conversation->order_id && ! $conversation->shipment_id) {
            $order = $conversation->order()->first();
            if ($order) {
                $this->assertParticipantsIn($participants, [(int) $order->buyer_user_id], allowAdmin: true);
            }
        }

        if ($conversation->shipment_id) {
            $shipment = Shipment::query()->with([
                'sellerOrder.order',
                'sellerOrder.sellerProfile',
                'logisticsCenter.owner',
                'originLogisticsCenter.owner',
                'destinationLogisticsCenter.owner',
                'serviceArea.logisticsCenter.owner',
                'riderAssignments.riderProfile.user',
            ])->find($conversation->shipment_id);

            if ($shipment) {
                abort_unless(! $conversation->seller_order_id || (int) $shipment->seller_order_id === (int) $conversation->seller_order_id, 403);
                abort_unless(! $conversation->order_id || (int) $shipment->sellerOrder?->order_id === (int) $conversation->order_id, 403);
                $eligibleIds = collect([
                    $shipment->sellerOrder?->order?->buyer_user_id,
                    $shipment->sellerOrder?->sellerProfile?->user_id,
                    $shipment->logisticsCenter?->owner_user_id,
                    $shipment->originLogisticsCenter?->owner_user_id,
                    $shipment->destinationLogisticsCenter?->owner_user_id,
                    $shipment->serviceArea?->logisticsCenter?->owner_user_id,
                ])
                    ->merge($shipment->riderAssignments->map(function ($assignment) {
                        if ($assignment->riderProfile?->status !== 'ACTIVE'
                            || $assignment->riderProfile?->user?->status !== User::STATUS_ACTIVE
                            || ! in_array($assignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'], true)) {
                            return null;
                        }

                        return $assignment->riderProfile?->user_id;
                    }))
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $this->assertParticipantsIn($participants, $eligibleIds, allowAdmin: true);
            }
        }

        // Retain the explicit product inquiry and support relationships used by
        // existing web flows; context-specific order/shipment records above
        // further narrow access whenever those references are present.
        if ($participantIds === []) {
            abort(403);
        }
    }

    /** @param Collection<int, mixed> $participants
     *  @param array<int, int> $allowedIds
     */
    private function assertParticipantsIn(Collection $participants, array $allowedIds, bool $allowAdmin): void
    {
        $allowed = collect($allowedIds)->filter()->map(fn ($id): int => (int) $id)->all();
        $valid = $participants->every(function ($participant) use ($allowed, $allowAdmin): bool {
            return in_array((int) $participant->user_id, $allowed, true)
                || ($allowAdmin && $participant->user?->isAccountType(User::TYPE_ADMIN));
        });

        abort_unless($valid, 403);
    }
}
