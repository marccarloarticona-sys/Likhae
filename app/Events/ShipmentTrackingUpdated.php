<?php

namespace App\Events;

use App\Models\Logistics\Shipment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentTrackingUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $connection = 'background';

    public function __construct(
        public int $shipmentId,
        public string $status,
        public ?int $assignmentId = null,
        public ?string $assignmentType = null,
    ) {
    }

    public static function fromShipment(Shipment $shipment): self
    {
        $assignment = match ($shipment->current_status) {
            'PICKUP_ASSIGNED' => $shipment->riderAssignments
                ->where('assignment_type', 'PICKUP')
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                ->sortByDesc('id')
                ->first(),
            'READY_FOR_PICKUP' => $shipment->riderAssignments
                ->where('assignment_type', 'PICKUP')
                ->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
                ->sortByDesc('id')
                ->first(),
            'PICKED_UP' => $shipment->riderAssignments
                ->where('assignment_type', 'PICKUP')
                ->where('status', 'COMPLETED')
                ->sortByDesc('id')
                ->first(),
            'OUT_FOR_DELIVERY' => $shipment->riderAssignments
                ->where('assignment_type', 'DELIVERY')
                ->where('status', 'IN_PROGRESS')
                ->sortByDesc('id')
                ->first(),
            default => null,
        };

        return new self(
            (int) $shipment->id,
            (string) $shipment->current_status,
            $assignment?->id,
            $assignment?->assignment_type,
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('shipments.'.$this->shipmentId)];
    }

    public function broadcastAs(): string
    {
        return 'shipment.tracking.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'current_status' => $this->status,
            'assignment_id' => $this->assignmentId,
            'assignment_type' => $this->assignmentType,
        ];
    }
}
