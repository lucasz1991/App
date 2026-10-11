<?php

use App\Http\Controllers\UserAppRuntimeStatusController;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\TouchSessionPermissions;
use Illuminate\Support\Facades\Route;

Route::post('/app/runtime-status', [UserAppRuntimeStatusController::class, 'store'])
    ->middleware(['auth', 'auth.status', config('jetstream.auth_session'), 'throttle:30,1'])
    ->withoutMiddleware([LogActivity::class, TouchSessionPermissions::class])
    ->name('app.runtime-status');
