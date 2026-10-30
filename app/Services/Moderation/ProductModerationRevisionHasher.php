<?php

namespace App\Services\Moderation;

use App\Models\Seller\Product;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductModerationRevisionHasher
{
    private ?object $memoizedRequest = null;

    /** @var array<string, string> */
    private array $imageHashMemo = [];

    public function hash(Product $product): string
    {
        $product->loadMissing([
            'category.parent',
            'images',
            'options.values',
            'variants.optionValues.option',
        ]);

        $images = $product->images
            ->sortBy('id')
            ->map(fn ($image): array => [
                'hash' => $this->imageHash((string) $image->file_path),
                'option_value_id' => $image->product_option_value_id,
            ])
            ->values()
            ->all();

        $options = $product->options
            ->sortBy('id')
            ->map(fn ($option): array => [
                'name' => $option->name,
                'values' => $option->values->sortBy('id')->pluck('value')->values()->all(),
            ])
            ->values()
            ->all();

        $variants = $product->variants
            ->sortBy('id')
            ->map(fn ($variant): array => [
                'name' => $variant->description,
                'active' => (bool) $variant->is_active,
                'image_id' => $variant->product_image_id,
            ])
            ->values()
            ->all();

        $state = [
            'title' => (string) $product->name,
            'description' => (string) ($product->description ?? ''),
            'category' => $product->category?->parent?->name,
            'subcategory' => $product->category?->name,
            'options' => $options,
            'variants' => $variants,
            'images' => $images,
            'policy_version' => config('product_moderation.policy_version'),
            'models' => config('product_moderation.models'),
        ];

        return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
    }

    private function imageHash(string $path): string
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return 'invalid';
        }

        // Product image paths are immutable in the seller upload flow. Reuse
        // their hashes within one web request so pre-save, post-save, and queue
        // checks do not reread the same files. Queue workers must hash afresh.
        if (app()->runningInConsole()) {
            $this->imageHashMemo = [];
        } else {
            $request = app('request');
            if ($this->memoizedRequest !== $request) {
                $this->memoizedRequest = $request;
                $this->imageHashMemo = [];
            }
        }
        if (isset($this->imageHashMemo[$path])) {
            return $this->imageHashMemo[$path];
        }

        try {
            $stream = Storage::disk('public')->readStream($path);
            if (! is_resource($stream)) {
                return $this->imageHashMemo[$path] = 'missing';
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);

            return $this->imageHashMemo[$path] = hash_final($hash);
        } catch (Throwable) {
            return $this->imageHashMemo[$path] = 'unavailable';
        }
    }
}
