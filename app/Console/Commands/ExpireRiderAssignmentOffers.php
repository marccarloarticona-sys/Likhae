<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
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
    ): int
    {
        $restored = $matching->pausePendingWhenDisabled();
        $expired = $offers->expireDuePickupOffers();
        $redispatched = $autoDispatch->retryFailedOffers();
        $expiredCenters = $matching->expireDueOffers();
        $pendingRetried = $matching->retryPending();
        $returnedToSellers = $matching->returnUnansweredRequests();

        $this->info("Expired {$expired} rider offer(s) and reassigned {$redispatched} pickup request(s); expired {$expiredCenters} center offer(s), retried {$pendingRetried} pending automatic pickup request(s), returned {$returnedToSellers} unanswered request(s) to sellers, and restored {$restored} request(s) to the manual queue.");

        return self::SUCCESS;
    }
}
