<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Mail\RegistrationEmailVerificationCode;
use App\Models\Admin\PlatformSetting;
use App\Models\User;
use App\Services\RegistrationWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class MobileAuthController extends Controller
{
    private const EMAIL_CODE_TTL_MINUTES = 5;

    private const GOOGLE_EMAIL_TOKEN_TTL_MINUTES = 30;

    public function register(
        StoreRegistrationRequest $request,
        RegistrationWorkflowService $workflow,
    ): JsonResponse {
        if (! (bool) PlatformSetting::valueOf('registration_enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'New registrations are temporarily disabled.',
            ], 403);
        }

        $email = mb_strtolower(trim((string) $request->input('email')));
        if (! $this->hasValidEmailVerificationToken(
            $email,
            (string) $request->input('email_verification_token'),
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Verify your email address before registering.',
                'errors' => ['email_verification_token' => ['A valid email verification token is required.']],
            ], 422);
        }

        if (! PhilippineAddressController::selectionIsValid(
            (string) $request->input('region_code'),
            (string) $request->input('region'),
            (string) $request->input('province_code'),
            (string) $request->input('province'),
            (string) $request->input('municipality_code'),
            (string) $request->input('municipality'),
            (string) $request->input('barangay_code'),
            (string) $request->input('barangay'),
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a valid Philippine address.',
                'errors' => ['address' => ['The selected address is invalid.']],
            ], 422);
        }

        $expectedPostalCode = PhilippineAddressController::expectedPostalCodeFor(
            (string) $request->input('province_code'),
            (string) $request->input('municipality_code'),
            (string) $request->input('province'),
            (string) $request->input('municipality'),
        );
        $registrationOverrides = [];
        if ($expectedPostalCode !== null) {
            // The client may display this value, but the authoritative postal
            // code is always derived from the selected municipality.
            $registrationOverrides['postal_code'] = $expectedPostalCode;
        }

        $application = $workflow->submit($request, $registrationOverrides);
        Cache::forget($this->emailVerificationTokenCacheKey($email));

        return response()->json([
            'success' => true,
            'message' => 'Registration submitted. Wait for account approval before signing in.',
            'data' => [
                'application_number' => $application->application_number,
                'status' => $application->status,
                'email' => $application->user?->email,
            ],
        ], 201);
    }

    public function sendRegistrationEmailVerificationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An account with this email already exists.',
                'errors' => ['email' => ['An account with this email already exists.']],
            ], 422);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Mail::to($email)->send(new RegistrationEmailVerificationCode($code));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The verification email could not be sent. Check the SMTP settings and try again.',
            ], 503);
        }

        Cache::put($this->emailCodeCacheKey($email), [
            'hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES)->timestamp,
        ], now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES));
        Cache::forget($this->emailVerificationTokenCacheKey($email));

        return response()->json([
            'success' => true,
            'message' => 'A verification code was sent. It expires in 5 minutes.',
        ]);
    }

    public function verifyRegistrationEmailVerificationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $cacheKey = $this->emailCodeCacheKey($email);
        $challenge = Cache::get($cacheKey);

        if (! is_array($challenge) || ! isset($challenge['hash'], $challenge['expires_at'])
            || (int) $challenge['expires_at'] <= now()->timestamp) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'This code has expired. Request a new verification code.',
                'errors' => ['code' => ['This code has expired. Request a new verification code.']],
            ], 422);
        }

        if (! Hash::check($data['code'], $challenge['hash'])) {
            return response()->json([
                'success' => false,
                'message' => 'That verification code is incorrect.',
                'errors' => ['code' => ['That verification code is incorrect.']],
            ], 422);
        }

        $token = Str::random(64);
        Cache::put($this->emailVerificationTokenCacheKey($email), [
            'hash' => Hash::make($token),
            'expires_at' => now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES)->timestamp,
        ], now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES));
        Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
            'message' => 'Email verified. Submit this token with registration.',
            'email_verification_token' => $token,
            'expires_at' => now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES)->toIso8601String(),
        ]);
    }

    public function exchangeGoogle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:64'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $exchange = Cache::pull('mobile_google_exchange:'.hash('sha256', $data['code']));
        if (! is_array($exchange) || ! isset($exchange['code_challenge'], $exchange['state'])) {
            return response()->json([
                'success' => false,
                'message' => 'This Google sign-in code is invalid or has expired.',
            ], 401);
        }

        $challenge = rtrim(
            strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'),
            '=',
        );
        if (! hash_equals((string) $exchange['code_challenge'], $challenge)) {
            return response()->json([
                'success' => false,
                'message' => 'This Google sign-in code is invalid or has expired.',
            ], 401);
        }

        if (is_array($exchange['profile'] ?? null)) {
            if (! (bool) PlatformSetting::valueOf('registration_enabled', true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'New registrations are temporarily disabled.',
                ], 403);
            }

            $email = mb_strtolower(trim((string) ($exchange['profile']['email'] ?? '')));
            if ($email === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'The Google account could not be matched to a LIKHAE account.',
                ], 401);
            }

            $verificationToken = Str::random(64);
            $verificationExpiresAt = now()->addMinutes(self::GOOGLE_EMAIL_TOKEN_TTL_MINUTES);
            Cache::put($this->emailVerificationTokenCacheKey($email), [
                'hash' => Hash::make($verificationToken),
                'expires_at' => $verificationExpiresAt->timestamp,
            ], $verificationExpiresAt);

            return response()->json([
                'success' => false,
                'code' => 'google_registration_required',
                'message' => 'Complete buyer registration to continue.',
                'profile' => [
                    'email' => $email,
                    'first_name' => (string) ($exchange['profile']['first_name'] ?? ''),
                    'last_name' => (string) ($exchange['profile']['last_name'] ?? ''),
                    'email_verification_token' => $verificationToken,
                ],
            ], 409);
        }

        $user = User::query()->find($exchange['user_id'] ?? null);
        if (! $user || ! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => $user?->inactiveMessage() ?? 'The Google account could not be matched to a LIKHAE account.',
            ], 403);
        }

        $mobileRoles = $this->mobileRoles($user);
        if ($mobileRoles === []) {
            return response()->json([
                'success' => false,
                'message' => 'This account does not have Buyer or Rider mobile access.',
            ], 403);
        }

        $plainTextToken = Str::random(64);
        $expiresAt = now()->addDays(30);
        $user->forceFill([
            'api_token_hash' => hash('sha256', $plainTextToken),
            'api_token_expires_at' => $expiresAt,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Signed in successfully.',
            'token_type' => 'Bearer',
            'token' => $plainTextToken,
            'access_token' => $plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'device_name' => trim((string) ($data['device_name'] ?? '')) ?: 'likhae-mobile',
            'user' => $this->userPayload($user, $mobileRoles),
        ]);
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        try {
            Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Password reset email is temporarily unavailable. Please try again later.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account exists with this email, a password reset link will be sent.',
        ]);
    }
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));
        $rateLimitKey = 'mobile-login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($rateLimitKey).' seconds.',
                'errors' => ['email' => ['Too many sign-in attempts.']],
            ], 429);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
                'errors' => ['email' => ['Invalid email or password.']],
            ], 401);
        }

        if (! $user->isActive() || ! $user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => $user->inactiveMessage(),
                'errors' => ['email' => [$user->inactiveMessage()]],
            ], 403);
        }

        $mobileRoles = $this->mobileRoles($user);
        if ($mobileRoles === []) {
            return response()->json([
                'success' => false,
                'message' => 'This account does not have Buyer or Rider mobile access.',
                'errors' => ['email' => ['This account does not have Buyer or Rider mobile access.']],
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);

        $plainTextToken = Str::random(64);
        $expiresAt = now()->addDays(30);
        $user->forceFill([
            'api_token_hash' => hash('sha256', $plainTextToken),
            'api_token_expires_at' => $expiresAt,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Signed in successfully.',
            'token_type' => 'Bearer',
            'token' => $plainTextToken,
            'access_token' => $plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'device_name' => trim((string) ($credentials['device_name'] ?? '')) ?: 'likhae-mobile',
            'user' => $this->userPayload($user, $mobileRoles),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $mobileRoles = $this->mobileRoles($user);

        if (! $user->isActive()) {
            return response()->json(['success' => false, 'message' => $user->inactiveMessage()], 403);
        }

        if ($mobileRoles === []) {
            return response()->json([
                'success' => false,
                'message' => 'This account no longer has Buyer or Rider mobile access.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'user' => $this->userPayload($user, $mobileRoles),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'api_token_hash' => null,
            'api_token_expires_at' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Signed out successfully.',
        ]);
    }

    /** @return array<int, string> */
    private function mobileRoles(User $user): array
    {
        return match (strtoupper((string) $user->account_type)) {
            User::TYPE_BUYER => ['buyer'],
            User::TYPE_RIDER => ['rider'],
            default => [],
        };
    }

    /** @param array<int, string> $mobileRoles */
    private function userPayload(User $user, array $mobileRoles): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'middle_initial' => $user->middle_initial,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'account_type' => $user->account_type,
            'status' => $user->status,
            'email_verified' => $user->email_verified_at !== null,
            'roles' => $mobileRoles,
            'mobile_roles' => $mobileRoles,
        ];
    }

    private function hasValidEmailVerificationToken(string $email, string $token): bool
    {
        $challenge = Cache::get($this->emailVerificationTokenCacheKey($email));

        return is_array($challenge)
            && isset($challenge['hash'], $challenge['expires_at'])
            && (int) $challenge['expires_at'] > now()->timestamp
            && Hash::check($token, $challenge['hash']);
    }

    private function emailCodeCacheKey(string $email): string
    {
        return 'mobile_registration_email_code:'.hash('sha256', $email);
    }

    private function emailVerificationTokenCacheKey(string $email): string
    {
        return 'mobile_registration_email_token:'.hash('sha256', $email);
    }
}
