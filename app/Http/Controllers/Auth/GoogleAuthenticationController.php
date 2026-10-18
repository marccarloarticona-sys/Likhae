<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthenticationController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in is not configured yet. Please use your email and password.',
            ]);
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $mobileAuth = $request->session()->pull('google_mobile_auth');
        if (is_array($mobileAuth)) {
            return app(MobileGoogleAuthenticationController::class)
                ->callback($request, $mobileAuth);
        }

        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in is not configured yet. Please use your email and password.',
            ]);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in could not be completed. Please try again.',
            ]);
        }

        $email = mb_strtolower(trim((string) $googleUser->getEmail()));
        // Google userinfo responses use `verified_email`; some OpenID responses
        // use `email_verified`. Accept either form so valid accounts are not
        // rejected after the OAuth callback.
        $raw = $googleUser->getRaw();
        $verified = filter_var(
            data_get($raw, 'email_verified', data_get($raw, 'verified_email', false)),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($email === '' || ! $verified) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google did not provide a verified email address.',
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $fullName = trim((string) $googleUser->getName());
            $nameParts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $fullNameFirst = array_shift($nameParts) ?? '';
            $fullNameLast = implode(' ', $nameParts);
            $firstName = trim((string) ($raw['given_name'] ?? $fullNameFirst));
            $lastName = trim((string) ($raw['family_name'] ?? $fullNameLast));
            $request->session()->put('google_buyer_registration', [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'profile_photo_url' => $googleUser->getAvatar(),
            ]);

            return redirect()->route('register', ['role' => 'buyer']);
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->withErrors(['email' => $user->inactiveMessage()]);
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget('demo_user');

        return redirect()->route($user->workspaceRoute());
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
