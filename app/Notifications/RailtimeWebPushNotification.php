<?php

namespace App\Notifications;

use App\Support\Push\PushCategory;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RailtimeWebPushNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $notificationId,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly PushCategory $category,
        public readonly bool $bypassPreferences = false,
        public readonly ?int $targetSubscriptionId = null,
    ) {
        $this->onQueue(config('webpush.queue', 'webpush'));
    }

    public function via(object $notifiable): array
    {
        return $this->shouldSend($notifiable, WebPushChannel::class)
            ? [WebPushChannel::class]
            : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== WebPushChannel::class) {
            return true;
        }

        $subscriptions = $notifiable->pushSubscriptions()->whereNull('revoked_at');

        if ($this->targetSubscriptionId !== null) {
            $subscriptions->whereKey($this->targetSubscriptionId);
        }

        if (! config('webpush.enabled')
            || ! WebPushConfiguration::isConfigured()
            || $subscriptions->doesntExist()) {
            return false;
        }

        return $this->bypassPreferences || $notifiable->wantsWebPush($this->category);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('icons/pwa-192.png')
            ->badge('icons/push-badge-96.png')
            ->tag($this->notificationId)
            ->data([
                'notification_id' => $this->notificationId,
                'url' => $this->url,
                'category' => $this->category->value,
            ])
            ->options([
                'TTL' => config('webpush.default_ttl', 3600),
                'urgency' => $this->category === PushCategory::Chat ? 'high' : 'normal',
            ]);
    }
}
