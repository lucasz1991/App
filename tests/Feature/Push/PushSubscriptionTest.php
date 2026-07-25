<?php

namespace Tests\Feature\Push;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\RailtimeWebPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/railtime-test-endpoint';

    protected function setUp(): void
    {
        parent::setUp();

        $vapid = VAPID::createVapidKeys();

        config([
            'webpush.enabled' => true,
            'webpush.settings_ui_enabled' => true,
            'webpush.test_enabled' => true,
            'webpush.vapid.subject' => 'mailto:webpush@example.test',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
            'webpush.allowed_endpoint_hosts' => ['fcm.googleapis.com'],
        ]);
    }

    public function test_authenticated_user_can_register_an_encrypted_subscription_and_default_preferences(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();

        $response = $this->actingAs($user)->postJson(route('push.subscriptions.store'), $payload);

        $response
            ->assertCreated()
            ->assertJson(['subscribed' => true]);

        $subscription = PushSubscription::query()->sole();

        $this->assertTrue($user->ownsPushSubscription($subscription));
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame($payload['keys']['p256dh'], $subscription->public_key);
        $this->assertSame($payload['keys']['auth'], $subscription->auth_token);
        $this->assertSame(hash('sha256', self::ENDPOINT), $subscription->endpoint_hash);
        $this->assertNotSame(self::ENDPOINT, $subscription->getRawOriginal('endpoint'));
        $this->assertNotSame($payload['keys']['p256dh'], $subscription->getRawOriginal('public_key'));
        $this->assertNotSame($payload['keys']['auth'], $subscription->getRawOriginal('auth_token'));
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'messages',
            'web_push_enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'chat',
            'web_push_enabled' => true,
        ]);
    }

    public function test_subscription_endpoint_can_only_belong_to_one_user(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $secondUser->notificationPreferences()->create([
            'category' => 'messages',
            'web_push_enabled' => false,
        ]);

        $this->actingAs($firstUser)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCreated();

        $this->actingAs($secondUser)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCreated();

        $subscription = PushSubscription::query()->sole();

        $this->assertSame($secondUser->id, $subscription->subscribable_id);
        $this->assertSame(0, $firstUser->pushSubscriptions()->count());
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $secondUser->id,
            'category' => 'messages',
            'web_push_enabled' => false,
        ]);
    }

    public function test_user_can_revoke_and_restore_only_their_own_device(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($owner)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCreated();
        $subscriptionId = PushSubscription::query()->sole()->getKey();

        $this->actingAs($otherUser)
            ->deleteJson(route('push.subscriptions.destroy'), ['subscription_id' => $subscriptionId])
            ->assertNoContent();

        $this->assertNull(PushSubscription::query()->sole()->revoked_at);

        $this->actingAs($owner)
            ->deleteJson(route('push.subscriptions.destroy'), ['subscription_id' => $subscriptionId])
            ->assertNoContent();

        $this->assertNotNull(PushSubscription::query()->sole()->revoked_at);

        $this->actingAs($owner)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCreated();

        $this->assertNull(PushSubscription::query()->sole()->revoked_at);
    }

    public function test_endpoint_validation_rejects_non_https_and_unapproved_hosts(): void
    {
        $user = User::factory()->create();

        foreach ([
            'http://fcm.googleapis.com/fcm/send/test',
            'https://fcm.googleapis.com.evil.example/fcm/send/test',
            'https://127.0.0.1/fcm/send/test',
        ] as $endpoint) {
            $this->actingAs($user)
                ->postJson(route('push.subscriptions.store'), $this->payload($endpoint))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('endpoint');
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscription_validation_rejects_malformed_browser_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push.subscriptions.store'), array_replace_recursive($this->payload(), [
                'keys' => [
                    'p256dh' => $this->base64UrlEncode("\x04".str_repeat("\x01", 64)),
                    'auth' => $this->base64UrlEncode(str_repeat("\x02", 15)),
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_status_requires_a_valid_vapid_subject(): void
    {
        $user = User::factory()->create();

        config(['webpush.vapid.subject' => 'http://insecure.example.test']);

        $this->actingAs($user)
            ->getJson(route('push.status'))
            ->assertOk()
            ->assertJsonPath('configured', false);
    }

    public function test_preferences_and_test_notification_are_scoped_to_authenticated_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCreated();
        $subscriptionId = PushSubscription::query()->sole()->getKey();

        $this->patchJson(route('push.preferences.update'), [
            'preferences' => [
                'messages' => false,
                'chat' => true,
            ],
        ])->assertOk();

        $this->getJson(route('push.status'))
            ->assertOk()
            ->assertJsonPath('subscription_count', 1)
            ->assertJsonPath('preferences.messages', false)
            ->assertJsonPath('preferences.chat', true);

        $this->postJson(route('push.test'), ['subscription_id' => $subscriptionId])
            ->assertAccepted()
            ->assertJson(['queued' => true]);

        Notification::assertSentTo(
            $user,
            RailtimeWebPushNotification::class,
            fn (RailtimeWebPushNotification $notification): bool => $notification->bypassPreferences
                && str_starts_with($notification->notificationId, 'test:')
                && $notification->url === 'user/profile?tab=app'
                && $notification->targetSubscriptionId === $subscriptionId
        );
    }

    public function test_test_notification_targets_only_the_owned_current_device(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCreated();
        $target = PushSubscription::query()->sole();

        $this->postJson(
            route('push.subscriptions.store'),
            $this->payload('https://fcm.googleapis.com/fcm/send/railtime-second-endpoint'),
        )->assertCreated();

        $this->actingAs($otherUser)
            ->postJson(
                route('push.subscriptions.store'),
                $this->payload('https://fcm.googleapis.com/fcm/send/railtime-other-endpoint'),
            )
            ->assertCreated();
        $otherSubscription = $otherUser->pushSubscriptions()->sole();

        $this->actingAs($user)
            ->postJson(route('push.test'), ['subscription_id' => $target->getKey()])
            ->assertAccepted();

        Notification::assertSentTo(
            $user,
            RailtimeWebPushNotification::class,
            function (RailtimeWebPushNotification $notification) use ($user, $target): bool {
                return $notification->targetSubscriptionId === $target->getKey()
                    && $user->routeNotificationForWebPush($notification)->modelKeys() === [$target->getKey()];
            },
        );

        $this->postJson(route('push.test'), [
            'subscription_id' => $otherSubscription->getKey(),
        ])->assertUnprocessable();
    }

    public function test_push_routes_require_authentication(): void
    {
        $this->getJson(route('push.status'))->assertUnauthorized();
        $this->postJson(route('push.subscriptions.store'), $this->payload())->assertUnauthorized();
        $this->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
        $this->patchJson(route('push.preferences.update'), ['preferences' => []])->assertUnauthorized();
        $this->postJson(route('push.test'))->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $this->validP256PublicKey(),
                'auth' => $this->base64UrlEncode(str_repeat("\x02", 16)),
            ],
            'contentEncoding' => 'aes128gcm',
            'platform' => 'android',
            'browser' => 'Chrome',
            'deviceName' => 'Testgeraet',
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function validP256PublicKey(): string
    {
        static $encodedKey;

        if (is_string($encodedKey)) {
            return $encodedKey;
        }

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($privateKey);

        $this->assertIsArray($details);
        $this->assertArrayHasKey('ec', $details);

        return $encodedKey = $this->base64UrlEncode(
            "\x04".$details['ec']['x'].$details['ec']['y'],
        );
    }
}
