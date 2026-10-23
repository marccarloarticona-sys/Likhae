<?php

use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\Auth\GoogleAuthenticationController;
use App\Http\Controllers\Auth\MobileGoogleAuthenticationController;
use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\PolicyAcceptanceController;
use App\Http\Controllers\Buyer\GuestMarketplaceController;
use App\Http\Controllers\Buyer\BuyerController;
use App\Http\Controllers\Buyer\TrackingController;
use App\Http\Controllers\Communication\ConversationAttachmentController;
use App\Http\Controllers\Communication\ConversationReceiptController;
use App\Http\Controllers\Communication\ConversationReadController;
use App\Http\Controllers\Communication\MessagePresenceController;
use App\Http\Controllers\NotificationCenterController;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Role Route Files
|--------------------------------------------------------------------------
*/
require __DIR__.'/Admin.php';
require __DIR__.'/Seller.php';
require __DIR__.'/Buyer.php';
require __DIR__.'/logistics.php';
require __DIR__.'/rider.php';

/*
|--------------------------------------------------------------------------
| Guest Marketplace
|--------------------------------------------------------------------------
*/
Route::get('/', [GuestMarketplaceController::class, 'home'])->name('home');
Route::get('/guest-account', [GuestMarketplaceController::class, 'products'])->name('guest.home');
Route::get('/products', [GuestMarketplaceController::class, 'products'])->name('products');
Route::get('/products/{slug}', [GuestMarketplaceController::class, 'show'])->name('products.show');
Route::get('/shops/{seller}', [BuyerController::class, 'publicShop'])->name('shops.show');
Route::get('/marketplace/search-suggestions', [GuestMarketplaceController::class, 'searchSuggestions'])
    ->middleware('throttle:60,1')
    ->name('marketplace.search-suggestions');
Route::get('/track/{trackingCode}', [TrackingController::class, 'show'])->middleware('throttle:30,1')->name('tracking.show');
Route::get('/track/{trackingCode}/live', [TrackingController::class, 'live'])->middleware('throttle:120,1')->name('tracking.live');
Route::get('/delivery-events/{event}/proof', [TrackingController::class, 'proof'])->middleware('auth')->name('delivery-events.proof');
Route::get('/messages/{message}/attachment', [ConversationAttachmentController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('messages.attachments.show');
Route::post('/conversations/{conversation}/read', [ConversationReadController::class, 'mark'])
    ->middleware(['auth', 'verified'])
    ->whereNumber('conversation')
    ->name('messages.read');
Route::get('/conversations/{conversation}/receipt', [ConversationReceiptController::class, 'show'])
    ->middleware(['auth', 'verified', 'throttle:60,1'])
    ->whereNumber('conversation')
    ->name('messages.receipt');
Route::post('/messages/presence', [MessagePresenceController::class, 'touch'])
    ->middleware(['auth', 'verified', 'throttle:30,1'])
    ->name('messages.presence');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationCenterController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/announcements/{notification}/dismiss', [NotificationCenterController::class, 'dismissAnnouncement'])
        ->whereNumber('notification')
        ->name('notifications.announcements.dismiss');
    Route::post('/notifications/announcements/{notification}/open', [NotificationCenterController::class, 'openAnnouncement'])
        ->whereNumber('notification')
        ->name('notifications.announcements.open');
});

/*
|--------------------------------------------------------------------------
| Marketplace Login
|--------------------------------------------------------------------------
|
| Admin, Buyer, and Seller use this login. Logistics and Rider/Courier accounts use
| the separate Logistics & Rider portal login. Rider and Courier are one workspace role.
|
*/
Route::get('/login', function (Request $request, AuthenticationController $auth) {
    $user = $request->user();
    if ($user) {
        if ($user->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER)) {
            return redirect()->route('logistics.login');
        }

        $pendingPolicies = $request->attributes->get('mandatory_policy_documents');
        if (! $pendingPolicies instanceof \Illuminate\Support\Collection || $pendingPolicies->isEmpty()) {
            return redirect()->route($user->workspaceRoute());
        }
    }

    $savedAccounts = collect($auth->savedAccounts($request, 'marketplace'));

    return view('auth.login', compact('savedAccounts'));
})
    ->name('login');

Route::post('/login', [AuthenticationController::class, 'store'])
    ->middleware(['guest', 'throttle:30,1'])
    ->name('login.post');

