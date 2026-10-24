<?php

namespace App\Console\Commands;

use App\Services\ReturnRefundWorkflowService;
use Illuminate\Console\Command;

class AutoApproveExpiredSellerRefunds extends Command
{
    protected $signature = 'likhae:auto-approve-expired-seller-refunds {--limit=250}';

    protected $description = 'Automatically approve eligible refund requests after the Seller response deadline.';

    public function handle(ReturnRefundWorkflowService $workflow): int
    {
        $processed = $workflow->autoApproveExpiredSellerResponses((int) $this->option('limit'));
        $this->info("Automatically approved {$processed} expired Seller response(s).");

        return self::SUCCESS;
    }
}
