<?php

use App\Http\Controllers\EmployeeLocation\EmployeeLocationLinksController;
use App\Http\Controllers\EmployeeLocation\BrowserLocationController;
use App\Http\Middleware\EmployeeLocationPrivacy;
use App\Http\Middleware\LogActivity;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/assetlinks.json', [EmployeeLocationLinksController::class, 'android'])->withoutMiddleware(LogActivity::class)->name('employee-location.links.android');
Route::get('/.well-known/apple-app-site-association', [EmployeeLocationLinksController::class, 'apple'])->withoutMiddleware(LogActivity::class)->name('employee-location.links.apple');
Route::get('/employee-location/open', [EmployeeLocationLinksController::class, 'open'])->withoutMiddleware(LogActivity::class)->name('employee-location.open');

Route::prefix('employee-location')->name('employee-location.browser.')->middleware(['auth', EmployeeLocationPrivacy::class, 'throttle:60,1'])->withoutMiddleware(LogActivity::class)->group(function (): void {
    Route::get('browser-status', [BrowserLocationController::class, 'status'])->name('status');
    Route::post('browser-point', [BrowserLocationController::class, 'store'])->name('store');
    Route::delete('browser-point', [BrowserLocationController::class, 'destroy'])->name('destroy');
});
