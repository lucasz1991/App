<?php

namespace App\Notifications;

use App\Services\Operations\EmployeeRequestNotificationService;
use App\Support\Push\PushCategory;
use NotificationChannels\WebPush\WebPushChannel;

class EmployeeRequestWebPushNotification extends RailtimeWebPushNotification
{
    public function __construct(public readonly int $receiptId, string $notificationId, string $title, string $body, string $url)
    {
        parent::__construct($notificationId, $title, $body, $url, PushCategory::Messages);
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return parent::shouldSend($notifiable, $channel)
            && ($channel !== WebPushChannel::class || app(EmployeeRequestNotificationService::class)->mayNotify($this->receiptId, (int) $notifiable->getKey()));
    }
}
