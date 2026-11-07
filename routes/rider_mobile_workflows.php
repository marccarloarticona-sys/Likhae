<?php

use App\Http\Controllers\Api\RiderWorkflowsApiController;
use App\Http\Controllers\Rider\RiderController;
use App\Http\Controllers\Rider\RiderReturnWorkflowController;
use App\Http\Controllers\Rider\RiderTripController;
use Illuminate\Support\Facades\Route;

Route::get('profile/updates', [RiderWorkflowsApiController::class, 'profileUpdates'])
    ->name('profile.updates');
Route::post('profile/updates', [RiderController::class, 'submitProfileUpdate'])
    ->name('profile.updates.store');

Route::middleware('rider.operational:LOCAL_BARANGAY')->group(function (): void {
    Route::get('returns', [RiderWorkflowsApiController::class, 'returns'])->name('returns');
    foreach ([
        'accept' => 'accept',
        'center-handoff' => 'centerHandoff',
        'pickup' => 'pickup',
        'seller-handoff' => 'sellerHandoff',
        'handoff/from' => 'handoffFrom',
        'handoff/to' => 'handoffTo',
    ] as $path => $method) {
        Route::post('returns/{workflow}/'.$path, [RiderReturnWorkflowController::class, $method])
            ->name('returns.'.$method);
    }

    Route::get('trips', [RiderWorkflowsApiController::class, 'trips'])->name('trips');
    Route::get('trips/{trip}', [RiderWorkflowsApiController::class, 'trip'])->name('trips.show');
    foreach (['accept', 'reject', 'load', 'start'] as $action) {
        Route::post('trips/{trip}/'.$action, [RiderTripController::class, $action])
            ->name('trips.'.$action);
    }
});
