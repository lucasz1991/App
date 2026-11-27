<?php

namespace App\Providers;

use App\Listeners\EmbedSystemMailImages;
use App\Listeners\HandleWebPushFailed;
use App\Listeners\HandleWebPushSent;
use App\Listeners\ScheduleOutlookSnapshotForTeamMember;
use App\Listeners\StopChatLiveLocationsOnLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Logout::class => [
            StopChatLiveLocationsOnLogout::class,
        ],
        NotificationSent::class => [
            HandleWebPushSent::class,
        ],
        NotificationFailed::class => [
            HandleWebPushFailed::class,
        ],
        MessageSending::class => [
            EmbedSystemMailImages::class,
        ],
        TeamMemberAdded::class => [
            ScheduleOutlookSnapshotForTeamMember::class,
        ],
        TeamMemberRemoved::class => [
            ScheduleOutlookSnapshotForTeamMember::class,
        ],
        TeamMemberUpdated::class => [
            ScheduleOutlookSnapshotForTeamMember::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        foreach ([\App\Models\Order::class, \App\Models\Shift::class, \App\Models\OperationInquiry::class] as $type) {
            $type::saved(function ($record) use ($type) {
                if (! $record->wasRecentlyCreated && ! $record->wasChanged(['location_name', 'street', 'city', 'postal_code', 'country', 'order_id', 'location_snapshot'])) {
                    return;
                }
                $id = (int) $record->getKey();
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($type, $id) {
                    // Pending work is also recovered on view and by the scheduled batch.
                    try {
                        $settings = \App\Support\Routing\TomTomSettings::all();
                        if ($settings['enabled'] && $settings['has_api_key'] && $settings['server_cache_enabled']) {
                            $job = Artisan::queue('routing:resolve-worksites', ['--model' => $type, '--id' => $id]);
                            if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
                                $job->afterResponse();
                            }
                        }
                    } catch (\Throwable $error) {
                        report($error);
                    }
                });
            });
        }
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
