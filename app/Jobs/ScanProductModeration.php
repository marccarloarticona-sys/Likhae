<?php

namespace App\Jobs;

use App\Models\Admin\ProductModerationScan;
use App\Services\Moderation\ProductModerationProviderException;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScanProductModeration implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $scanId, public readonly bool $quotaDeferredRetry = false) {}

    public function uniqueId(): string
    {
        return 'product-moderation-scan-'.$this->scanId.($this->quotaDeferredRetry ? '-quota-retry' : '');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $productId = ProductModerationScan::query()->whereKey($this->scanId)->value('product_id') ?? 0;

        return [(new WithoutOverlapping('product-moderation-product-'.$productId))->expireAfter(600)->releaseAfter(30)];
    }

    public function handle(ProductModerationService $moderation): void
    {
        $attempt = $this->attempts();
        if ($this->quotaDeferredRetry) {
            $attempt += max(1, (int) config('product_moderation.queue.max_attempts', 3));
        }
        $moderation->processScan($this->scanId, $attempt);
    }

    public function failed(?Throwable $exception): void
    {
        $scan = ProductModerationScan::query()->find($this->scanId);
        if ($scan && ! in_array($scan->scan_status, ['COMPLETE', 'SUPERSEDED'], true)) {
            app(ProductModerationService::class)->markFailure(
                $scan,
                $exception instanceof ProductModerationProviderException ? $exception->failureCode : 'AI_JOB_FAILED',
                false,
                $this->attempts(),
            );
        }
    }
}
