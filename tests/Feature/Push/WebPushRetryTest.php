<?php

namespace Tests\Feature\Push;

use App\Jobs\RetryWebPushDelivery;
use App\Listeners\HandleWebPushFailed;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\RailtimeWebPushNotification;
use App\Support\Push\PushCategory;
use App\Support\Push\WebPushTransport;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use RuntimeException;
use Tests\TestCase;

class WebPushRetryTest extends TestCase
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
    }

    public function test_transient_reports_schedule_a_retry_for_only_the_failed_subscription(): void
    {
        Queue::fake();

        $subscription = $this->subscription();
        $message = $this->message();
        $listener = app(HandleWebPushFailed::class);

        foreach ([429, 503] as $status) {
            $listener->handle(new NotificationFailed(
                $this->failedReport($status),
                $subscription->fresh(),
                $message,
            ));
        }

        Queue::assertPushedTimes(RetryWebPushDelivery::class, 2);
        Queue::assertPushed(
            RetryWebPushDelivery::class,
            fn (RetryWebPushDelivery $job): bool => $job->subscriptionId === $subscription->id
                && ($job->payload['data']['notification_id'] ?? null) === 'message:42'
                && $job->delay !== null
        );
        $this->assertSame(2, $subscription->fresh()->failure_count);
    }

    public function test_expired_subscription_is_deleted_without_retry(): void
    {
        Queue::fake();

        $subscription = $this->subscription();

        app(HandleWebPushFailed::class)->handle(new NotificationFailed(
            $this->failedReport(410),
            $subscription,
            $this->message(),
        ));

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_retry_job_throws_for_a_transient_report_so_the_queue_applies_backoff(): void
    {
        $subscription = $this->subscription();
        $transport = $this->createMock(WebPushTransport::class);
        $transport
            ->expects($this->once())
            ->method('send')
            ->willReturn($this->failedReport(503));
        $job = new RetryWebPushDelivery(
            $subscription->id,
            $this->message()->toArray(),
            $this->message()->getOptions(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transient web-push delivery failure.');

        try {
            $job->handle($transport);
        } finally {
            $this->assertSame(1, $subscription->fresh()->failure_count);
        }
    }

    public function test_retry_job_records_success_without_touching_other_devices(): void
    {
        $subscription = $this->subscription();
        $otherSubscription = $this->subscription('https://fcm.googleapis.com/fcm/send/other');
        $transport = $this->createMock(WebPushTransport::class);
        $transport
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(fn (PushSubscription $model): bool => $model->is($subscription)))
            ->willReturn(new MessageSentReport(
                new Request('POST', $subscription->endpoint),
                new Response(201),
                true,
            ));
        $job = new RetryWebPushDelivery(
            $subscription->id,
            $this->message()->toArray(),
            $this->message()->getOptions(),
        );

        $job->handle($transport);

        $this->assertNotNull($subscription->fresh()->last_success_at);
        $this->assertNull($otherSubscription->fresh()->last_success_at);
    }

    public function test_queued_retry_is_suppressed_after_the_feature_flag_is_disabled(): void
    {
        $subscription = $this->subscription();
        $transport = $this->createMock(WebPushTransport::class);
        $transport->expects($this->never())->method('send');
        $job = new RetryWebPushDelivery(
            $subscription->id,
            $this->message()->toArray(),
            $this->message()->getOptions(),
        );
        config(['webpush.enabled' => false]);

        $job->handle($transport);

        $this->assertNull($subscription->fresh()->last_success_at);
        $this->assertSame(0, $subscription->fresh()->failure_count);
    }

    public function test_already_queued_notification_is_suppressed_after_feature_flag_is_disabled(): void
    {
        $subscription = $this->subscription();
        $user = $subscription->subscribable;
        $user->enableDefaultPushPreferences();
        $notification = new RailtimeWebPushNotification(
            notificationId: 'message:42',
            title: 'Neue Nachricht',
            body: 'In RailTime liegt eine neue Nachricht vor.',
            url: 'messages?open=42',
            category: PushCategory::Messages,
        );
        $channel = $this->createMock(WebPushChannel::class);
        $channel->expects($this->never())->method('send');
        $this->app->instance(WebPushChannel::class, $channel);
        $queued = new SendQueuedNotifications(
            $user,
            $notification,
            [WebPushChannel::class],
        );
        config(['webpush.enabled' => false]);

        $queued->handle(app(ChannelManager::class));

        $this->assertFalse($notification->shouldSend($user, WebPushChannel::class));
    }

    public function test_queued_notification_rechecks_preferences_and_active_device(): void
    {
        $subscription = $this->subscription();
        $user = $subscription->subscribable;
        $user->enableDefaultPushPreferences();
        $notification = new RailtimeWebPushNotification(
            notificationId: 'message:42',
            title: 'Neue Nachricht',
            body: 'In RailTime liegt eine neue Nachricht vor.',
            url: 'messages?open=42',
            category: PushCategory::Messages,
        );

        $this->assertTrue($notification->shouldSend($user, WebPushChannel::class));

        $user->notificationPreferences()
            ->where('category', 'messages')
            ->update(['web_push_enabled' => false]);

        $this->assertFalse($notification->shouldSend($user, WebPushChannel::class));

        $subscription->update(['revoked_at' => now()]);
        $notificationWithBypass = new RailtimeWebPushNotification(
            notificationId: 'test:42',
            title: 'Test',
            body: 'Test',
            url: 'user/profile?tab=app',
            category: PushCategory::Messages,
            bypassPreferences: true,
        );

        $this->assertFalse($notificationWithBypass->shouldSend($user, WebPushChannel::class));
    }

    public function test_retry_rechecks_the_current_category_preference(): void
    {
        $subscription = $this->subscription();
        $user = $subscription->subscribable;
        $user->notificationPreferences()
            ->where('category', 'messages')
            ->update(['web_push_enabled' => false]);
        $transport = $this->createMock(WebPushTransport::class);
        $transport->expects($this->never())->method('send');
        $job = new RetryWebPushDelivery(
            $subscription->id,
            $this->message()->toArray(),
            $this->message()->getOptions(),
        );

        $job->handle($transport);

        $this->assertSame(0, $subscription->fresh()->failure_count);
    }

    private function subscription(
        string $endpoint = 'https://fcm.googleapis.com/fcm/send/retry-test',
    ): PushSubscription {
        $user = User::factory()->create();
        $user->enableDefaultPushPreferences();

        return $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    private function message(): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Neue Nachricht')
            ->body('In RailTime liegt eine neue Nachricht vor.')
            ->data([
                'notification_id' => 'message:42',
                'url' => 'messages?open=42',
                'category' => 'messages',
            ])
            ->options(['TTL' => 3600]);
    }

    private function failedReport(int $status): MessageSentReport
    {
        return new MessageSentReport(
            new Request('POST', 'https://fcm.googleapis.com/fcm/send/retry-test'),
            new Response($status),
            false,
            'HTTP '.$status,
        );
    }
}
