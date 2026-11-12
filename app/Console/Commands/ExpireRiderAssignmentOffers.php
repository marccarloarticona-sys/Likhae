<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\SellerPickupAutoDispatchService;
use App\Services\Fulfillment\SellerPickupMatchingService;
use Illuminate\Console\Command;

class ExpireRiderAssignmentOffers extends Command
{
    protected $signature = 'likhae:expire-rider-assignment-offers';

    protected $description = 'Expire rider and Logistics center pickup offers that were not accepted in time';

    public function handle(
        RiderAssignmentOfferExpiryService $offers,
        SellerPickupAutoDispatchService $autoDispatch,
        SellerPickupMatchingService $matching,
        SellerPickupBatchService $pickupBatches,
        LogisticsSchemaReadiness $schema,
    ): int
    {
        $restored = $matching->pausePendingWhenDisabled();
        $expired = $offers->expireDuePickupOffers();
        $redispatched = $autoDispatch->retryFailedOffers();
        $expiredCenters = $matching->expireDueOffers();
        $pendingRetried = $matching->retryPending();
        $returnedToSellers = $matching->returnUnansweredRequests();
        $reconciledBatches = $schema->sellerPickupBatchesReady()
            ? $pickupBatches->reconcileFullyCollectedBatches()
            : 0;

        $this->info("Expired {$expired} rider offer(s) and reassigned {$redispatched} pickup request(s); expired {$expiredCenters} center offer(s), retried {$pendingRetried} pending automatic pickup request(s), returned {$returnedToSellers} unanswered request(s) to sellers, restored {$restored} request(s) to the manual queue, and reconciled {$reconciledBatches} fully collected pickup batch(es).");

        return self::SUCCESS;
    }
}
