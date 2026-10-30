<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class MobileGoogleAuthenticationController extends Controller
{
    private const CALLBACK_URL = 'likhae://auth/google-callback';

    public function redirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'size:32', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'code_challenge' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        if (! $this->isConfigured()) {
            return $this->returnToApp($data['state'], ['error' => 'google_unavailable']);
        }

        $request->session()->put('google_mobile_auth', [
            'state' => $data['state'],
            'code_challenge' => $data['code_challenge'],
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request, array $mobileAuth): RedirectResponse
    {
        $state = (string) ($mobileAuth['state'] ?? '');
        if (
            $state === ''
            || ! isset($mobileAuth['code_challenge'], $mobileAuth['expires_at'])
            || (int) $mobileAuth['expires_at'] <= now()->timestamp
        ) {
            return $this->returnToApp($state, ['error' => 'google_failed']);
        }

        if (! $this->isConfigured()) {
            return $this->returnToApp($state, ['error' => 'google_unavailable']);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->returnToApp($state, ['error' => 'google_failed']);
        }

        $email = mb_strtolower(trim((string) $googleUser->getEmail()));
        $raw = $googleUser->getRaw();
        $verified = filter_var(
            data_get($raw, 'email_verified', data_get($raw, 'verified_email', false)),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($email === '' || ! $verified) {
            return $this->returnToApp($state, ['error' => 'unverified_email']);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $fullName = trim((string) $googleUser->getName());
            $nameParts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $fullNameFirst = array_shift($nameParts) ?? '';
            $fullNameLast = implode(' ', $nameParts);

            return $this->createExchangeCode($mobileAuth, [
                'profile' => [
                    'email' => $email,
                    'first_name' => trim((string) ($raw['given_name'] ?? $fullNameFirst)),
                    'last_name' => trim((string) ($raw['family_name'] ?? $fullNameLast)),
                    'profile_photo_url' => $googleUser->getAvatar(),
                ],
            ]);
        }

        if (! $user->isActive()) {
            return $this->returnToApp($state, ['error' => 'inactive_account']);
        }

        if (! in_array(strtoupper((string) $user->account_type), [User::TYPE_BUYER, User::TYPE_RIDER, User::TYPE_LOGISTICS], true)) {
            return $this->returnToApp($state, ['error' => 'mobile_access_required']);
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->createExchangeCode($mobileAuth, [
            'user_id' => $user->id,
        ]);
    }

    private function createExchangeCode(array $mobileAuth, array $result): RedirectResponse
    {
        $code = Str::random(64);
        Cache::put(
            'mobile_google_exchange:'.hash('sha256', $code),
            array_merge($result, [
                'state' => $mobileAuth['state'],
                'code_challenge' => $mobileAuth['code_challenge'],
            ]),
            now()->addMinutes(2),
        );

        return $this->returnToApp($mobileAuth['state'], ['code' => $code]);
    }

    private function returnToApp(string $state, array $parameters): RedirectResponse
    {
        return redirect()->away(
            self::CALLBACK_URL.'?'.http_build_query(
                array_merge(['state' => $state], $parameters),
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
        );
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
