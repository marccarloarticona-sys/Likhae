<?php

namespace App\Console\Commands;

use App\Models\Buyer\Order;
use App\Services\Marketplace\BuyerOrderActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoConfirmDeliveredOrders extends Command
{
    protected $signature = 'likhae:auto-confirm-delivered-orders';

    protected $description = 'Automatically confirm delivered Buyer Orders after 72 hours';

    public function handle(BuyerOrderActionService $actions): int
    {
        $cutoff = now()->subHours(72);
        $returnRefundStorageReady = $actions->returnRefundStorageReady();
        $completedCount = 0;

        Order::query()
            ->where('status', 'PROCESSING')
            ->whereHas('sellerOrders')
            ->whereHas('sellerOrders.shipment.events', function ($query) use ($cutoff): void {
                $query->where('status', 'DELIVERED')
                    ->whereNotNull('occurred_at')
                    ->where('occurred_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(100, function ($candidateOrders) use ($actions, $returnRefundStorageReady, &$completedCount): void {
                foreach ($candidateOrders as $candidateOrder) {
                    $completed = DB::transaction(function () use ($candidateOrder, $actions, $returnRefundStorageReady): bool {
                        $order = Order::query()
                            ->whereKey($candidateOrder->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $order || $order->status !== 'PROCESSING') {
                            return false;
                        }

                        $order->load([
                            'sellerOrders.shipment.events',
                            'disputes',
                            ...($returnRefundStorageReady ? ['returnRefundRequests.sellerRequests'] : []),
                        ]);
                        $state = $actions->state($order, $returnRefundStorageReady);

                        if (! $state['can_auto_confirm_received']) {
                            return false;
                        }

                        return $actions->completeReceipt($order, null, BuyerOrderActionService::AUTOMATIC_RECEIPT_NOTE);
                    });

                    if ($completed) {
                        $completedCount++;
                    }
                }
            });

        $message = 'Automatically completed '.$completedCount.' order(s).';
        $this->info($message);
        Log::info('Automatic delivered-order receipt confirmation finished.', [
            'completed_orders' => $completedCount,
        ]);

        return self::SUCCESS;
    }
}
