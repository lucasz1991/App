<?php

use App\Http\Controllers\Admin\MailDocumentController;
use App\Http\Controllers\Admin\MarketingCreativeController;
use App\Http\Controllers\Admin\MarketingCreativeTransferController;
use App\Http\Controllers\Admin\MarketingFileController;
use App\Http\Controllers\Admin\MarketingRenderController;
use App\Http\Controllers\Admin\MarketingVariantController;
use App\Http\Controllers\Admin\OutlookAddinDeploymentController;
use App\Http\Controllers\Admin\PageBuilderPreviewController;
use App\Http\Controllers\Assistant\AssistantAudioInputTranscriptionController;
use App\Http\Controllers\Assistant\AssistantAudioOutputStreamController;
use App\Http\Controllers\Assistant\AssistantPageBuilderActionClaimController;
use App\Http\Controllers\Assistant\AssistantSpeechStatusController;
use App\Http\Controllers\Auth\InvitedRegistrationController;
use App\Http\Controllers\Calls\CallRecordingAcknowledgementController;
use App\Http\Controllers\Calls\CallRecordingPlaybackController;
use App\Http\Controllers\Calls\CallRingAckController;
use App\Http\Controllers\Calls\CallTokenController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatExportController;
use App\Http\Controllers\ChatLiveLocationController;
use App\Http\Controllers\DeviceInventoryTemplateController;
use App\Http\Controllers\ManagedDocumentDownloadController;
use App\Http\Controllers\OutlookAddin\OutlookAddinController;
use App\Http\Controllers\ProfileEmailTemplateController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaIconController;
use App\Http\Controllers\WagonListExportController;
use App\Http\Controllers\WagonListMediaController;
use App\Http\Controllers\Webhooks\LiveKitWebhookController;
use App\Http\Middleware\EnsureAssistantAccess;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RedirectAdminWagonList;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\FileManager;
use App\Livewire\Admin\MailDocumentEditor;
use App\Livewire\Admin\MailManagement;
use App\Livewire\Admin\ManagedDocuments;
use App\Livewire\Admin\Marketing\CreativeEditor as MarketingCreativeEditor;
use App\Livewire\Admin\Marketing\CreativesIndex as MarketingCreativesIndex;
use App\Livewire\Admin\OperationalPreview;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\UserProfile;
use App\Livewire\Calls\CallDetails;
use App\Livewire\Calls\CallHistory;
use App\Livewire\Calls\CallWindow;
use App\Livewire\ChatBox;
use App\Livewire\Devices\DeviceEnrollmentSetup;
use App\Livewire\Devices\DeviceManagement;
use App\Livewire\Devices\MyDevices;
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

// Oeffentliche, geheimnisfreie Office-Add-in-Oberflaechen. Authentifiziert
// wird ausschliesslich der persoenliche API-Abruf per Microsoft Entra NAA.
Route::get('/outlook-addin/config.json', [OutlookAddinController::class, 'config'])
    ->withoutMiddleware(LogActivity::class)
    ->name('outlook-addin.config');
Route::get('/outlook-addin/taskpane', [OutlookAddinController::class, 'taskpane'])
    ->withoutMiddleware(LogActivity::class)
    ->name('outlook-addin.taskpane');
Route::get('/outlook-addin/runtime', [OutlookAddinController::class, 'runtime'])
    ->withoutMiddleware(LogActivity::class)
    ->name('outlook-addin.runtime');
Route::get('/outlook-addin/{bundle}.js', [OutlookAddinController::class, 'bundle'])
    ->whereIn('bundle', ['runtime', 'taskpane'])
    ->withoutMiddleware(LogActivity::class)
    ->name('outlook-addin.bundle');
Route::get('/outlook-addin/assets/icon-{size}.png', [OutlookAddinController::class, 'icon'])
    ->whereNumber('size')
    ->withoutMiddleware(LogActivity::class)
    ->name('outlook-addin.icon');
Route::get('/.well-known/microsoft-officeaddins-allowed.json', [OutlookAddinController::class, 'allowed'])
    ->withoutMiddleware(LogActivity::class)
    ->name('outlook-addin.allowed');

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

