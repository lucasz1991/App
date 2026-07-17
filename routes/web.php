<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\MailManagement;
use App\Livewire\Admin\UserProfile;
use App\Livewire\AdminMessageBox;
use App\Livewire\MessageBox;
use App\Livewire\UserDashboard;
use App\Livewire\UserFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return in_array(auth()->user()->role, ['admin', 'staff'], true)
            ? redirect()->route('admin.dashboard')
            : redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, config('app.supported_locales', []), true)) {
        session(['locale' => $locale]);
    }

    return redirect()->back();
})->name('locale.switch');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');

    // Registrierung nur per Einladung aus dem Adminbereich
    Route::get('/einladung/{token}', [App\Http\Controllers\Auth\InvitedRegistrationController::class, 'create'])
        ->name('invitation.register');
    Route::post('/einladung/{token}', [App\Http\Controllers\Auth\InvitedRegistrationController::class, 'store'])
        ->name('invitation.register.store');

    Route::get('/administrator/login', function (Request $request) {
        $request->session()->put('url.intended', route('admin.dashboard'));

        return view('auth.admin-login');
    })->name('admin.login');

    Route::view('/administrator/forgot-password', 'auth.forgot-password')->name('password.request');

    Route::get('/administrator/reset-password/{token}', function (Request $request, string $token) {
        return view('auth.reset-password', ['request' => $request]);
    })->name('password.reset');

    // Fortify registriert bei 'views' => false keine GET-View-Routen;
    // ohne diese Route wuerde ein Login mit aktivierter 2FA in einer
    // RouteNotFoundException enden.
    Route::view('/two-factor-challenge', 'auth.two-factor-challenge')->name('two-factor.login');
});

Route::view('/user/confirm-password', 'auth.confirm-password')
    ->middleware(['auth:sanctum', config('jetstream.auth_session')])
    ->name('password.confirm');

Route::middleware(['auth:sanctum', 'auth.status', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', UserDashboard::class)->name('dashboard');
    Route::get('/files', UserFiles::class)->name('files');
    Route::get('/messages', MessageBox::class)->name('messages');
});

Route::middleware(['auth:sanctum', 'auth.status', config('jetstream.auth_session'), 'verified', 'role:admin,staff'])
    ->prefix('administrator')
    ->name('admin.')
    ->group(function () {
        Route::get('/', Dashboard::class)->name('dashboard');
        Route::get('/index', Dashboard::class)->name('index');
        Route::get('/employees', Employees::class)->name('employees');
        Route::get('/user/{userId}', UserProfile::class)->name('user-profile');
        Route::get('/files', App\Livewire\Admin\FileManager::class)->name('files');
        Route::get('/mails', MailManagement::class)->name('mail-management');
        Route::get('/messages', AdminMessageBox::class)->name('messages');
});
