<?php

namespace App\Services\Moderation;

interface ProductModerationProvider
{
    /** @param array<string, mixed> $productState @return array<string, mixed> */
    public function classifyText(array $productState): array;

    /** @param array<string, mixed> $productState @param list<array<string, mixed>> $images @return array<string, mixed> */
    public function classifyImages(array $productState, array $images): array;

    /** @param array<string, mixed> $image @return list<array<string, mixed>> */
    public function detectObjects(array $image): array;

    public function configured(): bool;

    public function providerLabel(): string;

    public function primaryModel(): string;

    public function objectModel(): ?string;
}
