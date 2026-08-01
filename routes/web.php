<?php

use App\Http\Controllers\Assistant\AssistantAudioInputTranscriptionController;
use App\Http\Controllers\Assistant\AssistantAudioOutputStreamController;
use App\Http\Controllers\Assistant\AssistantSpeechStatusController;
use App\Http\Controllers\Auth\InvitedRegistrationController;
use App\Http\Controllers\Calls\CallRingAckController;
use App\Http\Controllers\Calls\CallTokenController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatExportController;
use App\Http\Controllers\ManagedDocumentDownloadController;
use App\Http\Controllers\ProfileEmailTemplateController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaIconController;
use App\Http\Controllers\WagonListExportController;
use App\Http\Controllers\Webhooks\LiveKitWebhookController;
use App\Http\Middleware\EnsureAssistantAccess;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RedirectAdminWagonList;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\FileManager;
use App\Livewire\Admin\MailManagement;
use App\Livewire\Admin\ManagedDocuments;
use App\Livewire\Admin\OperationalPreview;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\UserProfile;
use App\Livewire\Calls\CallHistory;
use App\Livewire\Calls\CallDetails;
use App\Livewire\Calls\CallWindow;
use App\Livewire\ChatBox;
use App\Livewire\HelpCenter;
use App\Livewire\ItSupport;
use App\Livewire\MessageBox;
use App\Livewire\Operations\WagonListPrototype;
use App\Livewire\UserDashboard;
use App\Livewire\UserFiles;
use App\Support\Pwa\PwaIcon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/pwa-icons/{icon}', PwaIconController::class)
    ->whereIn('icon', array_keys(PwaIcon::DIMENSIONS))
    ->withoutMiddleware(LogActivity::class)
    ->name('pwa.icon');

// Legacy-Pfad fuer bereits installierte Manifeste/Service Worker. Auf Servern,
// die physische Unterordner vor Laravel abfangen, zeigt die Anwendung selbst
// ausschliesslich auf den kanonischen /pwa-icons-Pfad oben.
Route::get('/icons/{icon}', PwaIconController::class)
    ->whereIn('icon', array_keys(PwaIcon::DIMENSIONS))
    ->withoutMiddleware(LogActivity::class)
    ->name('pwa.icon.legacy');

