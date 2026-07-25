<?php

namespace Tests\Feature\Push;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Notifications\RailtimeWebPushNotification;
use App\Support\Push\PushCategory;
use App\Support\Push\PushDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $vapid = VAPID::createVapidKeys();

        config([
            'webpush.enabled' => true,
            'webpush.vapid.subject' => 'mailto:webpush@example.test',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);
        Notification::fake();
    }

    public function test_internal_message_notifies_only_the_recipient_with_a_generic_payload(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $recipient->enableDefaultPushPreferences();
        $this->subscribe($recipient, 'message-recipient');

        $sender->sendMessage(
            $recipient->id,
            'Vertraulicher Dienstplan',
            'Die geheime Schicht beginnt um 04:30 Uhr.',
        );

        $message = Message::query()->sole();

        Notification::assertNotSentTo($sender, RailtimeWebPushNotification::class);
        Notification::assertSentTo(
            $recipient,
            RailtimeWebPushNotification::class,
            function (RailtimeWebPushNotification $notification) use ($message): bool {
                $payload = $notification->toWebPush(new \stdClass, $notification)->toArray();
                $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

                return $notification->notificationId === 'message:'.$message->id
                    && $notification->url === 'messages?open='.$message->id
                    && $notification->category === PushCategory::Messages
                    && ! str_contains($serialized, 'Vertraulicher Dienstplan')
                    && ! str_contains($serialized, '04:30');
            }
        );
    }

    public function test_chat_message_notifies_active_participants_except_the_sender(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $inactiveRecipient = User::factory()->create(['status' => false]);
        $recipient->enableDefaultPushPreferences();
        $inactiveRecipient->enableDefaultPushPreferences();
        $this->subscribe($recipient, 'chat-recipient');
        $this->subscribe($inactiveRecipient, 'inactive-recipient');
        $chat = Chat::query()->create([
            'type' => 'group',
            'name' => 'Disposition',
            'created_by' => $sender->id,
        ]);
        $chat->participants()->attach([$sender->id, $recipient->id, $inactiveRecipient->id]);
        $message = ChatMessage::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Vertraulicher Chatinhalt',
            'message_type' => 'text',
        ]);

        app(PushDelivery::class)->chatMessageReceived($message);

        Notification::assertNotSentTo($sender, RailtimeWebPushNotification::class);
        Notification::assertNotSentTo($inactiveRecipient, RailtimeWebPushNotification::class);
        Notification::assertSentTo(
            $recipient,
            RailtimeWebPushNotification::class,
            fn (RailtimeWebPushNotification $notification): bool => $notification->notificationId === 'chat-message:'.$message->id
                && $notification->url === 'chat?chat='.$chat->id
                && $notification->category === PushCategory::Chat
        );
    }

    public function test_notification_channel_respects_preferences_and_feature_flag(): void
    {
        Notification::fake(false);

        $user = User::factory()->create();
        $this->subscribe($user, 'preference-test');
        $notification = new RailtimeWebPushNotification(
            notificationId: 'message:42',
            title: 'Neue Nachricht',
            body: 'RailTime enthaelt eine neue Nachricht.',
            url: 'messages?open=42',
            category: PushCategory::Messages,
        );

        $this->assertSame([], $notification->via($user));

        $user->notificationPreferences()->create([
            'category' => PushCategory::Messages->value,
            'web_push_enabled' => true,
        ]);

        $this->assertNotSame([], $notification->via($user));

        config(['webpush.enabled' => false]);

        $this->assertSame([], $notification->via($user));
    }

    public function test_deleting_a_user_removes_encrypted_device_subscriptions(): void
    {
        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/delete-me',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        $this->assertDatabaseCount('push_subscriptions', 1);

        $user->delete();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    private function subscribe(User $user, string $suffix): void
    {
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }
}
