<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pwa\PwaIcon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PwaFrontendTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        config([
            'webpush.enabled' => false,
            'webpush.test_enabled' => false,
            'webpush.vapid.subject' => 'http://invalid.example.test',
            'webpush.vapid.public_key' => 'invalid-public-key',
            'webpush.vapid.private_key' => 'invalid-private-key',
        ]);
    }

    public function test_active_layouts_include_the_shared_pwa_head(): void
    {
        foreach (['master.blade.php', 'guest.blade.php', 'app.blade.php'] as $layout) {
            $contents = file_get_contents(resource_path('views/layouts/'.$layout));

            $this->assertStringContainsString("@include('layouts.pwa-head')", $contents);
        }

        Queue::fake();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('rel="manifest"', escape: false)
            ->assertSee('rel="apple-touch-icon"', escape: false)
            ->assertSee(route('pwa.icon', ['icon' => 'pwa-192.png']), escape: false)
            ->assertSee('name="apple-mobile-web-app-capable"', escape: false)
            ->assertSee('name="rt-service-worker-url"', escape: false)
            ->assertDontSee('name="rt-push-account-binding"', escape: false);
    }

    public function test_profile_exposes_a_reduced_settings_tab_for_push_notifications(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show', ['tab' => 'app']))
            ->assertOk()
            ->assertSee(__('app.settings'))
            ->assertSee(__('app.push_settings_title'))
            ->assertSee('data-testid="push-settings"', escape: false)
            ->assertDontSee(__('app.app_and_push'))
            ->assertDontSee('data-testid="push-settings-diagnostics"', escape: false)
            ->assertDontSee(__('app.push_preferences_title'))
            ->assertDontSee(__('app.push_send_test'))
            ->assertSee('railtimePushSettings(', escape: false)
            ->assertSee('name="rt-push-account-binding"', escape: false);

        $this->actingAs($user)
            ->get(route('profile.show', ['tab' => 'app']))
            ->assertOk()
            ->assertSee(__('app.settings'))
            ->assertSee('data-testid="push-settings"', escape: false)
            ->assertDontSee('data-testid="push-settings-diagnostics"', escape: false)
            ->assertDontSee(__('app.help_push_issue_disabled'))
            ->assertDontSee(__('app.push_preferences_title'))
            ->assertDontSee(__('app.push_send_test'));
    }

    public function test_push_settings_use_the_shared_server_configuration_validator(): void
    {
        $component = file_get_contents(app_path('Livewire/Settings/PushSettings.php'));

        $this->assertStringContainsString('WebPushConfiguration::diagnostics()', $component);
        $this->assertStringContainsString('WebPushConfiguration::accountBinding(', $component);
        $this->assertStringNotContainsString("filled(config('webpush.vapid.public_key'))", $component);
        $this->assertStringNotContainsString('privateKey', file_get_contents(
            resource_path('views/livewire/settings/push-settings.blade.php')
        ));
        $this->assertStringContainsString("'testEnabled' => (bool) config('webpush.test_enabled')", $component);
        $this->assertStringContainsString("'test' => route('push.test')", $component);
        $this->assertStringContainsString("'testQueued' => __('app.push_test_queued')", $component);
        $this->assertStringContainsString("'testFailed' => __('app.push_test_failed')", $component);
        $this->assertStringContainsString(
            'x-show.important="canTest"',
            file_get_contents(resource_path('views/livewire/settings/push-settings.blade.php'))
        );
    }

    public function test_manifest_is_scope_relative_and_references_valid_png_icons(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('manifest.webmanifest')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('./', $manifest['id']);
        $this->assertSame('./', $manifest['scope']);
        $this->assertSame('./?source=pwa', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#e4002b', $manifest['theme_color']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith('pwa-icons/', $icon['src']);
            $path = public_path('icons/'.basename($icon['src']));

            $this->assertFileExists($path);
            $image = getimagesize($path);
            $this->assertNotFalse($image);
            $this->assertSame('image/png', $image['mime']);
            $this->assertSame($icon['sizes'], $image[0].'x'.$image[1]);
        }

        $appleIcon = getimagesize(public_path('icons/apple-touch-icon-180.png'));
        $badge = getimagesize(public_path('icons/push-badge-96.png'));

        $this->assertSame([180, 180], [$appleIcon[0], $appleIcon[1]]);
        $this->assertSame([96, 96], [$badge[0], $badge[1]]);
        // favicon.ico muss MEHRERE kleine Groessen enthalten. Mit nur einer
        // 192px-Grafik blieben Tab-Uebersicht, Verlauf und Lesezeichen leer —
        // genau das war der gemeldete Fehler.
        $ico = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));

        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type'], 'favicon.ico muss ein ICO (Typ 1) sein.');
        $this->assertGreaterThanOrEqual(3, $header['count'], 'favicon.ico braucht mehrere Groessen.');

        $sizes = [];

        for ($index = 0; $index < $header['count']; $index++) {
            $entry = unpack(
                'Cwidth/Cheight/Ccolors/Creserved/vplanes/vbpp/Vsize/Voffset',
                substr($ico, 6 + ($index * 16), 16),
            );

            $sizes[] = $entry['width'] === 0 ? 256 : $entry['width'];

            // Jeder Eintrag muss vollstaendig in der Datei liegen.
            $this->assertLessThanOrEqual(strlen($ico), $entry['offset'] + $entry['size']);
            $this->assertNotFalse(getimagesizefromstring(substr($ico, $entry['offset'], $entry['size'])));
        }

        foreach ([16, 32, 48] as $required) {
            $this->assertContains($required, $sizes, "favicon.ico fehlt die Groesse {$required}px.");
        }
    }

    public function test_manifest_icons_have_a_public_laravel_fallback_for_incomplete_deployments(): void
    {
        foreach (array_keys(PwaIcon::DIMENSIONS) as $icon) {
            $response = $this->get(route('pwa.icon', ['icon' => $icon]));

            $response
                ->assertOk()
                ->assertHeader('content-type', 'image/png')
                ->assertHeader('x-content-type-options', 'nosniff');

            $this->get(route('pwa.icon.legacy', ['icon' => $icon]))
                ->assertOk()
                ->assertHeader('content-type', 'image/png');
        }

        $this->get('/icons/not-a-pwa-icon.png')->assertNotFound();
    }

    public function test_service_worker_uses_scope_safe_navigation_without_fetch_caching(): void
    {
        $serviceWorker = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString('self.registration.scope', $serviceWorker);
        $this->assertStringContainsString(
            "const FALLBACK_ICON = 'pwa-icons/pwa-192.png'",
            $serviceWorker,
        );
        $this->assertStringContainsString(
            "const FALLBACK_BADGE = 'pwa-icons/push-badge-96.png'",
            $serviceWorker,
        );
        $this->assertStringContainsString('url.origin === scope.origin', $serviceWorker);
        $this->assertStringContainsString('url.pathname.startsWith(scope.pathname)', $serviceWorker);
        $this->assertStringContainsString('includeUncontrolled: true', $serviceWorker);
        $this->assertStringContainsString(
            "client.visibilityState !== 'visible'",
            $serviceWorker
        );
        $this->assertStringContainsString('new MessageChannel()', $serviceWorker);
        $this->assertStringContainsString('FOREGROUND_ACK_TIMEOUT_MS', $serviceWorker);
        $this->assertStringContainsString('await requestForegroundContext(', $serviceWorker);
        $this->assertStringContainsString("type: 'railtime:push-context-request'", $serviceWorker);
        $this->assertStringContainsString('context.activeChatId === activeChatId', $serviceWorker);
        $this->assertStringContainsString('Number(right.context.focused)', $serviceWorker);
        $this->assertStringContainsString('await requestForegroundAck(', $serviceWorker);
        $this->assertStringContainsString('client.postMessage(message, [channel.port2])', $serviceWorker);
        $this->assertStringContainsString(
            'acknowledgement?.type === FOREGROUND_ACK_TYPE',
            $serviceWorker
        );
        $this->assertStringContainsString('if (acknowledged)', $serviceWorker);
        $this->assertStringContainsString(
            'await self.registration.showNotification(title, options)',
            $serviceWorker
        );
        $this->assertStringContainsString("type: 'railtime:push-received'", $serviceWorker);
        $this->assertStringContainsString('await client.navigate(target.href)', $serviceWorker);
        $this->assertStringContainsString('await self.clients.openWindow(target.href)', $serviceWorker);
        $this->assertStringNotContainsString("addEventListener('fetch'", $serviceWorker);
        $this->assertStringNotContainsString('caches.open(', $serviceWorker);
    }

    public function test_foreground_push_requires_an_explicit_ready_app_ack(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('!rtForegroundPushHandler', $script);
        $this->assertStringContainsString("document.visibilityState !== 'visible'", $script);
        $this->assertStringContainsString("event.data?.type === 'railtime:push-context-request'", $script);
        $this->assertStringContainsString('active_chat_id: snapshot.activeChatId', $script);
        $this->assertStringContainsString('const acknowledgementPort = event.ports?.[0]', $script);
        $this->assertStringContainsString(
            "type: 'railtime:push-received-ack'",
            $script
        );
        $this->assertStringNotContainsString('rtPendingForegroundPushMessages', $script);

        $handlerCall = strpos($script, 'rtForegroundPushHandler(event.data)');
        $ackPost = strpos($script, 'acknowledgementPort?.postMessage({');

        $this->assertNotFalse($handlerCall);
        $this->assertNotFalse($ackPost);
        $this->assertGreaterThan($handlerCall, $ackPost);
    }

    public function test_visible_open_chat_is_shared_with_the_notification_presenter(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $chatView = file_get_contents(resource_path('views/livewire/chat-box.blade.php'));
        $transcript = file_get_contents(resource_path('views/livewire/chat/partials/transcript.blade.php'));

        $this->assertStringContainsString(
            "import { createNotificationPresentationContext } from './notification-presentation'",
            $script
        );
        $this->assertStringContainsString(
            'rtNotificationContext.isChatVisible(chatId)',
            $script
        );
        $this->assertStringContainsString(
            'rtNotificationContext.isLocalChatVisible(chatId)',
            $script
        );
        $this->assertStringContainsString('data-active-chat-id=', $chatView);
        $this->assertStringContainsString('wire:poll.visible.2s="pollTick"', $transcript);
    }

    public function test_browser_permission_is_only_requested_inside_the_explicit_subscribe_action(): void
    {
        $script = file_get_contents(resource_path('js/pwa.js'));
        $subscribe = strpos($script, 'async subscribe()');
        $permission = strpos($script, 'Notification.requestPermission()');
        $existingSubscriptionSync = strpos($script, 'await syncExistingPushSubscription({');
        $statusFetch = strpos($script, 'const status = await apiRequest(this.config.urls.status)');

        $this->assertNotFalse($subscribe);
        $this->assertNotFalse($permission);
        $this->assertNotFalse($existingSubscriptionSync);
        $this->assertNotFalse($statusFetch);
        $this->assertGreaterThan($subscribe, $permission);
        $this->assertLessThan($statusFetch, $existingSubscriptionSync);
        $this->assertSame(1, substr_count($script, 'Notification.requestPermission()'));
        $this->assertStringContainsString("addEventListener('beforeinstallprompt'", $script);
        $this->assertStringContainsString('scope: serviceWorkerScope(scriptUrl.href)', $script);
        $this->assertStringContainsString(
            'rtSeenNotifications.take(notificationId)',
            file_get_contents(resource_path('js/app.js'))
        );
    }

    public function test_apache_serves_manifest_type_and_disables_service_worker_caching(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));
        $iconHtaccess = file_get_contents(public_path('icons/.htaccess'));

        $this->assertStringContainsString('AddType application/manifest+json .webmanifest', $htaccess);
        $this->assertStringContainsString('<Files "service-worker.js">', $htaccess);
        $this->assertStringContainsString('no-cache, no-store, must-revalidate', $htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $iconHtaccess);
        $this->assertStringContainsString(
            '^(?:pwa-192|pwa-512|pwa-maskable-512|apple-touch-icon-180|push-badge-96)\.png$',
            $iconHtaccess,
        );
        $this->assertStringContainsString('../index.php [L]', $iconHtaccess);
        $this->assertSame(1, substr_count($iconHtaccess, 'RewriteRule '));
    }
}
