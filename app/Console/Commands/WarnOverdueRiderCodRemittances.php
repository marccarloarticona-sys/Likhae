<?php

namespace App\Console\Commands;

use App\Services\Finance\OverdueRiderCodRemittanceWarningService;
use Illuminate\Console\Command;

class WarnOverdueRiderCodRemittances extends Command
{
    protected $signature = 'likhae:warn-overdue-rider-cod-remittances';

    protected $description = 'Warn riders whose collected COD has not been remitted within 24 hours';

    public function handle(OverdueRiderCodRemittanceWarningService $warnings): int
    {
        $count = $warnings->issueDueWarnings();
        $this->info("Issued {$count} overdue COD remittance warning(s).");

        return self::SUCCESS;
    }
}
