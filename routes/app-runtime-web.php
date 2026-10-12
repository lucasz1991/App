<?php

use App\Http\Controllers\PwaSessionStatusController;
use App\Http\Controllers\UserAppRuntimeStatusController;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\TouchSessionPermissions;
use Illuminate\Support\Facades\Route;

// Keep browser reports outside Reverb's reverse-proxied /app/* namespace.
Route::post('/pwa/runtime-status', [UserAppRuntimeStatusController::class, 'store'])
    ->middleware(['auth', 'auth.status', config('jetstream.auth_session'), 'throttle:30,1'])
    ->withoutMiddleware([LogActivity::class, TouchSessionPermissions::class])
    ->name('app.runtime-status');

Route::get('/pwa/session-status', PwaSessionStatusController::class)
    ->middleware(['auth', 'auth.status', config('jetstream.auth_session'), 'throttle:30,1'])
    ->withoutMiddleware([LogActivity::class, TouchSessionPermissions::class])
    ->name('pwa.session-status');
