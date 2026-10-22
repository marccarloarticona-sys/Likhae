<?php

namespace App\Services\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class PayoutDestinationReadiness
{
    private const COLUMNS = ['payout_method', 'payout_account_type', 'payout_bank_name', 'payout_account_name', 'payout_account_number'];

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

    public function hasDestination(Model $profile): bool
    {
        return filled($profile->payout_method)
            && filled($profile->payout_account_name)
            && filled($profile->payout_account_number)
            && ($profile->payout_method !== 'Bank Transfer' || filled($profile->payout_bank_name));
    }
}
