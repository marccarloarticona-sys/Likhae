<?php

use App\Http\Controllers\Buyer\BuyerController;
use App\Http\Controllers\Buyer\BuyerAiController;
use App\Http\Controllers\Buyer\BuyerOrderController;
use App\Http\Controllers\Buyer\BuyerPromotionController;
use App\Http\Controllers\Buyer\BuyerPlatformEventController;
use App\Http\Controllers\Buyer\BuyerReturnRefundController;
use App\Http\Controllers\Buyer\BuyerVoucherClaimController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\FlashSaleReminderController;
use App\Http\Controllers\Communication\SmartMessagingController;
use App\Http\Middleware\EnsureWorkspaceRole;
use Illuminate\Support\Facades\Route;

Route::prefix('buyer')
    ->name('buyer.')
    ->middleware(['auth', 'verified', EnsureWorkspaceRole::class.':buyer'])
    ->group(function () {
        Route::get('/pending', fn () => redirect()->route('buyer.home'))->name('pending');

        // This is deliberately separate from buyer Messages and its endpoints.
        Route::post('/ai/chat', [BuyerAiController::class, 'chat'])
            ->middleware('throttle:20,1')
            ->name('ai.chat');

        Route::get('/home', [BuyerController::class, 'home'])->name('home');
        Route::get('/platform-events', [BuyerPlatformEventController::class, 'index'])->name('platform-events.index');
        Route::get('/platform-events/{voucher}', [BuyerPlatformEventController::class, 'show'])->name('platform-events.show');
        Route::get('/discounts', [BuyerController::class, 'discounts'])->name('discounts');
        Route::get('/free-gifts', [BuyerController::class, 'freeGifts'])->name('free-gifts');
        Route::get('/products', [BuyerController::class, 'products'])->name('products');
        Route::get('/products/{slug}', [BuyerController::class, 'product'])->name('product-details');
        Route::get('/wishlist', [BuyerController::class, 'wishlist'])->name('wishlist');
        Route::post('/wishlist/{product}', [BuyerController::class, 'toggleWishlist'])->name('wishlist.toggle');
        Route::delete('/wishlist/clear', [BuyerController::class, 'clearWishlist'])->name('wishlist.clear');
        Route::delete('/wishlist', [BuyerController::class, 'removeWishlistItems'])->name('wishlist.remove');
        Route::get('/shop/{seller}', [BuyerController::class, 'shop'])->name('shop');
        Route::post('/shop/{seller}/follow', [BuyerController::class, 'followShop'])->name('shop.follow');
        Route::delete('/shop/{seller}/follow', [BuyerController::class, 'unfollowShop'])->name('shop.unfollow');

        Route::get('/flash-deals', [BuyerController::class, 'flashDeals'])->name('flash-deals');
        Route::post('/flash-deals/{product}/reminder', [FlashSaleReminderController::class, 'store'])
            ->middleware('throttle:20,1')
            ->whereNumber('product')
            ->name('flash-deals.reminder.store');
        Route::delete('/flash-deals/{product}/reminder', [FlashSaleReminderController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->whereNumber('product')
            ->name('flash-deals.reminder.destroy');
        Route::get('/local-finds', fn () => redirect()->route('buyer.products', ['focus' => 'local']))->name('local-finds');

        Route::get('/cart', [BuyerController::class, 'cart'])->name('cart');
        Route::post('/cart/preview', [BuyerController::class, 'cartPreview'])->middleware('throttle:60,1')->name('cart.preview');
        Route::post('/cart/{product}', [BuyerController::class, 'addCart'])->name('cart.add');
        Route::post('/cart/bundles/{promotion}', [BuyerPromotionController::class, 'addBundle'])->whereNumber('promotion')->name('cart.bundle-add');
        Route::post('/cart/offers/{promotion}/claim', [BuyerPromotionController::class, 'claimBuyXGetY'])->whereNumber('promotion')->name('cart.offer-claim');
        Route::patch('/cart/items/{item}', [BuyerController::class, 'updateCart'])->name('cart.update');
        Route::delete('/cart/items/{item}', [BuyerController::class, 'removeCart'])->name('cart.remove');
        Route::post('/buy-now/{product}', [BuyerController::class, 'buyNow'])->name('buy-now');

        Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
        Route::post('/checkout', [CheckoutController::class, 'select'])->name('checkout.post');
        Route::post('/checkout/voucher', [CheckoutController::class, 'applyVoucher'])->middleware('throttle:20,1')->name('checkout.voucher');
        Route::post('/order', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('order.store');

        Route::get('/orders/success', [BuyerOrderController::class, 'success'])->name('orders.success');
        Route::get('/orders', [BuyerOrderController::class, 'index'])->name('orders');
        Route::get('/orders/stream', [BuyerOrderController::class, 'stream'])->name('orders.stream');
        Route::post('/orders/{order}/seller-orders/{sellerOrder}/buy-again', [BuyerOrderController::class, 'buyAgain'])->name('orders.seller-orders.buy-again');
        Route::get('/returns', [BuyerReturnRefundController::class, 'index'])->name('returns');
        Route::get('/returns/request/{returnRequest}', [BuyerReturnRefundController::class, 'parentShow'])->name('returns.parent');
        Route::get('/returns/{sellerRequest}', [BuyerReturnRefundController::class, 'show'])->name('returns.show');
        Route::post('/returns/{sellerRequest}/confirm', [BuyerReturnRefundController::class, 'confirm'])->name('returns.confirm');
        Route::post('/returns/cash-refunds/{obligation}/confirm', [BuyerReturnRefundController::class, 'confirmCash'])->name('returns.cash-confirm');
        Route::post('/returns/{sellerRequest}/escalate', [BuyerReturnRefundController::class, 'escalate'])->name('returns.escalate');
        Route::get('/returns/payouts/{payout}/proof', [BuyerReturnRefundController::class, 'payoutProof'])->name('returns.payout-proof');
        Route::get('/returns/evidence/{evidence}', [BuyerReturnRefundController::class, 'evidence'])->name('returns.evidence');
        Route::get('/orders/{order}', [BuyerOrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/delivery-pin', [BuyerOrderController::class, 'confirmDeliveryPin'])->name('orders.delivery-pin');
        Route::post('/orders/{order}/seller-orders/{sellerOrder}/conversation', [BuyerOrderController::class, 'sellerConversation'])->name('orders.seller-conversation');
        Route::post('/orders/{order}/support/conversation', [BuyerOrderController::class, 'supportConversation'])->name('orders.support-conversation');
        Route::post('/orders/{order}/cancel', [BuyerOrderController::class, 'cancel'])->middleware('throttle:10,1')->name('orders.cancel');
        Route::post('/orders/{order}/shipments/{shipment}/received', [BuyerOrderController::class, 'receivedShipment'])->name('orders.shipments.received');
        Route::post('/orders/{order}/received', [BuyerOrderController::class, 'received'])->name('orders.received');
        Route::get('/orders/{order}/return-refund', [BuyerOrderController::class, 'returnRefundForm'])->name('orders.return-refund.form');
        Route::post('/orders/{order}/return-refund', [BuyerOrderController::class, 'returnRefund'])->name('orders.return-refund');
        Route::post('/orders/items/{item}/review', [BuyerOrderController::class, 'review'])->name('orders.review.store');

        Route::get('/messages', [BuyerController::class, 'messages'])->name('messages');
        Route::get('/messages/stream', [BuyerController::class, 'messageStream'])->name('messages.stream');
        Route::post('/messages', [BuyerController::class, 'sendMessage'])->name('messages.send');
        Route::post('/messages/{conversation}/smart-help/answer', [SmartMessagingController::class, 'answer'])
            ->whereNumber('conversation')->middleware('throttle:120,1')->name('messages.smart-help.answer');
        Route::post('/messages/{conversation}/smart-help/feedback', [SmartMessagingController::class, 'feedback'])
            ->whereNumber('conversation')->middleware('throttle:60,1')->name('messages.smart-help.feedback');
        Route::post('/messages/{conversation}/smart-help/handoff', [SmartMessagingController::class, 'handoff'])
            ->whereNumber('conversation')->middleware('throttle:20,1')->name('messages.smart-help.handoff');

        Route::get('/notifications', [BuyerController::class, 'notifications'])->name('notifications');
        Route::get('/notifications/{notification}/open', [BuyerController::class, 'openNotification'])->name('notifications.open');
        Route::get('/notifications/stream', [BuyerController::class, 'notificationStream'])->name('notifications.stream');
        Route::post('/notifications/read-all', [BuyerController::class, 'markNotificationsRead'])->name('notifications.read-all');
        Route::get('/rewards', [BuyerController::class, 'rewards'])->name('rewards');
        Route::post('/vouchers/{voucher}/claim', [BuyerVoucherClaimController::class, 'store'])->name('vouchers.claim');

        Route::get('/account', [BuyerController::class, 'account'])->name('account');
        Route::get('/settings', [BuyerController::class, 'settings'])->name('settings');
        Route::put('/settings', [BuyerController::class, 'updateSettings'])->name('settings.update');
        Route::patch('/settings/theme', [BuyerController::class, 'updateThemePreference'])->name('settings.theme.update');
        Route::patch('/settings/ai-preferences', [BuyerController::class, 'updateAiPreference'])->name('settings.ai.update');
        Route::get('/account/profile', fn () => redirect()->route('buyer.account', ['tab' => 'profile']))->name('account.profile');
        Route::get('/account/addresses', fn () => redirect()->route('buyer.account', ['tab' => 'addresses']))->name('account.addresses');
        Route::get('/account/security', fn () => redirect()->route('buyer.account', ['tab' => 'password']))->name('account.security');
        Route::get('/account/reviews', fn () => redirect()->route('buyer.account', ['tab' => 'reviews']))->name('account.reviews');
        Route::put('/account/profile', [BuyerController::class, 'saveProfile'])->middleware('throttle:10,1')->name('account.profile.update');
        Route::post('/account/email/verify', [BuyerController::class, 'verifyEmailChange'])->middleware('throttle:10,1')->name('account.email.verify');
        Route::post('/account/email/resend', [BuyerController::class, 'resendEmailChangeCode'])->middleware('throttle:3,1')->name('account.email.resend');
        Route::post('/account/mobile/resend', [BuyerController::class, 'resendMobileChangeCode'])->middleware('throttle:3,1')->name('account.mobile.resend');
        Route::put('/account/password', [BuyerController::class, 'savePassword'])->middleware('throttle:5,1')->name('account.password.update');
        Route::post('/account/addresses', [BuyerController::class, 'saveAddress'])->name('account.addresses.store');
        Route::patch('/account/addresses/default', [BuyerController::class, 'setDefaultAddress'])->name('account.addresses.default');
        Route::put('/account/addresses/{address}', [BuyerController::class, 'updateAddress'])->name('account.addresses.update');
        Route::delete('/account/addresses/{address}', [BuyerController::class, 'deleteAddress'])->name('account.addresses.destroy');
        Route::delete('/account', [BuyerController::class, 'destroyAccount'])->name('account.destroy');
    });
