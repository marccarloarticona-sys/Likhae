<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use Illuminate\Console\Command;

class ExpireRiderAssignmentOffers extends Command
{
    protected $signature = 'likhae:expire-rider-assignment-offers';

    protected $description = 'Expire pickup offers that riders have not accepted in time';

    public function handle(RiderAssignmentOfferExpiryService $offers): int
    {
        $expired = $offers->expireDuePickupOffers();

        $this->info("Expired {$expired} rider offer(s).");

        return self::SUCCESS;
    }
}
