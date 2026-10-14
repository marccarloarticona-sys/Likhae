<?php

use App\Http\Controllers\Rider\RiderController;
use App\Http\Controllers\Rider\RiderPickupBatchController;
use App\Http\Controllers\Rider\RiderRemittanceController;
use App\Http\Controllers\Rider\RiderShipmentController;
use App\Http\Controllers\Rider\RiderTripController;
use App\Http\Controllers\Rider\RiderTransferController;
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
        Route::get('/settings', [WorkspaceSettingsController::class, 'show'])->defaults('workspace', 'rider')->name('settings');
        Route::put('/settings', [WorkspaceSettingsController::class, 'update'])->defaults('workspace', 'rider')->name('settings.update');

        Route::middleware('rider.operational:LOCAL_BARANGAY')->group(function (): void {
            Route::get('/shipments', [RiderShipmentController::class, 'index'])->name('shipments');
            Route::get('/trips', [RiderTripController::class, 'index'])->name('trips');
            Route::get('/trips/{trip}', [RiderTripController::class, 'show'])->name('trips.show');
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
            Route::get('/transfers', [RiderTransferController::class, 'index'])->name('transfers');
            Route::get('/transfers/{manifest}', [RiderTransferController::class, 'show'])->name('transfers.show');
            Route::post('/transfers/{manifest}/accept', [RiderTransferController::class, 'accept'])->name('transfers.accept');
            Route::post('/transfers/{manifest}/start', [RiderTransferController::class, 'start'])->name('transfers.start');
        });

        Route::get('/earnings', [RiderController::class, 'earnings'])->name('earnings');
        Route::get('/remittances', [RiderRemittanceController::class, 'index'])->name('remittances');
        Route::post('/remittances', [RiderRemittanceController::class, 'store'])->name('remittances.store');
        Route::get('/profile', [RiderController::class, 'profile'])->name('profile');
        Route::get('/account', [RiderController::class, 'profile'])->name('account');
        Route::patch('/account/profile', [RiderController::class, 'updateAccount'])->name('account.profile.update');
        Route::patch('/account/address', [RiderController::class, 'updateResidentialAddress'])->name('account.address.update');
        Route::patch('/account/password', [RiderController::class, 'updatePassword'])->name('account.password.update');
        Route::get('/messages', [RiderController::class, 'messages'])->name('messages');
        Route::get('/messages/stream', [RiderController::class, 'messageStream'])->name('messages.stream');
        Route::post('/messages', [RiderController::class, 'sendMessage'])->name('messages.send');
    });
