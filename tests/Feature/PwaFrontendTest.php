<?php

namespace Tests\Feature;

use App\Models\User;
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
            'webpush.settings_ui_enabled' => true,
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
            ->assertSee('name="apple-mobile-web-app-capable"', escape: false)
            ->assertSee('name="rt-service-worker-url"', escape: false)
            ->assertDontSee('name="rt-push-account-binding"', escape: false);
    }

    public function test_profile_exposes_app_and_push_tab_only_behind_the_rollout_flag(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show', ['tab' => 'app']))
            ->assertOk()
            ->assertSee(__('app.app_and_push'))
            ->assertSee(__('app.push_settings_title'))
            ->assertSee('data-testid="push-settings"', escape: false)
            ->assertSee('railtimePushSettings(', escape: false)
            ->assertSee('name="rt-push-account-binding"', escape: false);

        config(['webpush.settings_ui_enabled' => false]);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('data-testid="push-settings"', escape: false);
    }

    public function test_push_settings_use_the_shared_server_configuration_validator(): void
    {
        $component = file_get_contents(app_path('Livewire/Settings/PushSettings.php'));

        $this->assertStringContainsString('WebPushConfiguration::isConfigured()', $component);
        $this->assertStringContainsString('WebPushConfiguration::accountBinding(', $component);
        $this->assertStringNotContainsString("filled(config('webpush.vapid.public_key'))", $component);
        $this->assertStringNotContainsString('privateKey', file_get_contents(
            resource_path('views/livewire/settings/push-settings.blade.php')
        ));
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
            $path = public_path($icon['src']);

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
        $this->assertGreaterThan(22, filesize(public_path('favicon.ico')));
        $this->assertSame(
            "\x00\x00\x01\x00\x01\x00",
            file_get_contents(public_path('favicon.ico'), length: 6),
        );
    }

    public function test_service_worker_uses_scope_safe_navigation_without_fetch_caching(): void
    {
        $serviceWorker = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString('self.registration.scope', $serviceWorker);
        $this->assertStringContainsString('url.origin === scope.origin', $serviceWorker);
        $this->assertStringContainsString('url.pathname.startsWith(scope.pathname)', $serviceWorker);
        $this->assertStringContainsString('includeUncontrolled: true', $serviceWorker);
        $this->assertStringContainsString(
            "client.visibilityState !== 'visible' || !client.focused",
            $serviceWorker
        );
        $this->assertStringContainsString('new MessageChannel()', $serviceWorker);
        $this->assertStringContainsString('FOREGROUND_ACK_TIMEOUT_MS', $serviceWorker);
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
        $this->assertStringContainsString('!document.hasFocus()', $script);
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

        $this->assertStringContainsString('AddType application/manifest+json .webmanifest', $htaccess);
        $this->assertStringContainsString('<Files "service-worker.js">', $htaccess);
        $this->assertStringContainsString('no-cache, no-store, must-revalidate', $htaccess);
    }
}
