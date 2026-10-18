<?php

namespace App\Services\Account;

use App\Models\User;
use Closure;

/** Compatibility adapter; email and mobile changes share the same OTP engine. */
class BuyerMobileChangeOtpService
{
    public function __construct(private readonly BuyerEmailChangeOtpService $contactChanges) {}

    public function pendingPhone(User $user): ?string
    {
        return $this->contactChanges->pendingMobile($user);
    }

    public function send(User $user, string $phone): void
    {
        $this->contactChanges->sendMobile($user, $phone);
    }

    public function confirm(User $user, string $code, Closure $applyChange): void
    {
        $this->contactChanges->confirmMobile($user, $code, $applyChange);
    }

    public function invalidate(User $user): void
    {
        $this->contactChanges->invalidateMobile($user);
    }
}
