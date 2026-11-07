<?php

// Loaded inside v1's bearer-token and policy-acceptance middleware group.
use App\Http\Controllers\Api\BuyerParityApiController as BuyerParity;
use App\Http\Controllers\Api\MobileWorkflowApiController as MobileWorkflow;
use App\Http\Controllers\Buyer\BuyerOrderController;
use App\Http\Controllers\Buyer\BuyerPromotionController;
use App\Http\Controllers\Buyer\BuyerReturnRefundController;
use App\Http\Controllers\Rider\RiderFinanceController;
use App\Http\Controllers\Finance\PayoutProofController;
use App\Http\Controllers\Logistics\InterhubTimekeepingController;
use App\Http\Controllers\WorkspaceAiController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnsureWorkspaceRole;
use Illuminate\Support\Facades\Route;

Route::prefix('buyer')->name('api.v1.buyer.')->middleware(EnsureWorkspaceRole::class.':buyer')->group(function (): void {
    Route::get('settings', [BuyerParity::class, 'buyerSettings'])->name('settings');
    Route::put('settings', [BuyerParity::class, 'updateBuyerSettings'])->name('settings.update');
    Route::delete('account', [BuyerParity::class, 'deleteAccount'])->middleware('throttle:5,1')->name('account.destroy');
    Route::get('account/reviews', [BuyerParity::class, 'reviews'])->name('account.reviews');
    Route::get('wallet/destinations', [BuyerParity::class, 'refundDestinations'])->name('wallet.destinations');
    Route::put('wallet/destinations', [BuyerParity::class, 'updateRefundDestination'])->middleware('throttle:10,1')->name('wallet.destinations.update');
    Route::put('addresses/{address}', [BuyerParity::class, 'updateAddress'])->name('addresses.update');
    Route::post('profile/email-change', [BuyerParity::class, 'requestEmailChange'])->middleware('throttle:5,1')->name('email.request');
    Route::post('profile/email-change/resend', [BuyerParity::class, 'resendEmailChangeCode'])->middleware('throttle:3,1')->name('email.resend');
    Route::post('profile/email-change/verify', [BuyerParity::class, 'verifyEmailChange'])->middleware('throttle:10,1')->name('email.verify');
    Route::get('stores/{seller}/follow', [BuyerParity::class, 'storeFollowingStatus'])->name('stores.follow.status');
    Route::post('stores/{seller}/follow', [BuyerParity::class, 'setStoreFollowing'])->name('stores.follow');
    Route::delete('stores/{seller}/follow', [BuyerParity::class, 'removeStoreFollowing'])->name('stores.unfollow');
    Route::get('stores/{seller}/vouchers', [BuyerParity::class, 'storeVouchers'])->name('stores.vouchers');
    Route::get('products/{product}/flash-reminder', [BuyerParity::class, 'flashSaleReminderStatus'])->name('reminders.show');
    Route::post('products/{product}/flash-reminder', [BuyerParity::class, 'setFlashSaleReminder'])->name('reminders.store');
    Route::delete('products/{product}/flash-reminder', [BuyerParity::class, 'removeFlashSaleReminder'])->name('reminders.destroy');
    Route::get('events/{voucher}', [BuyerParity::class, 'event'])->name('events.show');
    Route::post('vouchers/{voucher}/claim', [BuyerParity::class, 'claimVoucher'])->middleware('throttle:20,1')->name('vouchers.claim');
    Route::post('orders/{order}/buy-again', [BuyerParity::class, 'buyAgain'])->name('orders.buy-again');
    Route::post('orders/{order}/delivery-pin', [BuyerParity::class, 'confirmDeliveryPin'])->name('orders.delivery-pin');
    Route::post('orders/{order}/shipments/{shipment}/received', [BuyerOrderController::class, 'receivedShipment'])->name('orders.shipments.received');
    Route::get('returns', [BuyerParity::class, 'returnRequests'])->name('returns.index');
    Route::get('returns/{returnRequest}', [BuyerParity::class, 'returnRequest'])->whereNumber('returnRequest')->name('returns.show');
    Route::post('returns/seller-cases/{sellerRequest}/confirm', [BuyerReturnRefundController::class, 'confirm'])->name('returns.confirm');
    Route::post('returns/seller-cases/{sellerRequest}/escalate', [BuyerReturnRefundController::class, 'escalate'])->name('returns.escalate');
    Route::post('returns/cash-refunds/{obligation}/confirm', [BuyerReturnRefundController::class, 'confirmCash'])->name('returns.cash-confirm');
    Route::get('returns/evidence/{evidence}', [BuyerReturnRefundController::class, 'evidence'])->name('returns.evidence');
    Route::get('returns/payout-proofs/{payout}', [BuyerReturnRefundController::class, 'payoutProof'])->name('returns.proof');
    Route::get('offers', [MobileWorkflow::class, 'offers'])->name('offers');
    Route::post('cart/bundles/{promotion}', [BuyerPromotionController::class, 'addBundle'])->name('cart.bundle');
    Route::post('cart/offers/{promotion}/claim', [BuyerPromotionController::class, 'claimBuyXGetY'])->name('cart.offer');
    Route::get('messages/{conversation}/receipt', [MobileWorkflow::class, 'receipt'])->name('messages.receipt');
});

Route::prefix('rider')->name('api.v1.rider.')->middleware([EnsureWorkspaceRole::class.':rider', 'rider.active'])->group(function (): void {
    require __DIR__.'/rider_mobile_workflows.php';
    Route::get('settings', [MobileWorkflow::class, 'settings'])->name('settings');
    Route::put('settings', [WorkspaceSettingsController::class, 'update'])->defaults('workspace', 'rider')->name('settings.update');
    Route::post('ai/chat', [WorkspaceAiController::class, 'chat'])->defaults('workspace', 'rider')->middleware('throttle:20,1')->name('ai.chat');
    Route::get('messages/{conversation}/receipt', [MobileWorkflow::class, 'receipt'])->name('messages.receipt');
    Route::middleware('rider.operational:LOCAL_BARANGAY')->group(function (): void {
        require __DIR__.'/rider_payouts.php';
        Route::put('wallet/payout-destination', [RiderFinanceController::class, 'updatePayoutDestination'])->name('payout-destination');
        Route::post('earnings/payout-requests', [RiderFinanceController::class, 'requestPayout'])->name('payout-requests');
        Route::post('earnings/payouts/{entry}/acknowledge', [RiderFinanceController::class, 'acknowledgePayout'])->name('payouts.acknowledge');
        Route::get('earnings/payouts/{entry}/proof', [PayoutProofController::class, 'rider'])->name('payouts.proof');
    });
    Route::middleware('rider.operational:INTER_LOGISTICS')->group(function (): void {
        Route::get('attendance', [MobileWorkflow::class, 'attendance'])->name('attendance');
        Route::get('attendance/qr', [InterhubTimekeepingController::class, 'driverQr'])->name('attendance.qr');
        Route::post('attendance/scan', [InterhubTimekeepingController::class, 'scanByDriver'])->middleware('throttle:30,1')->name('attendance.scan');
        Route::get('attendance/payroll-items/{item}/proof', [InterhubTimekeepingController::class, 'driverProof'])->name('attendance.proof');
    });
});
