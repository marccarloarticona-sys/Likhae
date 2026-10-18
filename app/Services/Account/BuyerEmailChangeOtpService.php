<?php

namespace App\Services\Account;

use App\Mail\BuyerEmailChangeVerificationCode;
use App\Mail\BuyerMobileChangeVerificationCode;
use App\Models\User;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Shared, purpose-bound OTP flow for Buyer account contact changes.
 * Mobile changes are authorized through the existing verified-email channel.
 */
class BuyerEmailChangeOtpService
{
    private const EMAIL_PURPOSE = 'email_change';

    private const MOBILE_PURPOSE = 'mobile_change';

    private const CODE_TTL_MINUTES = 5;

    private const RESEND_WAIT_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function pendingEmail(User $user): ?string
    {
        return $this->pendingValue($user, self::EMAIL_PURPOSE);
    }

    public function send(User $user, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $this->sendChallenge(
            $user,
            self::EMAIL_PURPOSE,
            $email,
            $email,
            new BuyerEmailChangeVerificationCode($this->newCode()),
            'email',
        );
    }

    /** The current verified email, not the pending mobile, receives this code. */
    public function sendMobile(User $user, string $phone): void
    {
        $email = $this->verifiedEmail($user);
        $this->sendChallenge(
            $user,
            self::MOBILE_PURPOSE,
            $phone,
            $email,
            new BuyerMobileChangeVerificationCode($this->newCode()),
            'contact_number',
        );
    }

    public function pendingMobile(User $user): ?string
    {
        if (! $user->email_verified_at || ! filled($user->email)) {
            return null;
        }

        return $this->pendingValue($user, self::MOBILE_PURPOSE);
    }

    /** Verify and consume an email code only after the account update succeeds. */
    public function confirm(User $user, string $code, Closure $applyChange): void
    {
        $this->confirmPurpose($user, self::EMAIL_PURPOSE, $code, $applyChange);
    }

    /** Verify and consume a mobile code only after the account update succeeds. */
    public function confirmMobile(User $user, string $code, Closure $applyChange): void
    {
        $this->verifiedEmail($user);
        $this->confirmPurpose($user, self::MOBILE_PURPOSE, $code, $applyChange);
    }

    public function invalidate(User $user): void
    {
        $this->invalidatePurpose($user, self::EMAIL_PURPOSE);
    }

    public function invalidateMobile(User $user): void
    {
        $this->invalidatePurpose($user, self::MOBILE_PURPOSE);
    }

    private function sendChallenge(
        User $user,
        string $purpose,
        string $pendingValue,
        string $recipientEmail,
        Mailable $mail,
        string $validationField,
    ): void {
        $cooldownKey = $this->cooldownKey($user, $purpose);

        if (! Cache::add($cooldownKey, true, now()->addSeconds(self::RESEND_WAIT_SECONDS))) {
            throw ValidationException::withMessages([
                $validationField => 'Please wait a minute before requesting another verification code.',
            ]);
        }

        try {
            Mail::to($recipientEmail)->send($mail);
        } catch (TransportExceptionInterface $exception) {
            Cache::forget($cooldownKey);
            report($exception);

            throw ValidationException::withMessages([
                $validationField => 'The verification email could not be sent. Please try again later.',
            ]);
        }

        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);
        Cache::put($this->challengeKey($user, $purpose), [
            'user_id' => (int) $user->id,
            'purpose' => $purpose,
            'pending_value' => $pendingValue,
            'recipient_email' => mb_strtolower(trim($recipientEmail)),
            'hash' => Hash::make($mail->code),
            'attempts' => 0,
            'expires_at' => $expiresAt->timestamp,
        ], $expiresAt);
    }

    private function confirmPurpose(User $user, string $purpose, string $code, Closure $applyChange): void
    {
        Cache::lock($this->lockKey($user, $purpose), 10)->block(5, function () use ($user, $purpose, $code, $applyChange): void {
            $key = $this->challengeKey($user, $purpose);
            $challenge = Cache::get($key);

            if (! $this->isCurrentChallenge($challenge, $user, $purpose)) {
                Cache::forget($key);
                throw ValidationException::withMessages([
                    'code' => 'This verification code has expired. Request a new code.',
                ]);
            }

            if ((int) $challenge['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                throw ValidationException::withMessages([
                    'code' => 'Too many incorrect codes. Request a new verification code.',
                ]);
            }

            if (! Hash::check($code, (string) $challenge['hash'])) {
                $challenge['attempts'] = (int) $challenge['attempts'] + 1;
                if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
                    Cache::forget($key);
                    throw ValidationException::withMessages([
                        'code' => 'Too many incorrect codes. Request a new verification code.',
                    ]);
                }

                Cache::put($key, $challenge, now()->setTimestamp((int) $challenge['expires_at']));

                throw ValidationException::withMessages([
                    'code' => 'That verification code is incorrect.',
                ]);
            }

            $applyChange((string) $challenge['pending_value']);
            Cache::forget($key);
        });
    }

    private function pendingValue(User $user, string $purpose): ?string
    {
        $key = $this->challengeKey($user, $purpose);
        $challenge = Cache::get($key);

        if (! $this->isCurrentChallenge($challenge, $user, $purpose)) {
            Cache::forget($key);

            return null;
        }

        return (string) $challenge['pending_value'];
    }

    private function isCurrentChallenge(mixed $challenge, User $user, string $purpose): bool
    {
        if (! is_array($challenge)
            || (int) ($challenge['user_id'] ?? 0) !== (int) $user->id
            || ($challenge['purpose'] ?? null) !== $purpose
            || ! is_string($challenge['pending_value'] ?? null)
            || ! is_string($challenge['hash'] ?? null)
            || ! isset($challenge['expires_at'])
            || (int) $challenge['expires_at'] <= now()->timestamp) {
            return false;
        }

        if ($purpose === self::MOBILE_PURPOSE) {
            $recipient = $challenge['recipient_email'] ?? null;

            return $user->email_verified_at !== null
                && is_string($recipient)
                && mb_strtolower(trim((string) $user->email)) === $recipient;
        }

        return true;
    }

    private function verifiedEmail(User $user): string
    {
        if (! $user->email_verified_at || ! filled($user->email)) {
            throw ValidationException::withMessages([
                'contact_number' => 'Verify your account email before changing your mobile number.',
            ]);
        }

        return mb_strtolower(trim((string) $user->email));
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function invalidatePurpose(User $user, string $purpose): void
    {
        Cache::forget($this->challengeKey($user, $purpose));
    }

    private function challengeKey(User $user, string $purpose): string
    {
        return 'buyer_contact_change:'.$purpose.':'.(int) $user->id;
    }

    private function cooldownKey(User $user, string $purpose): string
    {
        return $this->challengeKey($user, $purpose).':resend';
    }

    private function lockKey(User $user, string $purpose): string
    {
        return $this->challengeKey($user, $purpose).':lock';
    }
}
