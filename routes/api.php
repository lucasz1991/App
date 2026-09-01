<?php

use App\Http\Controllers\Api\DeviceArtifactDownloadController;
use App\Http\Controllers\Api\DeviceProviderWebhookController;
use App\Http\Controllers\Api\OutlookAddinBootstrapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/outlook-addin/bootstrap', OutlookAddinBootstrapController::class)
    ->middleware('throttle:outlook-addin')
    ->name('api.outlook-addin.bootstrap');

// Connector-Rueckmeldungen sind HMAC-signiert (Zeitstempel + Rohbody),
// groessenbegrenzt und zusaetzlich durch den allgemeinen API-Limiter geschuetzt.
Route::post('/device-management/providers/{provider}/events', DeviceProviderWebhookController::class)
    ->where('provider', '[a-z0-9_-]{2,64}')
    ->middleware('throttle:device-provider-webhook')
    ->name('api.device-management.providers.events');

Route::get('/device-management/providers/{provider}/artifacts/{artifact}', DeviceArtifactDownloadController::class)
    ->where('provider', '[a-z0-9_-]{2,64}')
    ->middleware('throttle:device-provider-webhook')
    ->name('api.device-management.artifacts.show');
