<?php

namespace App\Livewire\Settings;

use App\Support\Push\WebPushConfiguration;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PushSettings extends Component
{
    /**
     * Testversand anzeigen. Im Profil bewusst aus: dort ist das Panel absichtlich
     * reduziert (siehe PwaFrontendTest). Die Hilfeseite setzt die Flagge, weil
     * "installieren und testen" dort der eigentliche Zweck ist.
     */
    public bool $showTest = false;

    public function render(): View
    {
        $pushDiagnostics = WebPushConfiguration::diagnostics();

        return view('livewire.settings.push-settings', [
            'pushDiagnostics' => $pushDiagnostics,
            'clientConfig' => [
                'serverEnabled' => $pushDiagnostics['enabled'],
                'serverConfigured' => $pushDiagnostics['configured'],
                'vapidPublicKey' => (string) config('webpush.vapid.public_key'),
                'accountBinding' => WebPushConfiguration::accountBinding(auth()->id()),
                'urls' => [
                    'status' => route('push.status'),
                    'subscribe' => route('push.subscriptions.store'),
                    'unsubscribe' => route('push.subscriptions.destroy'),
                    // Fehlte bisher: sendTest() in resources/js/pwa.js greift auf
                    // config.urls.test zu, wodurch der Testversand ins Leere lief.
                    'test' => route('push.test'),
                ],
                'messages' => [
                    'loadFailed' => __('app.push_load_failed'),
                    'syncFailed' => __('app.push_sync_failed'),
                    'statusLoading' => __('app.push_status_loading'),
                    'statusLoadingDescription' => __('app.push_status_loading_description'),
                    'statusInsecure' => __('app.push_status_insecure'),
                    'statusInsecureDescription' => __('app.push_status_insecure_description'),
                    'statusIosInstall' => __('app.push_status_ios_install'),
                    'statusIosInstallDescription' => __('app.push_status_ios_install_description'),
                    'statusUnsupported' => __('app.push_status_unsupported'),
                    'statusUnsupportedDescription' => __('app.push_status_unsupported_description'),
                    'statusUnavailable' => __('app.push_status_unavailable'),
                    'statusUnavailableDescription' => __('app.push_status_unavailable_description'),
                    'statusBlocked' => __('app.push_status_blocked'),
                    'statusBlockedDescription' => __('app.push_status_blocked_description'),
                    'statusActive' => __('app.push_status_active'),
                    'statusActiveDescription' => __('app.push_status_active_description'),
                    'statusReady' => __('app.push_status_ready'),
                    'statusReadyDescription' => __('app.push_status_ready_description'),
                    'installAccepted' => __('app.push_install_accepted'),
                    'installFailed' => __('app.push_install_failed'),
                    'subscribeUnavailable' => __('app.push_subscribe_unavailable'),
                    'permissionDenied' => __('app.push_permission_denied'),
                    'permissionDismissed' => __('app.push_permission_dismissed'),
                    'subscribed' => __('app.push_subscribed'),
                    'subscribeFailed' => __('app.push_subscribe_failed'),
                    'unsubscribed' => __('app.push_unsubscribed'),
                    'unsubscribeFailed' => __('app.push_unsubscribe_failed'),
                ],
            ],
        ]);
    }
}
