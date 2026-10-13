<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Mail\RegistrationEmailVerificationCode;
use App\Models\Admin\PlatformSetting;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\Category;
use App\Models\User;
use App\Services\RegistrationWorkflowService;
use App\Support\AddressCoordinateValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class RegistrationController extends Controller
{
    private const EMAIL_CODE_TTL_MINUTES = 5;

    public function __construct(
        private readonly RegistrationWorkflowService $workflow,
    ) {
    }

    public function create(Request $request): View
    {
        abort_unless(
            (bool) PlatformSetting::valueOf('registration_enabled', true),
            403,
            'New registrations are temporarily disabled.',
        );

        $requested = strtolower((string) $request->query('role', 'buyer'));
        $preselectedRole = in_array($requested, ['buyer', 'seller', 'logistics', 'rider'], true)
            ? $requested
            : 'buyer';

        return view('auth.register', [
            'preselectedRole' => $preselectedRole,
            'googleBuyerRegistration' => $request->session()->get('google_buyer_registration'),
            'sellerLineOfBusinessCategories' => Category::query()
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get(['id', 'name']),
            'logisticsCenters' => LogisticsCenter::query()
                ->active()
                ->whereHas('address')
                ->with('address:id,province_code,municipality_code')
                ->orderBy('business_name')
                ->get(['id', 'address_id', 'code', 'business_name']),
        ]);
    }

    public function sendEmailVerificationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json([
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
                'message' => 'The verification email could not be sent. Check the SMTP settings and try again.',
            ], 503);
        }

        Cache::put(
            $this->emailCodeCacheKey($email),
            [
                'hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES)->timestamp,
            ],
            now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES),
        );

        $request->session()->forget('registration_email_verified');

        return response()->json([
            'message' => 'A verification code was sent. It expires in 5 minutes.',
        ]);
    }

    public function verifyEmailCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $cacheKey = $this->emailCodeCacheKey($email);
        $challenge = Cache::get($cacheKey);

        if (! is_array($challenge) || ! isset($challenge['hash'], $challenge['expires_at'])) {
            return response()->json([
                'message' => 'This code has expired. Request a new verification code.',
            ], 422);
        }

        if ((int) $challenge['expires_at'] <= now()->timestamp) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'This code has expired. Request a new verification code.',
            ], 422);
        }

        if (! Hash::check($data['code'], $challenge['hash'])) {
            return response()->json([
                'message' => 'That verification code is incorrect.',
                'errors' => ['code' => ['That verification code is incorrect.']],
            ], 422);
        }

        Cache::forget($cacheKey);
        $request->session()->put('registration_email_verified', $email);

        return response()->json([
            'message' => 'Email verified. You can continue your registration.',
        ]);
    }

    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        abort_unless(
            (bool) PlatformSetting::valueOf('registration_enabled', true),
            403,
            'New registrations are temporarily disabled.',
        );

        $data = $request->validated();
        $googleRegistration = $request->session()->get('google_buyer_registration');
        $googleEmail = is_array($googleRegistration)
            ? mb_strtolower(trim((string) ($googleRegistration['email'] ?? '')))
            : '';

        if (
            $googleEmail !== mb_strtolower(trim($data['email']))
            && $request->session()->get('registration_email_verified') !== mb_strtolower(trim($data['email']))
        ) {
            throw ValidationException::withMessages([
                'email' => 'Verify your email address before submitting your registration.',
            ]);
        }

        abort_unless(
            PhilippineAddressController::selectionIsValid(
                (string) $data['region_code'],
                (string) $data['region'],
                (string) $data['province_code'],
                (string) $data['province'],
                (string) $data['municipality_code'],
                (string) $data['municipality'],
                (string) $data['barangay_code'],
                (string) $data['barangay'],
            ),
            422,
            'The selected Philippine address is invalid.',
        );

        $expectedPostalCode = PhilippineAddressController::expectedPostalCodeFor(
            (string) $data['province_code'],
            (string) $data['municipality_code'],
            (string) $data['province'],
            (string) $data['municipality'],
        );

        if ($expectedPostalCode !== null && filled($data['postal_code'] ?? null) && (string) $data['postal_code'] !== $expectedPostalCode) {
            return back()
                ->withErrors(['postal_code' => 'The postal code does not match the selected Philippine address.'])
                ->withInput();
        }

        AddressCoordinateValidator::assertValid($data);

        $application = $this->workflow->submit($request);
        $request->session()->forget(['google_buyer_registration', 'registration_email_verified']);

        $type = strtolower((string) $application->user->account_type);
        $reviewer = $type === 'rider' ? 'the selected LIKHAE Logistics Center' : 'the LIKHAE administrator';
        $loginRoute = in_array($type, ['logistics', 'rider'], true) ? 'logistics.login' : 'login';

        return redirect()
            ->route($loginRoute)
            ->with(
                'status',
                'Registration submitted successfully. Application '.$application->application_number.' is pending review by '.$reviewer.'. You can sign in after your application is approved.',
            );
    }

    private function emailCodeCacheKey(string $email): string
    {
        return 'registration_email_code:'.hash('sha256', $email);
    }
}
