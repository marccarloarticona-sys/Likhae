<?php

namespace App\Console\Commands;

use App\Models\Communication\Message;
use App\Models\Seller\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class PurgeExpiredSoftDeletes extends Command
{
    protected $signature = 'likhae:purge-expired-soft-deletes';

    protected $description = 'Permanently remove soft-deleted products and messages after the 30-day recovery period';

    public function handle(): int
    {
        $cutoff = now()->subDays(30);
        $productIds = Product::onlyTrashed()->where('deleted_at', '<=', $cutoff)->pluck('id');
        $purgedProducts = 0;
        foreach ($productIds as $productId) {
            $deletedPaths = DB::transaction(function () use ($productId, $cutoff): ?array {
                $product = Product::onlyTrashed()->whereKey($productId)->where('deleted_at', '<=', $cutoff)
                    ->lockForUpdate()->with('images')->first();
                if (! $product
                    || $product->orderItems()->exists()
                    || $product->variants()->whereHas('cartItems')->exists()) {
                    return null;
                }

                $paths = $product->images->pluck('file_path')->filter()->values()->all();
                preg_match_all(
                    '/\[\[description-image:stored:((?:[A-Za-z0-9]{40}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.(?:jpg|jpeg|png|webp))\]\]/i',
                    (string) $product->description,
                    $matches,
                );
                foreach (array_unique($matches[1] ?? []) as $filename) {
                    $paths[] = "sellers/{$product->seller_profile_id}/products/{$product->id}/descriptions/{$filename}";
                }
                $product->forceDelete();

                return $paths;
            });

            if ($deletedPaths === null) {
                continue;
            }
            $purgedProducts++;
            foreach ($deletedPaths as $path) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        $messages = Message::onlyTrashed()->where('deleted_at', '<=', $cutoff)->delete();
        $this->info('Permanently removed '.$purgedProducts.' expired products and '.$messages.' expired messages.');

        return self::SUCCESS;
    }
}