Route::get('/', function () {
    if (auth()->check()) {
        return auth()->user()->usesAdminLayout()
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
    Route::get('/einladung/{token}', [InvitedRegistrationController::class, 'create'])
        ->name('invitation.register');
    Route::post('/einladung/{token}', [InvitedRegistrationController::class, 'store'])
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
    Route::post('/assistant/audio-input/transcribe', AssistantAudioInputTranscriptionController::class)
        ->middleware(EnsureAssistantAccess::class.':assistant-stt')
        ->name('assistant.audio-input.transcribe');
    Route::post('/assistant/audio-output/stream', AssistantAudioOutputStreamController::class)
        ->middleware(EnsureAssistantAccess::class.':assistant-tts')
        ->name('assistant.audio-output.stream');
    Route::get('/assistant/speech/status', AssistantSpeechStatusController::class)
        ->name('assistant.speech.status');
    Route::get('/dashboard', UserDashboard::class)->name('dashboard');
    Route::get('/employees', Employees::class)->name('employees.index');
    Route::get('/employees/{userId}', UserProfile::class)->name('employees.show');
    Route::get('/betrieb/wagenliste', WagonListPrototype::class)
        ->middleware(RedirectAdminWagonList::class)
        ->name('operations.wagon-list');
    Route::post('/betrieb/wagenliste/export', WagonListExportController::class)
        ->name('operations.wagon-list.export');
    Route::get('/files', UserFiles::class)->name('files');
    Route::get('/files/verbindlich/{managedDocument}', ManagedDocumentDownloadController::class)
        ->name('managed-documents.download');
    Route::get('/messages', MessageBox::class)->name('messages');
    // Chat steht ALLEN angemeldeten Benutzern offen (Admin- wie Nutzerbereich).
    Route::get('/chat', ChatBox::class)->name('chat');
    // Videoanrufe: Anruf-Fenster + Token-Ausgabe (Token immer frisch per fetch,
    // nie im Livewire-Snapshot – siehe LIVEKIT_INTEGRATION_PLAN.md).
    // Uebersichten: Anrufverlauf und offene Besprechungsraeume. Muessen VOR
    // der {room:uuid}-Route stehen, damit /calls nicht als UUID gelesen wird.
    Route::get('/calls', CallHistory::class)->name('calls.index');
    Route::get('/meetings', fn () => redirect()->route('calls.index'))->name('meetings');
    Route::get('/calls/{room:uuid}/history', CallDetails::class)->name('calls.history');
    Route::get('/calls/{room:uuid}', CallWindow::class)->name('calls.window');
    Route::post('/calls/{room:uuid}/token', [CallTokenController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('calls.token');
    Route::get('/chat/{chat}/export', ChatExportController::class)
        ->whereNumber('chat')
        ->middleware('throttle:30,1')
        ->name('chat.export');
    Route::get('/help', HelpCenter::class)->name('help');
    Route::get('/support', ItSupport::class)->name('support');
    Route::prefix('settings/push')
        ->name('push.')
        ->middleware('throttle:push-subscriptions')
        ->group(function (): void {
            Route::get('/status', [PushSubscriptionController::class, 'status'])->name('status');
            Route::post('/subscriptions', [PushSubscriptionController::class, 'store'])->name('subscriptions.store');
            Route::delete('/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
            Route::patch('/preferences', [PushSubscriptionController::class, 'updatePreferences'])->name('preferences.update');
            Route::post('/test', [PushSubscriptionController::class, 'test'])
                ->withoutMiddleware('throttle:push-subscriptions')
                ->middleware('throttle:push-test')
                ->name('test');
        });
    Route::get('/chat/files/{file}', ChatAttachmentController::class)
        ->name('chat.attachments');
    // Personalisierte E-Mail-Vorlagen/Signaturen als eigenstaendiger Bereich.
    Route::view('/email-templates', 'email-templates.index')->name('email-templates.index');
    Route::get('/email-templates/{template}/download', ProfileEmailTemplateController::class)
        ->name('email-templates.download');
    Route::get('/email-templates/{template}/preview', [ProfileEmailTemplateController::class, 'preview'])
        ->name('email-templates.preview');
});

Route::middleware(['auth:sanctum', 'auth.status', config('jetstream.auth_session'), 'verified', 'role:admin'])
    ->prefix('administrator')
    ->name('admin.')
    ->group(function () {
        Route::get('/', Dashboard::class)->name('dashboard');
        Route::get('/index', Dashboard::class)->name('index');
        Route::get('/betrieb/{module}', OperationalPreview::class)
            ->where('module', 'orders|shift-management|calendar|customers')
            ->name('operations.preview');
        Route::get('/betrieb/wagenliste', WagonListPrototype::class)->name('operations.wagon-list');
        Route::post('/betrieb/wagenliste/export', WagonListExportController::class)
            ->name('operations.wagon-list.export');
        Route::get('/settings', Settings::class)->name('settings');
        Route::get('/employees', Employees::class)->name('employees');
        Route::get('/user/{userId}', UserProfile::class)->name('user-profile');
        Route::get('/files', FileManager::class)->name('files');
        Route::get('/files/verbindlich', ManagedDocuments::class)->name('managed-documents');
        Route::get('/mails', MailManagement::class)->name('mail-management');
        // Admins verwenden dieselbe robuste Nachrichtenoberfläche, erhalten
        // aber weiterhin über die Komponente das Admin-Layout.
        Route::get('/messages', MessageBox::class)->name('messages');
    });

// Klingel-Rueckmeldung der installierten App: der Service Worker meldet, dass
// er die Anruf-Benachrichtigung tatsaechlich anzeigt. Ausserhalb der Auth-
// Gruppe, weil der Service Worker keinen verlaesslichen Sitzungskontext hat —
// autorisiert wird ueber die signierte, kurzlebige URL aus der Push-Nutzlast.
Route::post('/calls/ring/{invitation}', CallRingAckController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->whereNumber('invitation')
    ->name('calls.ring-ack');

// LiveKit-Webhooks: ausserhalb der Auth-Gruppe, Signaturpruefung im Controller
// (JWT im Authorization-Header, signiert mit dem LiveKit-API-Schluesselpaar).
Route::post('/webhooks/livekit', LiveKitWebhookController::class)
    ->name('webhooks.livekit');
