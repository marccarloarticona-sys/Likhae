<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerPickupRequestProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @param array<int, int> $shipmentIds */
    public function __construct(
        public int $pickupRequestId,
        public array $shipmentIds,
    ) {
    }

    public function broadcastOn(): array
    {
        return array_map(
            static fn (int $shipmentId): PrivateChannel => new PrivateChannel('shipments.'.$shipmentId),
            $this->shipmentIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'seller.pickup-request.updated';
    }

    public function broadcastWith(): array
    {
        return ['pickup_request_id' => $this->pickupRequestId];
    }
}