Route::post('/login/recent-accounts/remove', [AuthenticationController::class, 'removeRecentAccount'])
    ->middleware('guest')
    ->name('login.recent-accounts.remove');
Route::post('/login/recent-accounts/clear', [AuthenticationController::class, 'clearRecentAccounts'])
    ->middleware('guest')
    ->name('login.recent-accounts.clear');

Route::post('/switch-account', [AuthenticationController::class, 'switchAccount'])
    ->middleware('auth')
    ->name('account.switch');

Route::get('/forgot-password', [AccountRecoveryController::class, 'request'])->middleware('guest')->name('password.request');
Route::post('/forgot-password', [AccountRecoveryController::class, 'email'])->middleware(['guest', 'throttle:5,1'])->name('password.email');
Route::get('/reset-password/{token}', [AccountRecoveryController::class, 'reset'])->middleware('guest')->name('password.reset');
Route::post('/reset-password', [AccountRecoveryController::class, 'update'])->middleware('guest')->name('password.update');

Route::get('/email/verify', fn () => view('auth.verify-email'))->middleware('auth')->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();

    return redirect()->route($request->user()->workspaceRoute());
})->middleware(['auth', 'signed', 'throttle:6,1'])->name('verification.verify');
Route::post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();

    return back()->with('status', 'verification-link-sent');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

/*
|--------------------------------------------------------------------------
| Registration — Fixed Account Type
|--------------------------------------------------------------------------
|
| Public registrations are Buyer, Seller, Logistics, or Rider/Courier. A user does
| not become Buyer first and later upgrade into another role.
|
*/
Route::get('/register', [RegistrationController::class, 'create'])
    ->middleware('guest')
    ->name('register');
Route::post('/register/email-verification/send', [RegistrationController::class, 'sendEmailVerificationCode'])
    ->middleware('guest')
    ->name('register.email-verification.send');
Route::post('/register/email-verification/verify', [RegistrationController::class, 'verifyEmailCode'])
    ->middleware('guest')
    ->name('register.email-verification.verify');
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware('guest')
    ->name('register.store');

// Compatibility links from the existing navigation now open the correct
// fixed-role registration form instead of a Buyer-to-Seller/Logistics upgrade.
Route::get('/sell', fn () => redirect()->route('register', ['role' => 'seller']))->name('sell.create');
Route::get('/partner', fn () => redirect()->route('register', ['role' => 'logistics']))->name('partner.create');
Route::get('/partner/status', fn () => redirect()->route('register', ['role' => 'logistics']))->name('partner.status');

/*
|--------------------------------------------------------------------------
| Philippine Address API
|--------------------------------------------------------------------------
*/
Route::prefix('address/philippines')
    ->name('address.philippines.')
    ->group(function () {
        Route::get('/regions', [PhilippineAddressController::class, 'regions'])->name('regions');
        Route::get('/regions/{region}/provinces', [PhilippineAddressController::class, 'provinces'])->name('provinces');
        Route::get('/provinces/{province}/municipalities', [PhilippineAddressController::class, 'municipalities'])->name('municipalities');
        Route::get('/municipalities/{municipality}/barangays', [PhilippineAddressController::class, 'barangays'])->name('barangays');
        Route::get('/postal-code', [PhilippineAddressController::class, 'postalCode'])->name('postal-code');
    });

Route::post('/logout', [AuthenticationController::class, 'destroy'])->name('logout');
Route::post('/policy-acceptance', [PolicyAcceptanceController::class, 'store'])
    ->middleware('auth')
    ->name('policies.accept');

/* Google is retained for Buyer convenience without adding social-token tables. */
Route::get('/auth/google', [GoogleAuthenticationController::class, 'redirect'])
    ->middleware('guest')
    ->name('google.redirect');
Route::get('/auth/google/mobile', [MobileGoogleAuthenticationController::class, 'redirect'])
    ->name('google.mobile.redirect');
Route::get('/auth/google/callback', [GoogleAuthenticationController::class, 'callback'])
    ->name('google.callback');

Route::get('/continue-as-guest', function (Request $request) {
    $request->session()->put('demo_user', [
        'email' => 'guest',
        'role' => 'guest',
    ]);

    return redirect()->route('guest.home');
})->name('guest.continue');

Route::get('/admin/home', fn () => redirect()->route('admin.dashboard'))->name('admin.home');
