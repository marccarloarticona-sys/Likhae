<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));
        $request->merge(['email' => $email]);
        $key = 'login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, 60);
        $isLogisticsPortal = $request->routeIs('logistics.login.store');

        $authenticated = Auth::attemptWhen(
            [
                'email' => $email,
                'password' => $credentials['password'],
            ],
            function (User $user) use ($isLogisticsPortal): bool {
                if (! $user->isActive()) {
                    throw ValidationException::withMessages(['email' => $user->inactiveMessage()]);
                }

                $allowed = $isLogisticsPortal
                    ? [User::TYPE_LOGISTICS, User::TYPE_RIDER]
                    : [User::TYPE_ADMIN, User::TYPE_BUYER, User::TYPE_SELLER];

                if (! $user->isAccountType(...$allowed)) {
                    throw ValidationException::withMessages([
                        'email' => $isLogisticsPortal
                            ? 'Use the Marketplace Login page for this account.'
                            : 'Use the Logistics & Rider Portal sign-in page for this account.',
                    ]);
                }

                return true;
            },
            $request->boolean('remember'),
        );

        if (! $authenticated) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget('demo_user');

        /** @var User $user */
        $user = $request->user();
        $redirect = redirect()->route($user->workspaceRoute());

        $savedAccounts = $this->rememberAccount($request, $user);

        $redirect
            ->withCookie($this->savedAccountsCookie($request, $savedAccounts))
            ->with('status', 'Your account is available for quick sign-in on this browser.');

        return $redirect;
    }

    /** @return array<int, array<string, mixed>> */
    public function savedAccounts(Request $request, string $portal): array
    {
        abort_unless(in_array($portal, ['marketplace', 'logistics'], true), 404);

        return $this->allSavedAccounts($request)
            ->where('portal', $portal)
            ->take(5)
            ->values()
            ->all();
    }

    public function removeRecentAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'portal' => ['required', 'in:marketplace,logistics'],
        ]);

        $accounts = $this->allSavedAccounts($request)
            ->reject(fn (array $account): bool => (int) $account['id'] === (int) $data['account_id'])
            ->values()
            ->all();

        return $this->recentAccountsRedirect($request, $accounts, $data['portal'], 'Recent account removed.');
    }

    public function clearRecentAccounts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'portal' => ['required', 'in:marketplace,logistics'],
        ]);

        $accounts = $this->allSavedAccounts($request)
            ->reject(fn (array $account): bool => $account['portal'] === $data['portal'])
            ->values()
            ->all();

        return $this->recentAccountsRedirect($request, $accounts, $data['portal'], 'Recent accounts cleared.');
    }

    public function switchAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'integer'],
        ]);

        $savedAccount = collect($request->session()->get('saved_accounts', []))
            ->filter(fn ($account): bool => is_array($account))
            ->first(fn (array $account): bool => (int) ($account['id'] ?? 0) === (int) ($data['account_id'] ?? 0));

        Auth::logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        if (! $savedAccount) {
            return redirect()->route('login')->with('status', 'Sign in with the account you want to use.');
        }

        $loginRoute = ($savedAccount['portal'] ?? 'marketplace') === 'logistics'
            ? 'logistics.login'
            : 'login';

        return redirect()->route($loginRoute, ['email' => $savedAccount['email']])
            ->with('status', 'Enter your password to switch accounts.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $portal = $user instanceof User
            && $user->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER);
        $savedAccounts = $this->allSavedAccounts($request)->all();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($savedAccounts !== []) {
            $request->session()->put('saved_accounts', $savedAccounts);
        }

        return redirect()
            ->route($portal ? 'logistics.login' : 'login')
            ->withCookie($this->savedAccountsCookie($request, $savedAccounts))
            ->with('status', 'You have been signed out.');
    }

    private function rememberAccount(Request $request, User $user): array
    {
        $portal = $user->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER)
            ? 'logistics'
            : 'marketplace';

        $currentAccount = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_type' => $user->account_type,
            'avatar_path' => $user->profile_photo_path,
            'portal' => $portal,
            'remembered' => true,
        ];

        $accounts = $this->allSavedAccounts($request)
            ->reject(fn (array $account): bool => (int) $account['id'] === (int) $user->id)
            ->prepend($currentAccount)
            ->groupBy('portal')
            ->flatMap(fn (Collection $portalAccounts) => $portalAccounts->take(5))
            ->values()
            ->all();

        $request->session()->put('saved_accounts', $accounts);

        return $accounts;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function allSavedAccounts(Request $request): Collection
    {
        $cookieAccounts = $request->cookie('likhae_saved_accounts', []);
        if (is_string($cookieAccounts)) {
            $cookieAccounts = json_decode($cookieAccounts, true);
        }

        return collect($request->session()->get('saved_accounts', []))
            ->merge(is_array($cookieAccounts) ? $cookieAccounts : [])
            ->filter(fn ($account): bool => is_array($account)
                && is_numeric($account['id'] ?? null)
                && (int) $account['id'] > 0
                && is_string($account['name'] ?? null)
                && is_string($account['email'] ?? null)
                && filter_var($account['email'], FILTER_VALIDATE_EMAIL) !== false
                && in_array(strtoupper((string) ($account['account_type'] ?? '')), User::ACCOUNT_TYPES, true)
                && in_array($account['portal'] ?? 'marketplace', ['marketplace', 'logistics'], true)
                && (($account['portal'] ?? 'marketplace') === 'logistics'
                    ? in_array(strtoupper((string) $account['account_type']), [User::TYPE_LOGISTICS, User::TYPE_RIDER], true)
                    : in_array(strtoupper((string) $account['account_type']), [User::TYPE_ADMIN, User::TYPE_BUYER, User::TYPE_SELLER], true)))
            ->map(fn (array $account): array => [
                'id' => (int) $account['id'],
                'name' => trim($account['name']) !== '' ? trim($account['name']) : $account['email'],
                'email' => trim($account['email']),
                'account_type' => strtoupper((string) $account['account_type']),
                'avatar_path' => $this->safeAvatarPath($account['avatar_path'] ?? null),
                'portal' => $account['portal'] ?? 'marketplace',
                'remembered' => true,
            ])
            ->unique(fn (array $account): string => $account['portal'].':'.$account['id'])
            ->values();
    }

    private function safeAvatarPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || preg_match('~^[a-z][a-z0-9+.-]*:|^[/\\\\]|[?#]|(^|[/\\\\])\\.\\.?([/\\\\]|$)~i', $path)) {
            return null;
        }

        return trim(str_replace('\\', '/', $path));
    }

    /** @param array<int, array<string, mixed>> $accounts */
    private function recentAccountsRedirect(Request $request, array $accounts, string $portal, string $message): RedirectResponse
    {
        $request->session()->put('saved_accounts', $accounts);

        return redirect()
            ->route($portal === 'logistics' ? 'logistics.login' : 'login')
            ->withCookie($this->savedAccountsCookie($request, $accounts))
            ->with('status', $message);
    }

    /** @param array<int, array<string, mixed>> $accounts */
    private function savedAccountsCookie(Request $request, array $accounts)
    {
        if ($accounts === []) {
            return cookie()->forget('likhae_saved_accounts', '/');
        }

        return cookie()->make(
            'likhae_saved_accounts',
            json_encode($accounts, JSON_THROW_ON_ERROR),
            60 * 24 * 30,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        );
    }
}
