<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use App\Services\Fulfillment\SellerPickupAutoDispatchService;
use Illuminate\Console\Command;

class ExpireRiderAssignmentOffers extends Command
{
    protected $signature = 'likhae:expire-rider-assignment-offers';

    protected $description = 'Expire pickup offers that riders have not accepted in time';

    public function handle(
        RiderAssignmentOfferExpiryService $offers,
        SellerPickupAutoDispatchService $autoDispatch,
    ): int
    {
        $expired = $offers->expireDuePickupOffers();
        $redispatched = $autoDispatch->retryFailedOffers();

        $this->info("Expired {$expired} rider offer(s) and reassigned {$redispatched} pickup request(s).");

        return self::SUCCESS;
    }
}
