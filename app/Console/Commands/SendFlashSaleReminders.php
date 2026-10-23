<?php

namespace App\Console\Commands;

use App\Services\Marketplace\FlashSaleReminderService;
use Illuminate\Console\Command;

class SendFlashSaleReminders extends Command
{
    protected $signature = 'likhae:send-flash-sale-reminders';

    protected $description = 'Send due Buyer flash sale reminders';

    public function handle(FlashSaleReminderService $reminders): int
    {
        $count = $reminders->sendDue();
        $this->info('Sent '.$count.' flash sale reminder(s).');

        return self::SUCCESS;
    }
}
