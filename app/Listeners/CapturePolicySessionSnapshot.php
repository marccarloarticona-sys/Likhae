<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Illuminate\Auth\Events\Login;

class CapturePolicySessionSnapshot
{
    public function __construct(private readonly PolicyAcceptanceService $policies)
    {
    }

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! request()->hasSession() || ! $this->policies->schemaReady()) {
            return;
        }

        request()->session()->put('policy_version_snapshot', $this->policies->fingerprintFor($event->user));
    }
}
