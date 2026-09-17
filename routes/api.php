<?php

use App\Http\Controllers\Api\DeviceArtifactDownloadController;
use App\Http\Controllers\Api\DeviceDesktopClientController;
use App\Http\Controllers\Api\DeviceProviderWebhookController;
use App\Http\Controllers\Api\OutlookAddinBootstrapController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OperationsController;

Route::prefix('v1/operations')->name('api.operations.')->middleware([\App\Http\Middleware\OperationsApi::class, 'auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('me/times', [OperationsController::class, 'ownTimes']);
    Route::get('me/absences', [OperationsController::class, 'ownAbsences']);
    Route::post('me/absences', [OperationsController::class, 'requestAbsence']);
    Route::post('me/absences/{id}/withdraw', [OperationsController::class, 'withdrawAbsence'])->whereNumber('id');
    Route::post('me/times/start', [OperationsController::class, 'start']);
    Route::post('me/times/manual', [OperationsController::class, 'manual']);
    Route::post('me/times/{id}/events', [OperationsController::class, 'clock'])->whereNumber('id');
    Route::patch('me/times/{id}', [OperationsController::class, 'correct'])->whereNumber('id');
    Route::get('times', [OperationsController::class, 'times']);
    Route::post('times/{id}/review', [OperationsController::class, 'reviewTime'])->whereNumber('id');
    Route::get('absences', [OperationsController::class, 'absences']);
    Route::get('absences.csv', [OperationsController::class, 'absenceCsv']);
    Route::post('absences/{id}/review', [OperationsController::class, 'reviewAbsence'])->whereNumber('id');
    Route::get('exports', [OperationsController::class, 'exports']);
    Route::post('exports', [OperationsController::class, 'exportTimes']);
    Route::get('exports/{publicId}', [OperationsController::class, 'download'])->whereUuid('publicId')->name('exports.show');
});

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

// Native Desktopclients besitzen eigene, widerrufbare Geraetecredentials.
// Keine Microsoft-Tokens, Connector-Geheimnisse oder Nutzerpasswoerter.
Route::prefix('device-client/v1')->name('api.device-client.')->group(function () {
    Route::get('/help', [DeviceDesktopClientController::class, 'help'])->middleware('throttle:30,1')->name('help');
    Route::get('/support', [DeviceDesktopClientController::class, 'supportList'])->middleware('throttle:device-desktop-client')->name('support.index');
    Route::post('/support', [DeviceDesktopClientController::class, 'supportCreate'])->middleware('throttle:6,1')->name('support.create');
    Route::post('/support/{supportCase}', [DeviceDesktopClientController::class, 'supportReply'])->whereUuid('supportCase')->middleware('throttle:12,1')->name('support.reply');
    Route::get('/workplace', [DeviceDesktopClientController::class, 'workplace'])->middleware('throttle:device-desktop-client')->name('workplace');
    Route::post('/withdraw', [DeviceDesktopClientController::class, 'withdraw'])->middleware('throttle:6,1')->name('withdraw');
    Route::post('/enroll', [DeviceDesktopClientController::class, 'enroll'])
        ->middleware('throttle:6,1')->name('enroll');
    Route::post('/sync', [DeviceDesktopClientController::class, 'sync'])
        ->middleware('throttle:device-desktop-client')->name('sync');
    Route::post('/jobs/{job}/result', [DeviceDesktopClientController::class, 'result'])
        ->whereUuid('job')->middleware('throttle:device-desktop-client')->name('result');
});
