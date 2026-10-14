<?php

namespace App\Support;

final class InventoryStockStatus
{
    public const LOW_STOCK_THRESHOLD = 10;

    public static function level(int $stock): string
    {
        return match (true) {
            $stock <= 0 => 'out',
            $stock <= self::LOW_STOCK_THRESHOLD => 'low',
            default => 'healthy',
        };
    }

    public static function label(int $stock): string
    {
        return match (self::level($stock)) {
            'out' => 'Out of Stock',
            'low' => 'Low Stock',
            default => 'Healthy',
        };
    }

    public static function isLow(int $stock): bool
    {
        return self::level($stock) === 'low';
    }

    public static function isOut(int $stock): bool
    {
        return self::level($stock) === 'out';
    }
}
