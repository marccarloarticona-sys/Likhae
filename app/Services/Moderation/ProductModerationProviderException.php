<?php

namespace App\Services\Moderation;

use RuntimeException;

class ProductModerationProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable,
    ) {
        parent::__construct($failureCode);
    }
}
