<?php

use App\Http\Controllers\GpsPositionController;
use App\Http\Controllers\VehiclePositionController;
use App\Http\Middleware\AuthenticateGpsDevice;
use Illuminate\Support\Facades\Route;

Route::post('v1/gps-positions', [GpsPositionController::class, 'store'])
    ->middleware([AuthenticateGpsDevice::class, 'throttle:60,1'])
    ->name('api.v1.gps-positions.store');

Route::get('v1/vehicles/{vehicle:code}/latest-position', VehiclePositionController::class)
    ->middleware([AuthenticateGpsDevice::class, 'throttle:60,1'])
    ->name('api.v1.vehicles.latest-position');
