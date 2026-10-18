<?php

namespace App\Services\Moderation;

class FallbackProductModerationProvider implements ProductModerationProvider
{
    public function __construct(
        private readonly ProductModerationProvider $primary,
        private readonly ProductModerationProvider $fallback,
    ) {}

    public function configured(): bool
    {
        return $this->primary->configured()
            || ((bool) config('product_moderation.fallback.enabled') && $this->fallback->configured());
    }

    public function providerLabel(): string
    {
        return $this->primary->providerLabel().' ('.$this->fallback->providerLabel().' backup)';
    }

    public function primaryModel(): string
    {
        return $this->primary->primaryModel();
    }

    public function objectModel(): ?string
    {
        $model = $this->primary->objectModel();

        return $model === null
            ? $this->fallback->objectModel()
            : $model.' ('.$this->fallback->providerLabel().' backup)';
    }

    public function classifyText(array $productState): array
    {
        return $this->withFallback(
            fn (): array => $this->primary->classifyText($productState),
            fn (): array => $this->fallback->classifyText($productState),
        );
    }

    public function classifyImages(array $productState, array $images): array
    {
        return $this->withFallback(
            fn (): array => $this->primary->classifyImages($productState, $images),
            fn (): array => $this->fallback->classifyImages($productState, $images),
        );
    }

    public function detectObjects(array $image): array
    {
        try {
            $detections = $this->primary->detectObjects($image);
            $provider = $this->primary;
            $usedFallback = false;
        } catch (ProductModerationProviderException $exception) {
            if (! (bool) config('product_moderation.fallback.enabled') || ! $this->fallback->configured()) {
                throw $exception;
            }
            $detections = $this->fallback->detectObjects($image);
            $provider = $this->fallback;
            $usedFallback = true;
        }

        return array_map(fn (array $detection): array => $detection + [
            'provider_source' => $provider->providerLabel(),
            'model_source' => $provider->primaryModel(),
            'used_fallback' => $usedFallback,
        ], $detections);
    }

    /** @param callable(): array<string, mixed> $primaryCall @param callable(): array<string, mixed> $fallbackCall @return array<string, mixed> */
    private function withFallback(callable $primaryCall, callable $fallbackCall): array
    {
        try {
            $result = $primaryCall();
            $provider = $this->primary;
            $usedFallback = false;
        } catch (ProductModerationProviderException $exception) {
            if (! (bool) config('product_moderation.fallback.enabled') || ! $this->fallback->configured()) {
                throw $exception;
            }
            $result = $fallbackCall();
            $provider = $this->fallback;
            $usedFallback = true;
        }

        return $result + [
            'provider_source' => $provider->providerLabel(),
            'model_source' => $provider->primaryModel(),
            'used_fallback' => $usedFallback,
        ];
    }
}