Route::get('/locale/{locale}', function (Request $request, string $locale) {
    if (in_array($locale, config('app.supported_locales', []), true)) {
        session(['locale' => $locale]);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }
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

// Die Auth-Views sind in Fortify bewusst deaktiviert. Verify- und Resend-
// Endpunkt kommen aus Fortify; die bestehende Hinweisansicht registrieren wir
// deshalb selbst und ohne `verified`, damit keine Redirect-Schleife entsteht.
Route::view('/email/verify', 'auth.verify-email')
    ->middleware(['auth:sanctum', 'auth.status', config('jetstream.auth_session')])
    ->name('verification.notice');

Route::middleware(['auth:sanctum', 'auth.status', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::post('/assistant/audio-input/transcribe', AssistantAudioInputTranscriptionController::class)
        ->middleware(EnsureAssistantAccess::class.':assistant-stt')
        ->name('assistant.audio-input.transcribe');
    Route::post('/assistant/audio-output/stream', AssistantAudioOutputStreamController::class)
        ->middleware(EnsureAssistantAccess::class.':assistant-tts')
        ->name('assistant.audio-output.stream');
    Route::get('/assistant/speech/status', AssistantSpeechStatusController::class)
        ->name('assistant.speech.status');
    Route::post('/assistant/pagebuilder-actions/claim', AssistantPageBuilderActionClaimController::class)
        ->middleware(EnsureAssistantAccess::class.':assistant-pagebuilder-actions')
        ->name('assistant.pagebuilder-actions.claim');
    Route::get('/dashboard', UserDashboard::class)->name('dashboard');
    Route::get('/employees', Employees::class)->name('employees.index');
    Route::get('/employees/{userId}', UserProfile::class)->name('employees.show');
    // Die neutrale Route bleibt fuer delegierte Verwaltungs-/Teamrechte
    // erreichbar. Jede Livewire-Aktion prueft ihr konkretes Device-Gate erneut.
    Route::get('/geraete', DeviceManagement::class)->name('devices.index');
    Route::get('/geraete/import-vorlage.csv', DeviceInventoryTemplateController::class)
        ->name('devices.import-template');
    Route::get('/meine-geraete', MyDevices::class)->name('devices.mine');
    Route::get('/geraete/einrichten/{token}', DeviceEnrollmentSetup::class)
        ->where('token', '[A-Za-z0-9_-]{40,128}')
        ->middleware('throttle:10,1')
        ->name('devices.enrollment');
    Route::get('/betrieb/wagenliste', WagonListPrototype::class)
        ->middleware(RedirectAdminWagonList::class)
        ->name('operations.wagon-list');
    Route::post('/betrieb/wagenliste/export', WagonListExportController::class)
        ->name('operations.wagon-list.export');
    Route::post('/betrieb/wagenliste/medien', WagonListMediaController::class)
        ->middleware('throttle:30,1')
        ->name('operations.wagon-list.media');
    Route::get('/files', UserFiles::class)->name('files');
    Route::get('/files/verbindlich/{managedDocument}', ManagedDocumentDownloadController::class)
        ->name('managed-documents.download');
    Route::get('/messages', MessageBox::class)->name('messages');
    // Chat steht ALLEN angemeldeten Benutzern offen (Admin- wie Nutzerbereich).
    Route::get('/chat', ChatBox::class)->name('chat');
    Route::get('/chat/live-locations/active', [ChatLiveLocationController::class, 'active'])
        ->middleware('throttle:chat-live-location-sync')
        ->name('chat.live-locations.active');
    Route::post('/chat/{chat}/live-locations', [ChatLiveLocationController::class, 'store'])
        ->whereNumber('chat')
        ->middleware('throttle:chat-live-location-start')
        ->name('chat.live-locations.store');
    Route::patch('/chat/live-locations/{liveLocation:uuid}', [ChatLiveLocationController::class, 'update'])
        ->middleware('throttle:chat-live-location-sync')
        ->name('chat.live-locations.update');
    Route::delete('/chat/live-locations/{liveLocation:uuid}', [ChatLiveLocationController::class, 'destroy'])
        ->middleware('throttle:chat-live-location-sync')
        ->name('chat.live-locations.destroy');
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
    Route::post('/calls/recording/acknowledge', CallRecordingAcknowledgementController::class)
        ->middleware('throttle:10,1')
        ->name('calls.recording.acknowledge');
    Route::get('/calls/{room:uuid}/recordings/{recording:uuid}/play', CallRecordingPlaybackController::class)
        ->middleware('throttle:30,1')
        ->name('calls.recording.play');
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
    Route::get('/email-templates/signature/copy', [ProfileEmailTemplateController::class, 'signatureCopy'])
        ->name('email-templates.signature-copy');
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
        Route::post('/betrieb/wagenliste/medien', WagonListMediaController::class)
            ->middleware('throttle:30,1')
            ->name('operations.wagon-list.media');
        Route::get('/settings', Settings::class)->name('settings');
        Route::get('/employees', Employees::class)->name('employees');
        Route::get('/user/{userId}', UserProfile::class)->name('user-profile');
        Route::get('/geraete', DeviceManagement::class)->name('devices');
        Route::get('/files', FileManager::class)->name('files');
        Route::get('/files/verbindlich', ManagedDocuments::class)->name('managed-documents');
        Route::get('/mails', MailManagement::class)->name('mail-management');
        Route::get('/mail-vorlagen', MailDocumentEditor::class)->name('mail-documents.editor');
        Route::get('/mail-vorlagen/outlook/manifest.xml', [OutlookAddinDeploymentController::class, 'manifest'])
            ->withoutMiddleware('role:admin')
            ->name('outlook-addin.manifest');
        Route::get('/mail-vorlagen/outlook/bereitstellung.zip', [OutlookAddinDeploymentController::class, 'package'])
            ->withoutMiddleware('role:admin')
            ->name('outlook-addin.package');
        // Speichern und Veroeffentlichen antworten mit JSON. role:admin ist
        // dafuer abgeschaltet, weil RoleMiddleware bei falscher Rolle
        // weiterleitet statt 403 zu liefern — im Editor kaeme sonst eine
        // HTML-Seite als vermeintliche JSON-Antwort an. Die Pruefung steht
        // stattdessen ausdruecklich im Controller.
        Route::withoutMiddleware('role:admin')->group(function (): void {
            Route::post('/mail-vorlagen/importieren', [MailDocumentController::class, 'import'])
                ->middleware('throttle:20,1')
                ->name('mail-documents.import');
            Route::get('/mail-vorlagen/{document}/vorschau', [PageBuilderPreviewController::class, 'mail'])
                ->whereUuid('document')
                ->middleware('throttle:180,1')
                ->name('mail-documents.preview');
            Route::post('/mail-vorlagen/{document}/versandvorschau', [MailDocumentController::class, 'deliveryPreview'])
                ->whereUuid('document')
                ->middleware('throttle:60,1')
                ->name('mail-documents.delivery-preview');
            Route::put('/mail-vorlagen/{document}', [MailDocumentController::class, 'update'])
                ->whereUuid('document')
                ->middleware('throttle:120,1')
                ->name('mail-documents.update');
            Route::post('/mail-vorlagen/{document}/code-pruefen', [MailDocumentController::class, 'validateCode'])
                ->whereUuid('document')
                ->middleware('throttle:60,1')
                ->name('mail-documents.validate-code');
            Route::post('/mail-vorlagen/{document}/veroeffentlichen', [MailDocumentController::class, 'publish'])
                ->whereUuid('document')
                ->middleware('throttle:30,1')
                ->name('mail-documents.publish');
            Route::post('/mail-vorlagen/{document}/testmail', [MailDocumentController::class, 'testMail'])
                ->whereUuid('document')
                ->middleware('throttle:10,1')
                ->name('mail-documents.test-mail');
            Route::post('/mail-vorlagen/{document}/versionen/{version}/wiederherstellen', [MailDocumentController::class, 'restoreVersion'])
                ->whereUuid(['document', 'version'])
                ->middleware('throttle:30,1')
                ->name('mail-documents.versions.restore');
            Route::delete('/mail-vorlagen/{document}/versionen/{version}', [MailDocumentController::class, 'deleteVersion'])
                ->whereUuid(['document', 'version'])
                ->middleware('throttle:30,1')
                ->name('mail-documents.versions.destroy');
            Route::post('/mail-vorlagen/{document}/designs', [MailDocumentController::class, 'createDesignSlot'])
                ->whereUuid('document')
                ->middleware('throttle:30,1')
                ->name('mail-documents.slots.store');
            Route::patch('/mail-vorlagen/{document}/design', [MailDocumentController::class, 'renameDesignSlot'])
                ->whereUuid('document')
                ->middleware('throttle:60,1')
                ->name('mail-documents.slots.update');
            Route::delete('/mail-vorlagen/{document}/design', [MailDocumentController::class, 'deleteDesignSlot'])
                ->whereUuid('document')
                ->middleware('throttle:30,1')
                ->name('mail-documents.slots.destroy');
        });
        Route::withoutMiddleware('role:admin')->group(function (): void {
            Route::get('/marketing/motive', MarketingCreativesIndex::class)
                ->name('marketing.creatives.index');
            Route::post('/marketing/motive', [MarketingCreativeController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('marketing.creatives.store');
            Route::post('/marketing/motive/importieren', [MarketingCreativeTransferController::class, 'import'])
                ->middleware('throttle:10,1')
                ->name('marketing.creatives.import');
            Route::get('/marketing/motive/{creative}/bearbeiten', MarketingCreativeEditor::class)
                ->whereUuid('creative')
                ->name('marketing.creatives.editor');
            Route::get('/marketing/motive/{creative}/vorschau/{format}', [PageBuilderPreviewController::class, 'marketing'])
                ->whereUuid('creative')
                ->whereIn('format', ['story', 'post', 'web'])
                ->middleware('throttle:180,1')
                ->name('marketing.creatives.preview');
            Route::patch('/marketing/motive/{creative}', [MarketingCreativeController::class, 'update'])
                ->whereUuid('creative')
                ->middleware('throttle:120,1')
                ->name('marketing.creatives.update');
            Route::post('/marketing/motive/{creative}/redesign', [MarketingCreativeController::class, 'redesign'])
                ->whereUuid('creative')
                ->middleware('throttle:20,1')
                ->name('marketing.creatives.redesign');
            Route::post('/marketing/motive/{creative}/duplizieren', [MarketingCreativeController::class, 'duplicate'])
                ->whereUuid('creative')
                ->middleware('throttle:30,1')
                ->name('marketing.creatives.duplicate');
            Route::get('/marketing/motive/{creative}/exportieren', [MarketingCreativeTransferController::class, 'export'])
                ->whereUuid('creative')
                ->middleware('throttle:30,1')
                ->name('marketing.creatives.export');
            Route::post('/marketing/motive/{creative}/freigeben', [MarketingCreativeController::class, 'approve'])
                ->whereUuid('creative')
                ->middleware('throttle:30,1')
                ->name('marketing.creatives.approve');
            Route::post('/marketing/motive/{creative}/archivieren', [MarketingCreativeController::class, 'archive'])
                ->whereUuid('creative')
                ->middleware('throttle:30,1')
                ->name('marketing.creatives.archive');
            Route::delete('/marketing/motive/{creative}', [MarketingCreativeController::class, 'destroy'])
                ->whereUuid('creative')
                ->middleware('throttle:30,1')
                ->name('marketing.creatives.destroy');
            Route::put('/marketing/motive/{creative}/varianten/{format}', [MarketingVariantController::class, 'update'])
                ->whereUuid('creative')
                ->whereIn('format', ['story', 'post', 'web'])
                ->middleware('throttle:120,1')
                ->name('marketing.variants.update');
            Route::post('/marketing/motive/{creative}/renders', [MarketingRenderController::class, 'store'])
                ->whereUuid('creative')
                ->middleware('throttle:10,1')
                ->name('marketing.renders.store');

            Route::get('/marketing/dateien/{file}', MarketingFileController::class)
                ->whereNumber('file')
                ->middleware('throttle:600,1')
                ->name('marketing.files.show');
            Route::get('/marketing/renders/{render}', [MarketingRenderController::class, 'show'])
                ->whereUuid('render')
                ->middleware('throttle:120,1')
                ->name('marketing.renders.show');
            Route::get('/marketing/renders/{render}/download', [MarketingRenderController::class, 'download'])
                ->whereUuid('render')
                ->middleware('throttle:60,1')
                ->name('marketing.renders.download');
        });
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
