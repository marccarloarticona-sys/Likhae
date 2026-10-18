<?php

namespace App\Console\Commands;

use App\Models\Rider\RiderAssignment;
use App\Models\Logistics\Shipment;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireRiderAssignmentOffers extends Command
{
    protected $signature = 'likhae:expire-rider-assignment-offers';

    protected $description = 'Expire delivery offers that riders have not accepted in time';

    public function handle(ShipmentWorkflowService $workflow): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('rider.assignment_offer_minutes', 15)));
        $expired = 0;

        RiderAssignment::query()
            ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
            ->where('status', 'ASSIGNED')
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($assignments) use ($cutoff, $workflow, &$expired): void {
                foreach ($assignments as $candidate) {
                    $wasExpired = DB::transaction(function () use ($candidate, $cutoff, $workflow): bool {
                        $shipmentId = RiderAssignment::query()->whereKey($candidate->id)->value('shipment_id');
                        $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();
                        if (! $shipment) {
                            return false;
                        }
                        $assignment = RiderAssignment::query()
                            ->whereKey($candidate->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $assignment || $assignment->assignment_type !== RiderAssignment::TYPE_DELIVERY
                            || $assignment->status !== 'ASSIGNED' || ! $assignment->assigned_at
                            || $assignment->assigned_at->gt($cutoff)
                            || (int) $assignment->shipment_id !== (int) $shipment->id) {
                            return false;
                        }

                        $assignment->update([
                            'status' => 'CANCELLED',
                            'cancelled_at' => now(),
                            'rejection_reason' => 'Offer expired before rider acceptance.',
                        ]);

                        if ($shipment?->current_status === 'ASSIGNED_TO_RIDER') {
                            $shipment->update(['current_status' => 'SORTED']);
                            $workflow->recordEvent(
                                $shipment,
                                'SORTED',
                                null,
                                'Delivery offer expired; parcel returned to the unassigned dispatch queue.',
                                $assignment,
                            );
                        }

                        return true;
                    });

                    $expired += $wasExpired ? 1 : 0;
                }
            });

        $this->info("Expired {$expired} rider offer(s).");

        return self::SUCCESS;
    }
}
