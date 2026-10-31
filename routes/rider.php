<?php

use App\Http\Controllers\Rider\RiderController;
use App\Http\Controllers\Communication\SmartMessagingController;
use App\Http\Controllers\Rider\RiderFinanceController;
use App\Http\Controllers\Finance\PayoutProofController;
use App\Http\Controllers\Rider\RiderPickupBatchController;
use App\Http\Controllers\Rider\RiderParcelScanController;
use App\Http\Controllers\Rider\RiderRemittanceController;
use App\Http\Controllers\Rider\RiderReturnWorkflowController;
use App\Http\Controllers\Rider\RiderShipmentController;
use App\Http\Controllers\Rider\RiderTripController;
use App\Http\Controllers\Rider\RiderTransferController;
use App\Http\Controllers\Logistics\InterhubTimekeepingController;
use App\Http\Controllers\WorkspaceAiController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnsureWorkspaceRole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rider / Courier Workspace
|--------------------------------------------------------------------------
|
| Rider and Courier are the same workspace role. All pickup, sorting-center
| handoff, delivery, history, earnings, messaging, and account routes live
| here under the single canonical `rider.*` route namespace.
|
*/

Route::prefix('rider')
    ->name('rider.')
    ->middleware(['auth', 'verified', EnsureWorkspaceRole::class.':rider', 'rider.active'])
    ->group(function (): void {
        Route::post('/ai/chat', [WorkspaceAiController::class, 'chat'])
            ->defaults('workspace', 'rider')
            ->middleware('throttle:20,1')
            ->name('ai.chat');

        Route::get('/', fn () => redirect()->route('rider.dashboard'))->name('home');
        Route::get('/dashboard', [RiderController::class, 'dashboard'])->name('dashboard');
        Route::get('/scan', [RiderParcelScanController::class, 'resolve'])->name('scan');
        Route::get('/settings', [WorkspaceSettingsController::class, 'show'])->defaults('workspace', 'rider')->name('settings');
        Route::put('/settings', [WorkspaceSettingsController::class, 'update'])->defaults('workspace', 'rider')->name('settings.update');

        Route::middleware('rider.operational:LOCAL_BARANGAY')->group(function (): void {
            Route::patch('/availability', [RiderController::class, 'updateAvailability'])->name('availability.update');
            Route::post('/availability/location', [RiderController::class, 'updateAvailabilityLocation'])->name('availability.location.update');
            Route::get('/shipments', [RiderShipmentController::class, 'index'])->name('shipments');
            Route::get('/returns', [RiderReturnWorkflowController::class, 'index'])->name('returns');
            Route::post('/returns/{workflow}/center-handoff', [RiderReturnWorkflowController::class, 'centerHandoff'])->whereNumber('workflow')->name('returns.center-handoff');
            Route::post('/returns/{workflow}/accept', [RiderReturnWorkflowController::class, 'accept'])->whereNumber('workflow')->name('returns.accept');
            Route::post('/returns/{workflow}/pickup', [RiderReturnWorkflowController::class, 'pickup'])->whereNumber('workflow')->name('returns.pickup');
            Route::post('/returns/{workflow}/seller-handoff', [RiderReturnWorkflowController::class, 'sellerHandoff'])->whereNumber('workflow')->name('returns.seller-handoff');
            Route::post('/returns/{workflow}/handoff/from', [RiderReturnWorkflowController::class, 'handoffFrom'])->whereNumber('workflow')->name('returns.handoff.from');
            Route::post('/returns/{workflow}/handoff/to', [RiderReturnWorkflowController::class, 'handoffTo'])->whereNumber('workflow')->name('returns.handoff.to');
            Route::get('/trips', [RiderTripController::class, 'index'])->name('trips');
            Route::get('/trips/{trip}', [RiderTripController::class, 'show'])->name('trips.show');
            Route::post('/trips/{trip}/accept', [RiderTripController::class, 'accept'])->name('trips.accept');
            Route::post('/trips/{trip}/reject', [RiderTripController::class, 'reject'])->name('trips.reject');
            Route::post('/trips/{trip}/load', [RiderTripController::class, 'load'])->name('trips.load');
            Route::post('/trips/{trip}/start', [RiderTripController::class, 'start'])->name('trips.start');
            Route::post('/assignments/{assignment}/location', [RiderShipmentController::class, 'updateLocation'])->name('assignments.location');
            Route::patch('/assignments/{assignment}', [RiderShipmentController::class, 'transition'])->name('assignments.transition');
            Route::patch('/shipments/{assignment}', [RiderShipmentController::class, 'transition'])->name('shipments.transition');

            Route::get('/parcels', [RiderController::class, 'shipments'])->name('parcels.index');
            Route::get('/parcels/{assignment}', [RiderController::class, 'pickupShow'])->name('parcels.show');
            Route::get('/pickups', [RiderPickupBatchController::class, 'index'])->name('pickups');
            Route::get('/pickups/scan', [RiderPickupBatchController::class, 'index'])->name('pickups.scan');
            Route::get('/pickups/batches/{trip}', [RiderPickupBatchController::class, 'show'])->name('pickups.batches.show');
            Route::post('/pickups/batches/{trip}/accept', [RiderPickupBatchController::class, 'accept'])->name('pickups.batches.accept');
            Route::post('/pickups/batches/{trip}/start', [RiderPickupBatchController::class, 'start'])->name('pickups.batches.start');
            Route::post('/pickups/batches/{trip}/scan', [RiderPickupBatchController::class, 'scan'])->name('pickups.batches.scan');
            Route::post('/pickups/batches/{trip}/not-collected', [RiderPickupBatchController::class, 'notCollected'])->name('pickups.batches.not-collected');
            Route::post('/pickups/batches/{trip}/finish', [RiderPickupBatchController::class, 'finish'])->name('pickups.batches.finish');
            Route::get('/pickups/{assignment}', [RiderController::class, 'pickupShow'])->name('pickups.show');
            Route::get('/deliveries', [RiderController::class, 'deliveries'])->name('deliveries');
            Route::get('/deliveries/{assignment}', [RiderController::class, 'deliveryShow'])->name('deliveries.show');
            Route::get('/history', [RiderController::class, 'history'])->name('history');
        });

        Route::middleware('rider.operational:INTER_LOGISTICS')->group(function (): void {
            Route::get('/attendance/qr', [InterhubTimekeepingController::class, 'driverQr'])->name('attendance.qr');
            Route::post('/attendance/scan', [InterhubTimekeepingController::class, 'scanByDriver'])->middleware('throttle:30,1')->name('attendance.scan');
            Route::get('/attendance/payroll-items/{item}/proof', [InterhubTimekeepingController::class, 'driverProof'])->whereNumber('item')->name('attendance.payroll-items.proof');
            Route::get('/transfers', [RiderTransferController::class, 'index'])->name('transfers');
            Route::get('/transfers/{manifest}', [RiderTransferController::class, 'show'])->name('transfers.show');
            Route::post('/transfers/{manifest}/accept', [RiderTransferController::class, 'accept'])->name('transfers.accept');
            Route::post('/transfers/{manifest}/decline', [RiderTransferController::class, 'decline'])->name('transfers.decline');
            Route::post('/transfers/{manifest}/start', [RiderTransferController::class, 'start'])->name('transfers.start');
        });

        Route::get('/earnings', [RiderController::class, 'earnings'])->name('earnings');
        Route::get('/wallet', [RiderController::class, 'earnings'])->name('wallet');
        Route::put('/wallet/payout-destination', [RiderFinanceController::class, 'updatePayoutDestination'])->name('wallet.payout-destination.update');
        Route::post('/earnings/payout-requests', [RiderFinanceController::class, 'requestPayout'])->name('earnings.payout-requests.store');
        Route::post('/earnings/payouts/{entry}/acknowledge', [RiderFinanceController::class, 'acknowledgePayout'])->whereNumber('entry')->name('earnings.payouts.acknowledge');
        Route::get('/earnings/payouts/{entry}/proof', [PayoutProofController::class, 'rider'])->whereNumber('entry')->name('earnings.payout-proof');
        Route::get('/remittances', [RiderRemittanceController::class, 'index'])->name('remittances');
        Route::post('/remittances', [RiderRemittanceController::class, 'store'])->name('remittances.store');
        Route::get('/profile', [RiderController::class, 'profile'])->name('profile');
        Route::get('/account', [RiderController::class, 'profile'])->name('account');
        Route::patch('/account/profile', [RiderController::class, 'updateAccount'])->name('account.profile.update');
        Route::post('/account/profile/updates', [RiderController::class, 'submitProfileUpdate'])->name('account.profile.updates.store');
        Route::patch('/account/address', [RiderController::class, 'updateResidentialAddress'])->name('account.address.update');
        Route::patch('/account/password', [RiderController::class, 'updatePassword'])->name('account.password.update');
        Route::get('/messages', [RiderController::class, 'messages'])->name('messages');
        Route::get('/messages/stream', [RiderController::class, 'messageStream'])->name('messages.stream');
        Route::post('/messages', [RiderController::class, 'sendMessage'])->name('messages.send');
        Route::post('/messages/{conversation}/smart-help/answer', [SmartMessagingController::class, 'answer'])->whereNumber('conversation')->middleware('throttle:120,1')->name('messages.smart-help.answer');
        Route::post('/messages/{conversation}/smart-help/feedback', [SmartMessagingController::class, 'feedback'])->whereNumber('conversation')->middleware('throttle:60,1')->name('messages.smart-help.feedback');
        Route::post('/messages/{conversation}/smart-help/handoff', [SmartMessagingController::class, 'handoff'])->whereNumber('conversation')->middleware('throttle:20,1')->name('messages.smart-help.handoff');
    });
