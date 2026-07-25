<?php

namespace App\Listeners;

use App\Jobs\RetryWebPushDelivery;
use App\Support\Push\WebPushReport;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;

class HandleWebPushFailed
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->report->isSubscriptionExpired()) {
            if ($event->subscription->exists) {
                $event->subscription->delete();
            }

            return;
        }

        if ($event->subscription->exists) {
            $event->subscription->forceFill([
                'last_failure_at' => now(),
                'failure_count' => $event->subscription->failure_count + 1,
            ])->save();

            if (WebPushReport::isTransient($event->report)) {
                RetryWebPushDelivery::dispatch(
                    (int) $event->subscription->getKey(),
                    $event->message->toArray(),
                    $event->message->getOptions(),
                )->delay(now()->addSeconds(30));
            }
        }

        Log::warning('Web-Push-Zustellung fehlgeschlagen.', [
            'subscription_id' => $event->subscription->getKey(),
            'status' => $event->report->getResponse()?->getStatusCode(),
            'expired' => $event->report->isSubscriptionExpired(),
        ]);
    }
}
