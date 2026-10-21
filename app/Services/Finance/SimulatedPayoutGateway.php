<?php

namespace App\Services\Finance;

use Illuminate\Support\Str;

final class SimulatedPayoutGateway
{
    public const NOTICE = 'DEMO ONLY — no money was transferred.';

    private const ONLINE_METHODS = ['GCash', 'Maya', 'Bank Transfer', 'Other'];

    /** @return array{external_reference: string, notice: string}|null */
    public function simulate(string $method): ?array
    {
        if (! $this->isOnlineMethod($method)) {
            return null;
        }

        return [
            'external_reference' => 'SIM-'.Str::upper(Str::slug($method)).'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'notice' => self::NOTICE,
        ];
    }

    public function isOnlineMethod(?string $method): bool
    {
        return in_array(trim((string) $method), self::ONLINE_METHODS, true);
    }

    public function isSimulationReference(?string $reference): bool
    {
        return str_starts_with((string) $reference, 'SIM-');
    }
}
