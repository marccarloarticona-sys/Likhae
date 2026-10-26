<?php

use App\Http\Controllers\Api\BuyerApiController;
use App\Http\Controllers\Api\LookupApiController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\PlatformEventApiController;
use App\Http\Controllers\Api\ProductApiController;
use App\Http\Controllers\Api\RiderApiController;
use App\Http\Controllers\Api\RiderVehicleProfileController;
use App\Http\Controllers\Api\TrackingApiController;
use App\Http\Controllers\Api\WorkflowApiController;
use App\Http\Controllers\Rider\RiderPickupBatchController;
use App\Http\Controllers\Buyer\BuyerAiController;
use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Http\Controllers\Buyer\BuyerOrderController;
use App\Http\Controllers\PolicyAcceptanceController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\RequireApiPolicyAcceptance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'LIKHAE API is running.',
            'timestamp' => now()->toIso8601String(),
        ]);
    })->name('api.v1.health');

    Route::prefix('address/philippines')
        ->name('api.v1.address.philippines.')
        ->group(function (): void {
            Route::get('/regions', [PhilippineAddressController::class, 'regions'])->name('regions');
            Route::get('/regions/{region}/provinces', [PhilippineAddressController::class, 'provinces'])->name('provinces');
            Route::get('/provinces/{province}/municipalities', [PhilippineAddressController::class, 'municipalities'])->name('municipalities');
            Route::get('/municipalities/{municipality}/barangays', [PhilippineAddressController::class, 'barangays'])->name('barangays');
            Route::get('/postal-code', [PhilippineAddressController::class, 'postalCode'])->name('postal-code');
        });

    Route::post('/auth/login', [MobileAuthController::class, 'login'])->middleware('throttle:10,1')->name('api.v1.auth.login');
    Route::post('/auth/google/exchange', [MobileAuthController::class, 'exchangeGoogle'])->middleware('throttle:10,1')->name('api.v1.auth.google.exchange');
    Route::post('/auth/password/email', [MobileAuthController::class, 'sendPasswordResetLink'])->middleware('throttle:5,1')->name('api.v1.auth.password.email');
    Route::post('/auth/register/email-verification/send', [MobileAuthController::class, 'sendRegistrationEmailVerificationCode'])
        ->middleware('throttle:5,1')
        ->name('api.v1.auth.register.email-verification.send');
    Route::post('/auth/register/email-verification/verify', [MobileAuthController::class, 'verifyRegistrationEmailVerificationCode'])
        ->middleware('throttle:10,1')
        ->name('api.v1.auth.register.email-verification.verify');
    Route::post('/auth/register', [MobileAuthController::class, 'register'])->middleware('throttle:5,1')->name('api.v1.auth.register');
    Route::get('/auth/registration-policies', [PolicyAcceptanceController::class, 'registration'])
        ->middleware('throttle:30,1')
        ->name('api.v1.policies.registration');

    Route::get('/categories', [LookupApiController::class, 'categories'])->name('api.v1.categories.index');
    Route::get('/logistics-centers', [LookupApiController::class, 'logisticsCenters'])->name('api.v1.logistics-centers.index');
    Route::get('/events', [PlatformEventApiController::class, 'index'])->name('api.v1.events.index');

    Route::get('/products', [ProductApiController::class, 'index'])->name('api.v1.products.index');
    Route::get('/products/{slug}', [ProductApiController::class, 'show'])->name('api.v1.products.show');
    Route::get('/stores/{seller}', [ProductApiController::class, 'store'])->name('api.v1.stores.show');

    Route::get('/tracking/{trackingNumber}', [TrackingApiController::class, 'show'])->name('api.v1.tracking.show');

    Route::middleware([AuthenticateApiToken::class, RequireApiPolicyAcceptance::class])->group(function (): void {
        Route::post('/policies/accept', [PolicyAcceptanceController::class, 'apiStore'])->name('api.v1.policies.accept');
        Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request))
            ->name('api.v1.broadcasting.auth');
        Route::get('/auth/me', [MobileAuthController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('/buyer/following-stores', [BuyerApiController::class, 'followingStores'])->name('api.v1.buyer.following-stores.index');
        Route::get('/buyer/cart', [BuyerApiController::class, 'cart'])->name('api.v1.buyer.cart.index');
        Route::post('/buyer/cart', [BuyerApiController::class, 'addCartItem'])->name('api.v1.buyer.cart.store');
        Route::patch('/buyer/cart/items/{item}', [BuyerApiController::class, 'updateCartItem'])->name('api.v1.buyer.cart.update');
        Route::delete('/buyer/cart/items/{item}', [BuyerApiController::class, 'removeCartItem'])->name('api.v1.buyer.cart.destroy');
        Route::get('/buyer/orders', [BuyerApiController::class, 'orders'])->name('api.v1.buyer.orders.index');
        Route::get('/buyer/orders/{order}', [BuyerApiController::class, 'showOrder'])->name('api.v1.buyer.orders.show');
        Route::get('/buyer/delivery-attempts/{attempt}/proof', [BuyerApiController::class, 'deliveryAttemptProof'])->name('api.v1.buyer.delivery-attempts.proof');
        Route::get('/buyer/orders/{order}/live-location', [BuyerApiController::class, 'liveRiderLocation'])->name('api.v1.buyer.orders.live-location');
        Route::post('/buyer/orders/{order}/cancel', [BuyerOrderController::class, 'cancel'])->name('api.v1.buyer.orders.cancel');
        Route::post('/buyer/orders/{order}/return-refund', [BuyerOrderController::class, 'returnRefund'])->name('api.v1.buyer.orders.return-refund');
        Route::get('/buyer/checkout/options', [BuyerApiController::class, 'checkoutOptions'])
            ->middleware('throttle:60,1')
            ->name('api.v1.buyer.checkout.options');
        Route::post('/buyer/cart/preview', [BuyerApiController::class, 'previewCheckout'])
            ->middleware('throttle:60,1')
            ->name('api.v1.buyer.cart.preview');
        Route::post('/buyer/checkout/preview', [BuyerApiController::class, 'previewCheckout'])
            ->middleware('throttle:60,1')
            ->name('api.v1.buyer.checkout.preview');
        Route::post('/buyer/checkout', [BuyerApiController::class, 'placeOrder'])->name('api.v1.buyer.checkout.store');
        Route::post('/buyer/orders/items/{item}/review', [BuyerOrderController::class, 'review'])->name('api.v1.buyer.orders.items.review');
        Route::get('/buyer/addresses', [BuyerApiController::class, 'addresses'])->name('api.v1.buyer.addresses.index');
        Route::post('/buyer/addresses', [BuyerApiController::class, 'createAddress'])->name('api.v1.buyer.addresses.store');
        Route::delete('/buyer/addresses/{address}', [BuyerApiController::class, 'deleteAddress'])->name('api.v1.buyer.addresses.destroy');
        Route::patch('/buyer/addresses/{address}/default', [BuyerApiController::class, 'setDefaultAddress'])->name('api.v1.buyer.addresses.default');
        Route::get('/buyer/profile', [BuyerApiController::class, 'profile'])->name('api.v1.buyer.profile.show');
        Route::patch('/buyer/profile', [BuyerApiController::class, 'updateProfile'])->name('api.v1.buyer.profile.update');
        Route::post('/buyer/profile/mobile-change/resend', [BuyerApiController::class, 'resendMobileChangeCode'])->middleware('throttle:3,1')->name('api.v1.buyer.profile.mobile-change.resend');
        Route::delete('/buyer/profile/photo', [BuyerApiController::class, 'deleteProfilePhoto'])->name('api.v1.buyer.profile.photo.destroy');
        Route::put('/buyer/password', [BuyerApiController::class, 'updatePassword'])->middleware('throttle:5,1')->name('api.v1.buyer.password.update');
        Route::get('/buyer/wishlist', [BuyerApiController::class, 'wishlist'])->name('api.v1.buyer.wishlist.index');
        Route::post('/buyer/wishlist/toggle', [BuyerApiController::class, 'toggleWishlist'])->name('api.v1.buyer.wishlist.toggle');
        Route::get('/buyer/rewards', [BuyerApiController::class, 'rewards'])->name('api.v1.buyer.rewards.show');
        Route::get('/buyer/notifications', [BuyerApiController::class, 'notifications'])->name('api.v1.buyer.notifications.index');
        Route::post('/buyer/notifications/read-all', [BuyerApiController::class, 'markNotificationsRead'])->name('api.v1.buyer.notifications.read-all');
        Route::get('/buyer/messages', [BuyerApiController::class, 'conversations'])->name('api.v1.buyer.messages.index');
        Route::get('/buyer/messages/{conversation}', [BuyerApiController::class, 'conversationMessages'])->name('api.v1.buyer.messages.show');
        Route::post('/buyer/messages', [BuyerApiController::class, 'sendMessage'])->name('api.v1.buyer.messages.store');
        Route::post('/buyer/orders/{order}/messages', [BuyerApiController::class, 'sendOrderMessage'])->name('api.v1.buyer.orders.messages.store');
        Route::post('/buyer/ai/chat', [BuyerAiController::class, 'chat'])->name('api.v1.buyer.ai.chat');
        Route::get('/rider/dashboard', [RiderApiController::class, 'dashboard'])->name('api.v1.rider.dashboard');
        Route::get('/rider/assignments', [RiderApiController::class, 'assignments'])->name('api.v1.rider.assignments.index');
        Route::get('/rider/pickups', [RiderApiController::class, 'pickups'])->name('api.v1.rider.pickups.index');
        Route::post('/rider/pickups/batches/{trip}/accept', [RiderApiController::class, 'acceptPickupBatch'])->name('api.v1.rider.pickups.batches.accept');
        Route::post('/rider/pickups/batches/{trip}/start', [RiderApiController::class, 'startPickupBatch'])->name('api.v1.rider.pickups.batches.start');
        Route::post('/rider/pickups/batches/{trip}/scan', [RiderPickupBatchController::class, 'scan'])->name('api.v1.rider.pickups.batches.scan');
        Route::post('/rider/pickups/batches/{trip}/not-collected', [RiderApiController::class, 'markPickupNotCollected'])->name('api.v1.rider.pickups.batches.not-collected');
        Route::post('/rider/pickups/batches/{trip}/finish', [RiderApiController::class, 'finishPickupBatch'])->name('api.v1.rider.pickups.batches.finish');
        Route::post('/rider/pickups/{assignment}/verify', [RiderApiController::class, 'verifyPickup'])->name('api.v1.rider.pickups.verify');
        Route::post('/rider/assignments/{assignment}/location', [RiderApiController::class, 'updateLocation'])->name('api.v1.rider.assignments.location');
        Route::get('/rider/history', [RiderApiController::class, 'history'])->name('api.v1.rider.history.index');
        Route::get('/rider/earnings', [RiderApiController::class, 'earnings'])->name('api.v1.rider.earnings.index');
        Route::get('/rider/profile', [RiderVehicleProfileController::class, 'profile'])->name('api.v1.rider.profile.show');
        Route::patch('/rider/profile/vehicle', [RiderVehicleProfileController::class, 'updatePlateNumber'])->name('api.v1.rider.profile.vehicle.update');
        Route::patch('/rider/profile/address', [RiderVehicleProfileController::class, 'updateResidentialAddress'])->name('api.v1.rider.profile.address.update');
        Route::get('/rider/notifications', [RiderApiController::class, 'notifications'])->name('api.v1.rider.notifications.index');
        Route::post('/rider/notifications/read-all', [RiderApiController::class, 'markNotificationsRead'])->name('api.v1.rider.notifications.read-all');
        Route::get('/rider/messages', [RiderApiController::class, 'conversations'])->name('api.v1.rider.messages.index');
        Route::get('/rider/messages/{conversation}', [RiderApiController::class, 'conversationMessages'])->name('api.v1.rider.messages.show');
        Route::post('/rider/messages', [RiderApiController::class, 'sendMessage'])->name('api.v1.rider.messages.store');
        Route::patch('/seller/orders/{sellerOrder}', [WorkflowApiController::class, 'sellerOrder'])->name('api.v1.seller.orders.transition');
        Route::patch('/rider/assignments/{assignment}', [WorkflowApiController::class, 'riderAssignment'])->name('api.v1.rider.assignments.transition');
        Route::post('/logistics/shipments/{shipment}/receive', [WorkflowApiController::class, 'receiveParcel'])->name('api.v1.logistics.shipments.receive');
        Route::post('/logistics/shipments/{shipment}/sort', [WorkflowApiController::class, 'sortParcel'])->name('api.v1.logistics.shipments.sort');
        Route::post('/logistics/shipments/{shipment}/assign-rider', [WorkflowApiController::class, 'assignRider'])->name('api.v1.logistics.shipments.assign-rider');
        Route::post('/buyer/orders/{order}/received', [BuyerOrderController::class, 'received'])->name('api.v1.buyer.orders.received');
    });
});

Route::fallback(function (Request $request) {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found.',
        'path' => $request->path(),
    ], 404);
});
