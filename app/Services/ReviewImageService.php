<?php

namespace App\Services;

use App\Models\Admin\AuditLog;
use App\Models\Buyer\Review;
use App\Models\User;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ReviewImageService
{
    private const EVENT = 'buyer.review.image.updated';

    public function __construct(private readonly ImageOptimizationService $images) {}

    public function urlsFor(Collection|EloquentCollection $reviews): array
    {
        return collect($this->photoUrlsFor($reviews))
            ->map(fn (array $urls): ?string => $urls[0] ?? null)
            ->filter()
            ->all();
    }

    /** @return array<int, array<int, string>> */
    public function photoUrlsFor(Collection|EloquentCollection $reviews): array
    {
        $reviewIds = $reviews->filter()->pluck('id')->all();
        if ($reviewIds === []) {
            return [];
        }

        $entries = AuditLog::query()
            ->where('event', self::EVENT)
            ->where('auditable_type', Review::class)
            ->whereIn('auditable_id', $reviewIds)
            ->orderBy('id')
            ->get(['auditable_id', 'new_values'])
            ->groupBy('auditable_id');

        return $entries->map(function ($reviewEntries, $reviewId): array {
            $prefix = 'reviews/'.(int) $reviewId.'/';

            return $reviewEntries
                ->map(function (AuditLog $entry) use ($prefix): ?string {
                    $path = data_get($entry->new_values, 'image_path');

                    if (! is_string($path)
                        || ! str_starts_with($path, $prefix)
                        || ! Storage::disk('public')->exists($path)) {
                        return null;
                    }

                    return $path;
                })
                ->filter()
                ->unique()
                ->map(fn (string $path): string => Storage::disk('public')->url($path))
                ->values()
                ->all();
        })
            ->filter(fn (array $urls): bool => $urls !== [])
            ->all();
    }

    public function store(Review $review, UploadedFile $image, User $actor, Request $request): void
    {
        $this->storeMany($review, [$image], $actor, $request);
    }

    /** @param array<int, UploadedFile> $images */
    public function storeMany(Review $review, array $images, User $actor, Request $request): array
    {
        abort_unless((int) $review->buyer_user_id === (int) $actor->id, 403);

        $storedPaths = [];

        try {
            DB::transaction(function () use ($review, $images, $actor, $request, &$storedPaths): void {
                foreach (array_values($images) as $sortOrder => $image) {
                    $path = $this->images->store($image, 'reviews/'.$review->id);
                    if (! is_string($path)) {
                        throw new RuntimeException('The review image could not be saved.');
                    }

                    $storedPaths[] = $path;
                    $duplicate = AuditLog::query()
                        ->where('event', self::EVENT)
                        ->where('auditable_type', Review::class)
                        ->where('auditable_id', $review->id)
                        ->get(['new_values'])
                        ->contains(fn (AuditLog $entry): bool => data_get($entry->new_values, 'image_path') === $path);

                    if ($duplicate) {
                        throw new RuntimeException('A duplicate review image path was generated.');
                    }

                    AuditLog::query()->create([
                        'actor_user_id' => $actor->id,
                        'event' => self::EVENT,
                        'auditable_type' => Review::class,
                        'auditable_id' => $review->id,
                        'new_values' => ['image_path' => $path, 'sort_order' => $sortOrder],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'created_at' => now(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }

        return $storedPaths;
    }

}
