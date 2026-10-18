<?php

use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\Logistics\DispatchController;
use App\Http\Controllers\Logistics\LogisticsPortalController;
use App\Http\Controllers\Logistics\LogisticsRemittanceController;
use App\Http\Controllers\Logistics\LogisticsShippingRateController;
use App\Http\Controllers\Logistics\RiderApplicationController;
use App\Http\Controllers\Logistics\LocalTripController;
use App\Http\Controllers\Logistics\TransferController;
use App\Http\Controllers\WorkspaceAiController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnsureWorkspaceRole;
use Illuminate\Support\Facades\Route;

Route::prefix('logistics')->name('logistics.')->group(function (): void {
    Route::get('/', fn () => view('Logistics.landing'))->name('home');

    Route::get('/login', function () {
        return view('auth.workspace-login', [
            'workspace' => 'logistics',
            'accountLabel' => 'Logistics & Rider Portal',
            'headline' => 'Coordinate every parcel movement.',
            'description' => 'Logistics centers manage intake and assignments. Riders manage only their own pickup and delivery work.',
            'homeRoute' => route('logistics.home'),
            'loginRoute' => route('logistics.login.store'),
            'registerRoute' => route('register', ['role' => 'logistics']),
        ]);
    })->middleware('guest')->name('login');

    Route::post('/login', [AuthenticationController::class, 'store'])->middleware(['guest', 'throttle:30,1'])->name('login.store');

    Route::middleware(['auth', 'verified', EnsureWorkspaceRole::class.':logistics', 'provider.approved'])->group(function (): void {
        Route::post('/ai/chat', [WorkspaceAiController::class, 'chat'])
            ->defaults('workspace', 'logistics')
            ->middleware('throttle:20,1')
            ->name('ai.chat');

        Route::get('/dashboard', [LogisticsPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/settings', [WorkspaceSettingsController::class, 'show'])->defaults('workspace', 'logistics')->name('settings');
        Route::put('/settings', [WorkspaceSettingsController::class, 'update'])->defaults('workspace', 'logistics')->name('settings.update');
        Route::get('/shipping-rate', [LogisticsShippingRateController::class, 'index'])->name('shipping-rate');
        Route::post('/shipping-rate-requests', [LogisticsShippingRateController::class, 'store'])->name('shipping-rate-requests.store');
        Route::post('/shipping-rate-requests/{shippingRateRequest}/cancel', [LogisticsShippingRateController::class, 'cancel'])
            ->whereNumber('shippingRateRequest')->name('shipping-rate-requests.cancel');

        Route::get('/parcels', [DispatchController::class, 'parcels'])->name('parcels');
        Route::get('/parcels/receive', [DispatchController::class, 'receive'])->name('parcels.receive');
        Route::post('/parcels/receive/scan', [DispatchController::class, 'receiveScanned'])->name('parcels.receive.scan');
        Route::post('/parcels/receive/{shipment}/confirm', [DispatchController::class, 'confirmReceive'])->name('parcels.receive.confirm');
        Route::get('/parcels/tracking', [DispatchController::class, 'tracking'])->name('parcels.tracking');
        Route::post('/parcels/{shipment}/status-override', [DispatchController::class, 'overrideShipmentStatus'])->name('parcels.status-override');
        Route::post('/parcels/{shipment}/exceptions', [DispatchController::class, 'reportShipmentException'])->name('parcels.exceptions.store');
        Route::get('/parcels/{shipment}', [DispatchController::class, 'show'])->name('parcels.show');

        Route::get('/pickups', [DispatchController::class, 'pickups'])->name('pickups');
        Route::post('/pickups/requests/{pickupRequest}/assign', [DispatchController::class, 'assignSellerPickupRequest'])
            ->whereNumber('pickupRequest')->name('pickups.requests.assign');
        Route::post('/pickups/requests/{pickupRequest}/reject', [DispatchController::class, 'rejectSellerPickupRequest'])
            ->whereNumber('pickupRequest')->name('pickups.requests.reject');
        Route::post('/pickups/assignments', [DispatchController::class, 'assignPickupBatch'])->name('pickups.assignments.store');
        Route::patch('/pickups/batches/{trip}/reassign', [DispatchController::class, 'reassignPickupBatch'])->name('pickups.batches.reassign');
        Route::post('/pickups/{pickupRequest}/approve', [DispatchController::class, 'approvePickup'])->name('pickups.approve');
        Route::post('/pickups/{pickupRequest}/reject', [DispatchController::class, 'rejectPickup'])->name('pickups.reject');
        Route::post('/pickups/{shipment}/auto-assign', [DispatchController::class, 'autoAssignPickup'])->name('pickups.auto-assign');
        Route::post('/pickups/{shipment}/assign', [DispatchController::class, 'assignPickup'])->name('pickups.assign');

        Route::get('/sorting', [DispatchController::class, 'sorting'])->name('sorting');
        Route::post('/sorting/{shipment}/sort', [DispatchController::class, 'sortParcel'])->name('sorting.sort');

        Route::get('/assignments', [DispatchController::class, 'assignments'])->name('assignments');
        Route::post('/assignments/{shipment}/assign', [DispatchController::class, 'assign'])->name('assignments.assign');
        Route::get('/dispatch', [DispatchController::class, 'assignments'])->name('dispatch');
        Route::post('/dispatch/{shipment}/assign', [DispatchController::class, 'assign'])->name('dispatch.assign');

        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{manifest}', [TransferController::class, 'show'])->name('transfers.show');
        Route::post('/transfers/{manifest}/items', [TransferController::class, 'addLeg'])->name('transfers.items.store');
        Route::patch('/transfers/{manifest}/items/{item}/measurements', [TransferController::class, 'recordItemMeasurements'])->name('transfers.items.measurements');
        Route::patch('/transfers/{manifest}/driver', [TransferController::class, 'assignDriver'])->name('transfers.driver.update');
        Route::post('/transfers/{manifest}/seal', [TransferController::class, 'seal'])->name('transfers.seal');
        Route::post('/transfers/{manifest}/items/{item}/release', [TransferController::class, 'releaseItem'])->name('transfers.items.release');
        Route::post('/transfers/{manifest}/release', [TransferController::class, 'release'])->name('transfers.release');
        Route::post('/transfers/{manifest}/depart', [TransferController::class, 'depart'])->name('transfers.depart');
        Route::post('/transfers/{manifest}/arrive', [TransferController::class, 'arrive'])->name('transfers.arrive');
        Route::post('/transfers/{manifest}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
        Route::post('/transfer-manifest-items/{item}/receive', [TransferController::class, 'receiveItem'])->name('transfers.items.receive');

        Route::get('/local-trips', [LocalTripController::class, 'index'])->name('local-trips');
        Route::post('/local-trips', [LocalTripController::class, 'store'])->name('local-trips.store');
        Route::get('/local-trips/{trip}', [LocalTripController::class, 'show'])->name('local-trips.show');
        Route::post('/local-trips/{trip}/items', [LocalTripController::class, 'addAssignment'])->name('local-trips.items.store');
        Route::post('/local-trips/{trip}/load', [LocalTripController::class, 'load'])->name('local-trips.load');
        Route::post('/local-trips/{trip}/close', [LocalTripController::class, 'close'])->name('local-trips.close');

        Route::get('/scanner', fn () => redirect()->route('logistics.parcels.receive'))->name('scanner');
        Route::get('/waybills/{tracking}', [DispatchController::class, 'waybill'])->name('waybills.show');

        Route::get('/riders', [LogisticsPortalController::class, 'riders'])->name('riders');
        Route::get('/riders/applications', [RiderApplicationController::class, 'index'])->name('riders.applications');
        Route::get('/riders/applications/{application}', [RiderApplicationController::class, 'show'])->name('riders.application.show');
        Route::post('/riders/applications/{application}/approve', [RiderApplicationController::class, 'approve'])->name('riders.approve');
        Route::post('/riders/applications/{application}/reject', [RiderApplicationController::class, 'reject'])->name('riders.reject');
        Route::get('/riders/applications/{application}/documents/{document}', [RiderApplicationController::class, 'document'])->name('riders.application.documents.show');
        Route::post('/riders/applications/{application}/documents/{document}/verify', [RiderApplicationController::class, 'verifyDocument'])->name('riders.application.documents.verify');
        Route::post('/riders/applications/{application}/documents/{document}/reject', [RiderApplicationController::class, 'rejectDocument'])->name('riders.application.documents.reject');
        Route::get('/riders/{rider}', [LogisticsPortalController::class, 'riderShow'])->name('riders.show');
        Route::patch('/riders/{rider}/moderation', [LogisticsPortalController::class, 'moderateRider'])->name('riders.moderation.update');
        Route::patch('/riders/{rider}/area', [LogisticsPortalController::class, 'reassignRiderArea'])->name('riders.area.update');
        Route::patch('/riders/{rider}/capacity', [LogisticsPortalController::class, 'updateRiderCapacity'])->name('riders.capacity.update');
        Route::patch('/riders/{rider}/activate', [LogisticsPortalController::class, 'activateRider'])->name('riders.activate');
        Route::patch('/riders/{rider}/deactivate', [LogisticsPortalController::class, 'deactivateRider'])->name('riders.deactivate');

        Route::get('/delivery-areas', [LogisticsPortalController::class, 'deliveryAreas'])->name('delivery-areas');
        Route::post('/delivery-areas', [LogisticsPortalController::class, 'saveDeliveryArea'])->name('delivery-areas.store');
        Route::patch('/delivery-areas/{area}', [LogisticsPortalController::class, 'toggleDeliveryArea'])->name('delivery-areas.toggle');

        Route::get('/messages', [LogisticsPortalController::class, 'messages'])->name('messages');
        Route::get('/messages/stream', [LogisticsPortalController::class, 'messageStream'])->name('messages.stream');
        Route::post('/messages', [LogisticsPortalController::class, 'sendMessage'])->name('messages.send');
        Route::get('/reports', [LogisticsPortalController::class, 'reports'])->name('reports');
        Route::get('/remittances', [LogisticsRemittanceController::class, 'index'])->name('remittances');
        Route::post('/remittances/{remittance}/verify', [LogisticsRemittanceController::class, 'verify'])->name('remittances.verify');
        Route::post('/remittances/{remittance}/reconcile', [LogisticsRemittanceController::class, 'reconcile'])->name('remittances.reconcile');
        Route::get('/reports/export', [LogisticsPortalController::class, 'exportReport'])->name('reports.export');
        Route::get('/profile', [LogisticsPortalController::class, 'profile'])->name('profile');
        Route::get('/account', [LogisticsPortalController::class, 'profile'])->name('account');
        Route::patch('/account/profile', [LogisticsPortalController::class, 'updateAccount'])->name('account.profile.update');
        Route::patch('/account/address', [LogisticsPortalController::class, 'updateCenterAddress'])->name('account.address.update');
        Route::patch('/account/password', [LogisticsPortalController::class, 'updatePassword'])->name('account.password.update');
    });
});
