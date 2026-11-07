<?php

use App\Http\Controllers\Api\RiderPayoutsApiController;
use Illuminate\Support\Facades\Route;

Route::get('payouts', [RiderPayoutsApiController::class, 'index'])
    ->name('payouts');
