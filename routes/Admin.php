<?php

use App\Http\Controllers\Admin\AdminCashRefundController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFinanceController;
use App\Http\Controllers\Admin\AdminMarketingController;
use App\Http\Controllers\Admin\AdminOnboardingController;
use App\Http\Controllers\Admin\AdminOperationsController;
use App\Http\Controllers\Admin\AdminProductModerationController;
use App\Http\Controllers\Admin\AdminProtectionRuleController;
use App\Http\Controllers\Admin\AdminRoutingReviewController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\SystemSettingsMonitoringController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnsureWorkspaceRole;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', EnsureWorkspaceRole::class.':admin'])
    ->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/routing-review', [AdminRoutingReviewController::class, 'index'])->name('routing-review');
        Route::post('/routing-review/{shipment}/resolve', [AdminRoutingReviewController::class, 'resolve'])->name('routing-review.resolve');

        /*
        |--------------------------------------------------------------------------
        | Registration Applications
        |--------------------------------------------------------------------------
        |
        | Buyer, Seller, and Logistics registrations are reviewed by Admin.
        | Rider registrations are reviewed by the rider's selected logistics center.
        |
        */
        Route::get('/registrations', [AdminOnboardingController::class, 'index'])->name('registrations');
        Route::get('/registrations/{application}', [AdminOnboardingController::class, 'show'])->name('registrations.show');
        Route::post('/registrations/{application}/approve', [AdminOnboardingController::class, 'approve'])->name('registrations.approve');
        Route::post('/registrations/{application}/reject', [AdminOnboardingController::class, 'reject'])->name('registrations.reject');
        Route::get('/registrations/{application}/documents/{document}', [AdminOnboardingController::class, 'document'])
            ->name('registrations.documents.show');
        Route::post('/registrations/{application}/documents/{document}/verify', [AdminOnboardingController::class, 'verifyDocument'])
            ->name('registrations.documents.verify');
        Route::post('/registrations/{application}/documents/{document}/reject', [AdminOnboardingController::class, 'rejectDocument'])
            ->name('registrations.documents.reject');

        // Compatibility links retained for the existing navigation/UI.
        Route::get('/sellers', [AdminOnboardingController::class, 'sellers'])->name('sellers.index');
        Route::get('/sellers/approvals', [AdminOnboardingController::class, 'sellers'])->name('sellers.approvals');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::post('/users/{user}/warnings', [AdminUserController::class, 'issueWarning'])->name('users.warnings.store');
        Route::post('/users/{user}/warnings/{warning}/review', [AdminUserController::class, 'reviewWarning'])->name('users.warnings.review');
        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->name('users.reactivate');

        Route::get('/products', [AdminOperationsController::class, 'products'])->name('products');
        Route::get('/marketing', [AdminMarketingController::class, 'index'])->name('marketing');
        Route::post('/marketing/campaigns', [AdminMarketingController::class, 'storeCampaign'])->name('marketing.campaigns.store');
        Route::patch('/marketing/campaigns/{voucher}', [AdminMarketingController::class, 'updateCampaign'])->name('marketing.campaigns.update');
        Route::patch('/marketing/campaigns/{voucher}/toggle', [AdminMarketingController::class, 'toggleCampaign'])->name('marketing.campaigns.toggle');
        Route::post('/marketing/announcements', [AdminMarketingController::class, 'storeAnnouncement'])->name('marketing.announcements.store');
        Route::patch('/marketing/announcements/{announcement}/toggle', [AdminMarketingController::class, 'toggleAnnouncement'])->name('marketing.announcements.toggle');
        Route::get('/compliance', [AdminOperationsController::class, 'compliance'])->name('compliance');
        Route::get('/compliance-export', [AdminOperationsController::class, 'exportCompliance'])->name('compliance.export');
        Route::get('/compliance/{case}', [AdminOperationsController::class, 'showComplianceCase'])->name('compliance.show');
        Route::get('/compliance/{case}/responses/{response}/evidence', [AdminOperationsController::class, 'downloadComplianceResponseEvidence'])
            ->name('compliance.responses.evidence');
        Route::patch('/compliance/{case}', [AdminOperationsController::class, 'updateComplianceCase'])->name('compliance.update');
        Route::get('/complaints', [AdminOperationsController::class, 'complaints'])->name('complaints');
        Route::get('/finance', [AdminOperationsController::class, 'finance'])->name('finance');
        Route::get('/protection-rules', [AdminProtectionRuleController::class, 'index'])->name('protection-rules');
        Route::post('/protection-rules', [AdminProtectionRuleController::class, 'store'])->name('protection-rules.store');
        Route::patch('/protection-rules/{protectionRule}', [AdminProtectionRuleController::class, 'update'])->name('protection-rules.update');
        Route::patch('/protection-rules/{protectionRule}/toggle', [AdminProtectionRuleController::class, 'toggle'])->name('protection-rules.toggle');
        Route::post('/finance/seller-settlements/{settlement}/authorize', [AdminFinanceController::class, 'authorizeSellerSettlement'])->name('finance.seller-settlements.authorize');
        Route::post('/finance/seller-settlements/{settlement}/pay', [AdminFinanceController::class, 'paySeller'])->name('finance.seller-settlements.pay');
        Route::post('/cash-refunds/{obligation}/paid', [AdminCashRefundController::class, 'markPaid'])->name('cash-refunds.paid');
        Route::get('/reports', [AdminOperationsController::class, 'reports'])->name('reports');
        Route::get('/messages', [AdminOperationsController::class, 'messages'])->name('messages');
        Route::get('/messages/stream', [AdminOperationsController::class, 'messageStream'])->name('messages.stream');
        Route::get('/settings', [AdminOperationsController::class, 'settings'])->name('settings');
        Route::get('/system-settings', [SystemSettingsMonitoringController::class, 'show'])
            ->defaults('workspace', 'admin')->middleware('throttle:20,1')->name('system-settings');
        Route::get('/system-settings/errors', [SystemSettingsMonitoringController::class, 'errors'])
            ->middleware('throttle:20,1')->name('system-settings.errors');
        Route::get('/system-settings/logs', [SystemSettingsMonitoringController::class, 'logs'])
            ->middleware('throttle:20,1')->name('system-settings.logs');
        Route::get('/system-settings/audit-logs', [SystemSettingsMonitoringController::class, 'auditLogs'])
            ->middleware('throttle:20,1')->name('system-settings.audit-logs');
        Route::post('/system-settings/storage/refresh', [SystemSettingsMonitoringController::class, 'refreshStorage'])
            ->middleware('throttle:1,1')->name('system-settings.storage.refresh');
        Route::patch('/system-settings/product-moderation', [SystemSettingsMonitoringController::class, 'updateProductModeration'])
            ->middleware('throttle:10,1')->name('system-settings.product-moderation.update');
        Route::put('/system-settings', [WorkspaceSettingsController::class, 'update'])->defaults('workspace', 'admin')->name('system-settings.update');
        Route::get('/account', [AdminOperationsController::class, 'account'])->name('account');
        Route::get('/notifications', [AdminOperationsController::class, 'notifications'])->name('notifications');
        Route::post('/notifications/read-all', [AdminOperationsController::class, 'markNotificationsRead'])->name('notifications.read-all');

        Route::post('/products/{product}/moderate', [AdminOperationsController::class, 'moderateProduct'])->name('products.moderate');
        Route::post('/products/{product}/ai/rescan', [AdminProductModerationController::class, 'rescan'])
            ->middleware('throttle:5,1')->name('products.ai.rescan');
        Route::post('/products/{product}/ai/scans/{scan}/review', [AdminProductModerationController::class, 'review'])
            ->middleware('throttle:10,1')->name('products.ai.review');
        Route::post('/products/{product}/ai/scans/{scan}/appeal-review', [AdminProductModerationController::class, 'reviewAppeal'])
            ->middleware('throttle:10,1')->name('products.ai.appeals.review');
        Route::get('/products/{product}/ai/scans/{scan}/appeal-evidence', [AdminProductModerationController::class, 'appealEvidence'])
            ->middleware('throttle:20,1')->name('products.ai.appeals.evidence');
        Route::post('/products/{product}/warn', [AdminOperationsController::class, 'warnProduct'])->name('products.warn');
        Route::post('/categories', [AdminOperationsController::class, 'storeCategory'])->name('categories.store');
        Route::patch('/categories/{category}/status', [AdminOperationsController::class, 'updateCategoryStatus'])->name('categories.status');
        Route::patch('/categories/{category}/archive', [AdminOperationsController::class, 'archiveCategory'])->name('categories.archive');
        Route::patch('/categories/{category}/restore', [AdminOperationsController::class, 'restoreCategory'])->name('categories.restore');
        Route::post('/subcategories', [AdminOperationsController::class, 'storeSubcategory'])->name('subcategories.store');
        Route::patch('/subcategories/{category}/status', [AdminOperationsController::class, 'updateSubcategoryStatus'])->name('subcategories.status');
        Route::patch('/subcategories/{category}/archive', [AdminOperationsController::class, 'archiveCategory'])->name('subcategories.archive');
        Route::patch('/subcategories/{category}/restore', [AdminOperationsController::class, 'restoreCategory'])->name('subcategories.restore');
        Route::get('/products-export', [AdminOperationsController::class, 'exportProducts'])->name('products.export');
        Route::get('/products/{product}', [AdminOperationsController::class, 'productDetails'])->name('products.show');
        Route::get('/disputes/{dispute}', [AdminOperationsController::class, 'showDispute'])->name('disputes.show');
        Route::patch('/disputes/{dispute}', [AdminOperationsController::class, 'updateDispute'])->name('disputes.update');
        Route::get('/disputes/payouts/{payout}/proof', [AdminOperationsController::class, 'refundPayoutProof'])->name('disputes.payout-proof');
        Route::get('/disputes/refund-evidence/{evidence}', [AdminOperationsController::class, 'refundEvidence'])->name('disputes.refund-evidence');
        Route::get('/finance-export', [AdminOperationsController::class, 'exportFinance'])->name('finance.export');
        Route::get('/reports-export', [AdminOperationsController::class, 'exportReport'])->name('reports.export');
        Route::post('/messages/send', [AdminOperationsController::class, 'sendMessage'])->name('messages.send');
        Route::post('/announcements', [AdminMarketingController::class, 'storeAnnouncement'])->name('announcements.store');
        Route::patch('/settings', [AdminOperationsController::class, 'updateSettings'])->name('settings.update');
        Route::post('/policies', [AdminOperationsController::class, 'storePolicy'])->name('policies.store');
        Route::patch('/policies/{policy}', [AdminOperationsController::class, 'updatePolicy'])->name('policies.update');
        Route::get('/audit-export', [AdminOperationsController::class, 'exportAuditLogs'])->name('audit.export');
        Route::patch('/account', [AdminOperationsController::class, 'updateAccount'])->name('account.update');
        Route::patch('/account/password', [AdminOperationsController::class, 'updatePassword'])->name('account.password');
        Route::patch('/account/preferences', [AdminOperationsController::class, 'updatePreferences'])->name('account.preferences');
        Route::post('/notifications/{notification}/read', [AdminOperationsController::class, 'markNotificationRead'])->name('notifications.read');

        // Compatibility aliases retained for the original Admin frontend.
        Route::get('/riders', fn () => redirect()->route('admin.users'))->name('riders');
        Route::get('/categories', [AdminOperationsController::class, 'categories'])->name('categories');
        Route::get('/payments', fn () => redirect()->route('admin.finance', ['tab' => 'payments']))->name('payments');
        Route::get('/refunds', fn () => redirect()->route('admin.complaints', ['tab' => 'returns']))->name('refunds');
    });
