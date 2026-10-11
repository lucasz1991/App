<?php

use App\Http\Controllers\EmployeeLocation\EmployeeLocationLinksController;
use App\Http\Middleware\LogActivity;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/assetlinks.json', [EmployeeLocationLinksController::class, 'android'])->withoutMiddleware(LogActivity::class)->name('employee-location.links.android');
Route::get('/.well-known/apple-app-site-association', [EmployeeLocationLinksController::class, 'apple'])->withoutMiddleware(LogActivity::class)->name('employee-location.links.apple');
Route::get('/employee-location/open', [EmployeeLocationLinksController::class, 'open'])->withoutMiddleware(LogActivity::class)->name('employee-location.open');
