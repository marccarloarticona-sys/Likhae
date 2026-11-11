<?php

namespace App\Services\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Schema;

final class PayoutDestinationReadiness
{
    // Older Rider profiles still store one encrypted destination in the
    // legacy columns and do not have payout_account_type. The account type is
    // inferable from the payment channel for that fallback path.
    private const COLUMNS = ['payout_method', 'payout_bank_name', 'payout_account_name', 'payout_account_number'];

    public function readyFor(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    public function supportsMultipleFor(string $table): bool
    {
        return $this->readyFor($table)
            && Schema::hasColumn($table, 'payout_destinations')
            && Schema::hasColumn($table, 'payout_account_type');
    }

    public function hasDestination(Model $profile): bool
    {
        return filled($profile->payout_method)
            && filled($profile->payout_account_name)
            && filled($profile->payout_account_number)
            && ($profile->payout_method !== 'Bank Transfer' || filled($profile->payout_bank_name));
    }

    /** Keep finance pages usable if encrypted destinations use another APP_KEY. */
    public function prepareForDisplay(?Model $profile): void
    {
        if (! $profile) {
            return;
        }

        try {
            $profile->payout_account_name;
            $profile->payout_account_number;
            if (Schema::hasColumn($profile->getTable(), 'payout_destinations')) {
                $profile->payout_destinations;
            }
        } catch (DecryptException) {
            $profile->setAttribute('payout_account_name', null);
            $profile->setAttribute('payout_account_number', null);
            if (Schema::hasColumn($profile->getTable(), 'payout_destinations')) {
                $profile->setAttribute('payout_destinations', null);
            }
            $profile->setAttribute('payout_destination_unreadable', true);
        }
    }
}
