<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;
use Illuminate\View\View;

class AccountRecoveryController extends Controller
{
    private const PORTAL_MARKETPLACE = 'marketplace';

    private const PORTAL_LOGISTICS = 'logistics';

    private const GENERIC_REQUEST_STATUS = "If an account exists with this email, a password reset link will be sent.";

    public function request(Request $request): View
    {
        $portal = $this->portal($request->input('portal'));

        return view('auth.forgot-password', [
            'portal' => $portal,
        ]);
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'portal' => ['nullable', 'string', 'in:marketplace,logistics'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $portal = $this->portal($data['portal'] ?? null);

        // The notification is sent synchronously by the configured broker. The
        // short-lived session value lets the notification keep the originating
        // portal in the reset URL without accepting arbitrary redirect targets.
        $request->session()->put('password_reset_portal', $portal);

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (Throwable $exception) {
            // Do not expose transport details or the submitted address. Keep a
            // diagnostic server-side record while returning the same safe reply.
            Log::error('Password reset notification could not be sent.', [
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);
            $status = Password::RESET_LINK_SENT;
        } finally {
            $request->session()->forget('password_reset_portal');
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()
                ->withInput(['email' => $email, 'portal' => $portal])
                ->withErrors(['email' => 'Please wait before requesting another reset link.']);
        }

        return back()
            ->withInput(['email' => $email, 'portal' => $portal])
            ->with('status', self::GENERIC_REQUEST_STATUS);
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
            'portal' => $this->portal($request->query('portal')),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
            'portal' => ['nullable', 'string', 'in:marketplace,logistics'],
        ]);
        $portal = $this->portal($data['portal'] ?? null);
        $resetUser = null;
        $resetCredentials = [
            'token' => $data['token'],
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            // The confirmation is checked by the `confirmed` rule on
            // password, but Laravel excludes the confirmation field from
            // validated() unless it has its own rule.
            'password_confirmation' => $request->input('password_confirmation'),
        ];

        $status = Password::reset($resetCredentials, function (User $user, string $password) use (&$resetUser): void {
            $resetUser = $user;
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            $loginRoute = $resetUser?->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER)
                ? 'logistics.login'
                : 'login';

            return redirect()
                ->route($loginRoute)
                ->with('status', 'Your password has been reset successfully. You can now sign in with your new password.');
        }

        $message = match ($status) {
            Password::INVALID_TOKEN => 'This password reset link is invalid or has expired. Request a new reset link to continue.',
            Password::RESET_THROTTLED => 'Please wait before requesting another reset link.',
            default => 'We could not reset your password with this link. Request a new reset link to continue.',
        };

        return redirect()
            ->route('password.reset', [
                'token' => $data['token'],
                'email' => $data['email'],
                'portal' => $portal,
            ])
            ->withInput(['email' => $data['email'], 'portal' => $portal])
            ->withErrors(['email' => $message]);
    }

    private function portal(?string $portal): string
    {
        return $portal === self::PORTAL_LOGISTICS
            ? self::PORTAL_LOGISTICS
            : self::PORTAL_MARKETPLACE;
    }
}
