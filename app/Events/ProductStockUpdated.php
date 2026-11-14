<?php

namespace App\Events;

use App\Services\Marketplace\ProductStockPayloadService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductStockUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $productId)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('products.'.$this->productId)];
    }

    public function broadcastAs(): string
    {
        return 'product.stock.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return app(ProductStockPayloadService::class)->forProduct($this->productId);
    }
}
