<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ExpireAccountSuspensions extends Command
{
    protected $signature = 'likhae:expire-account-suspensions';

    protected $description = 'Restore account access when temporary suspensions end';

    public function handle(): int
    {
        $expired = User::expireDueSuspensions();
        $this->info("Restored {$expired} expired account suspension(s).");

        return self::SUCCESS;
    }
}
