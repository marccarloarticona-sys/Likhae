<?php

use App\Http\Controllers\Seller\SellerAccountController;
use App\Http\Controllers\Seller\SellerComplianceController;
use App\Http\Controllers\Seller\SellerEngagementController;
use App\Http\Controllers\Seller\SellerOperationsController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\SellerPickupRequestController;
use App\Http\Controllers\Seller\SellerReturnRefundController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\WorkspaceAiController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnsureWorkspaceRole;
use Illuminate\Support\Facades\Route;

Route::get('/seller', fn () => redirect()->route('seller.dashboard'))
    ->middleware(['auth', 'verified', EnsureWorkspaceRole::class.':seller'])
    ->name('seller.entry');

Route::prefix('seller')
    ->name('seller.')
    ->middleware(['auth', 'verified', EnsureWorkspaceRole::class.':seller', 'seller.approved'])
    ->group(function (): void {
        Route::post('/ai/chat', [WorkspaceAiController::class, 'chat'])
            ->defaults('workspace', 'seller')
            ->middleware('throttle:20,1')
            ->name('ai.chat');

        Route::get('/dashboard', [SellerOperationsController::class, 'dashboard'])->name('dashboard');
        Route::get('/settings', [WorkspaceSettingsController::class, 'show'])->defaults('workspace', 'seller')->name('settings');
        Route::put('/settings', [WorkspaceSettingsController::class, 'update'])->defaults('workspace', 'seller')->name('settings.update');

        Route::get('/products', [SellerProductController::class, 'index'])->name('products');
        Route::get('/products/inventory/preview', [SellerProductController::class, 'inventoryPreview'])->name('products.inventory.preview');
        Route::get('/products/inventory/download', [SellerProductController::class, 'downloadInventoryPdf'])->name('products.inventory.download');
        Route::get('/products/trash', [SellerProductController::class, 'trash'])->name('products.trash');
        Route::post('/products/trash/{productId}/restore', [SellerProductController::class, 'restore'])->name('products.restore');
        Route::post('/products', [SellerProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [SellerProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}/stock', [SellerProductController::class, 'stock'])->name('products.stock');
        Route::patch('/products/{product}/variants/{variant}/stock', [SellerProductController::class, 'variantStock'])->name('products.variants.stock');
        Route::patch('/products/{product}/toggle', [SellerProductController::class, 'toggle'])->name('products.toggle');
        Route::delete('/products/{product}', [SellerProductController::class, 'destroy'])->name('products.destroy');
        Route::delete('/products/{product}/images/{image}', [SellerProductController::class, 'deleteImage'])->name('products.images.destroy');
        Route::get('/products-export', [SellerProductController::class, 'export'])->name('products.export');

        // Product compliance notices are scoped to the authenticated Seller;
        // they are linked from Product Management and Notifications rather
        // than added as a separate primary sidebar section.
        Route::get('/compliance', [SellerComplianceController::class, 'index'])->name('compliance');
        Route::get('/compliance/{case}', [SellerComplianceController::class, 'show'])->name('compliance.show');
        Route::post('/compliance/{case}/responses', [SellerComplianceController::class, 'storeResponse'])->name('compliance.responses.store');
        Route::post('/compliance/{case}/moderation-scans/{scan}/appeal', [SellerComplianceController::class, 'submitModerationAppeal'])
            ->middleware('throttle:5,60')->name('compliance.moderation-scans.appeal');
        Route::post('/compliance/{case}/moderation-scans/{scan}/appeal-clarification', [SellerComplianceController::class, 'submitModerationClarification'])
            ->middleware('throttle:5,60')->name('compliance.moderation-scans.appeal-clarification');
        Route::get('/compliance/{case}/moderation-scans/{scan}/appeal-evidence', [SellerComplianceController::class, 'downloadModerationAppealEvidence'])
            ->middleware('throttle:20,1')->name('compliance.moderation-scans.appeal-evidence');
        Route::get('/compliance/{case}/responses/{response}/evidence', [SellerComplianceController::class, 'downloadResponseEvidence'])
            ->name('compliance.responses.evidence');

        Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders');
        Route::get('/orders/pickups/{trip}/progress', [SellerOrderController::class, 'pickupProgress'])
            ->whereNumber('trip')
            ->name('orders.pickups.progress');
        Route::get('/orders/refunds/{sellerRequest}', [SellerReturnRefundController::class, 'show'])->name('orders.refunds.show');
        Route::post('/orders/refunds/{sellerRequest}/approve', [SellerReturnRefundController::class, 'approve'])->name('orders.refunds.approve');
        Route::post('/orders/refunds/{sellerRequest}/reject', [SellerReturnRefundController::class, 'reject'])->name('orders.refunds.reject');
        Route::post('/orders/refunds/{sellerRequest}/payout', [SellerReturnRefundController::class, 'payout'])->name('orders.refunds.payout');
        Route::post('/orders/refunds/{sellerRequest}/cash-ready', [SellerReturnRefundController::class, 'cashReady'])->name('orders.refunds.cash-ready');
        Route::post('/orders/refunds/{sellerRequest}/receive', [SellerReturnRefundController::class, 'receiveReturn'])->name('orders.refunds.receive');
        Route::post('/orders/refunds/{sellerRequest}/items/{item}/inspect', [SellerReturnRefundController::class, 'inspectReturn'])->name('orders.refunds.inspect');
        Route::get('/orders/refunds/payouts/{payout}/proof', [SellerReturnRefundController::class, 'payoutProof'])->name('orders.refunds.payout-proof');
        Route::get('/orders/refunds/evidence/{evidence}', [SellerReturnRefundController::class, 'evidence'])->name('orders.refunds.evidence');
        Route::patch('/orders/{sellerOrder}/status', [SellerOrderController::class, 'transition'])->name('orders.status');
        Route::patch('/orders/{sellerOrder}/items/{orderItem}/measurements', [SellerOrderController::class, 'recordParcelMeasurements'])->name('orders.parcels.measurements');
        Route::post('/orders/{sellerOrder}/parcels', [SellerOrderController::class, 'ensureParcels'])->name('orders.parcels.ensure');
        Route::get('/orders/{sellerOrder}/waybill', [SellerOperationsController::class, 'waybill'])->name('orders.waybill');
        Route::get('/orders/{sellerOrder}/items/{orderItem}/waybill', [SellerOperationsController::class, 'waybillItem'])->name('orders.waybill.item');
        Route::get('/orders-export', [SellerOperationsController::class, 'exportOrders'])->name('orders.export');

        Route::get('/logistics', [SellerOperationsController::class, 'logistics'])->name('logistics');
        Route::post('/logistics/pickup-requests', [SellerPickupRequestController::class, 'store'])->name('logistics.pickup-requests.store');
        Route::post('/logistics/pickup-requests/{pickupRequest}/cancel', [SellerPickupRequestController::class, 'cancel'])
            ->whereNumber('pickupRequest')->name('logistics.pickup-requests.cancel');
        Route::get('/logistics/pickup-requests/{pickupRequest}/progress', [SellerPickupRequestController::class, 'progress'])
            ->whereNumber('pickupRequest')->name('logistics.pickup-requests.progress');
        Route::post('/logistics/{sellerOrder}/pickup', [SellerOrderController::class, 'handover'])->name('logistics.pickup');

        Route::get('/messages', [SellerEngagementController::class, 'messages'])->name('messages');
        Route::get('/messages/stream', [SellerEngagementController::class, 'stream'])->name('messages.stream');
        Route::post('/messages/{conversation}/read', [SellerEngagementController::class, 'markConversationRead'])
            ->whereNumber('conversation')
            ->name('messages.read');
        Route::get('/messages/{message}/attachment', [SellerEngagementController::class, 'messageAttachment'])->name('messages.attachments.show');
        Route::post('/messages', [SellerEngagementController::class, 'sendMessage'])->name('messages.send');

        Route::get('/reviews', [SellerEngagementController::class, 'reviews'])->name('reviews');
        Route::post('/reviews/{review}/reply', [SellerEngagementController::class, 'replyReview'])->name('reviews.reply');
        Route::delete('/reviews/{review}', [SellerEngagementController::class, 'denyReviewModeration'])->name('reviews.destroy');
        Route::patch('/reviews/{review}/hide', [SellerEngagementController::class, 'denyReviewModeration'])->name('reviews.hide');
        Route::get('/reviews-export', [SellerEngagementController::class, 'exportReviews'])->name('reviews.export');

        Route::get('/marketing', [SellerEngagementController::class, 'marketing'])->name('marketing');
        Route::post('/marketing/campaigns', [SellerEngagementController::class, 'storeCampaign'])->name('marketing.campaigns.store');
        Route::patch('/marketing/campaigns/{voucher}', [SellerEngagementController::class, 'updateCampaign'])->name('marketing.campaigns.update');
        Route::patch('/marketing/campaigns/{voucher}/toggle', [SellerEngagementController::class, 'toggleCampaign'])->name('marketing.campaigns.toggle');

        Route::get('/reports', [SellerOperationsController::class, 'reports'])->name('reports');
        Route::get('/reports/download', [SellerOperationsController::class, 'downloadReport'])->name('reports.download');

        Route::get('/store', [SellerAccountController::class, 'store'])->name('store');
        Route::put('/store', [SellerAccountController::class, 'updateStore'])->name('store.update');

        Route::get('/account', [SellerAccountController::class, 'account'])->name('account');
        Route::put('/account/profile', [SellerAccountController::class, 'updateProfile'])->name('account.profile');
        Route::put('/account/business', [SellerAccountController::class, 'updateBusiness'])->name('account.business');
        Route::put('/account/password', [SellerAccountController::class, 'updatePassword'])->name('account.password');
        Route::put('/account/notifications', [SellerAccountController::class, 'updateNotifications'])->name('account.notifications');

        Route::get('/notifications', [SellerEngagementController::class, 'notifications'])->name('notifications');
        Route::post('/notifications/{notification}/open', [SellerEngagementController::class, 'openNotification'])->name('notifications.open');
        Route::post('/notifications/read-all', [SellerEngagementController::class, 'readAll'])->name('notifications.read-all');
    });
