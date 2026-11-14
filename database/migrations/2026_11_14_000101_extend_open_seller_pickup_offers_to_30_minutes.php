<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->adjustUnansweredOffers(15, 30);
    }

    public function down(): void
    {
        $this->adjustUnansweredOffers(30, 15);
    }

    private function adjustUnansweredOffers(int $fromMinutes, int $toMinutes): void
    {
        if (! Schema::hasTable('seller_pickup_center_offers')
            || ! Schema::hasColumns('seller_pickup_center_offers', ['id', 'status', 'offered_at', 'expires_at', 'responded_at'])) {
            return;
        }

        DB::table('seller_pickup_center_offers')
            ->where('status', 'OFFERED')
            ->whereNull('responded_at')
            ->orderBy('id')
            ->chunkById(200, function ($offers) use ($fromMinutes, $toMinutes): void {
                foreach ($offers as $offer) {
                    if (! $offer->offered_at || ! $offer->expires_at) {
                        continue;
                    }

                    $offeredAt = Carbon::parse($offer->offered_at);
                    $oldExpiry = $offeredAt->copy()->addMinutes($fromMinutes);
                    if (! Carbon::parse($offer->expires_at)->equalTo($oldExpiry)) {
                        continue;
                    }

                    DB::table('seller_pickup_center_offers')
                        ->where('id', $offer->id)
                        ->where('status', 'OFFERED')
                        ->whereNull('responded_at')
                        ->where('expires_at', $offer->expires_at)
                        ->update(['expires_at' => $offeredAt->copy()->addMinutes($toMinutes)]);
                }
            });
    }
};
