<?php

use App\Http\Controllers\EmployeeLocation\EmployeeLocationApiController;
use App\Http\Middleware\EmployeeLocationDevice;
use App\Http\Middleware\EmployeeLocationPrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('employee-location')->name('api.employee-location.')->middleware([EmployeeLocationPrivacy::class, 'throttle:employee-location'])->group(function (): void {
    Route::post('register', [EmployeeLocationApiController::class, 'register'])->middleware('throttle:5,1')->name('register');
    Route::middleware(EmployeeLocationDevice::class)->group(function (): void {
        Route::post('start', [EmployeeLocationApiController::class, 'start'])->name('start');
        Route::post('permit', [EmployeeLocationApiController::class, 'permit'])->name('permit');
        Route::post('points', [EmployeeLocationApiController::class, 'point'])->name('points');
        Route::post('stop', [EmployeeLocationApiController::class, 'stop'])->name('stop');
    });
});
