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
use Illuminate\Support\Facades\Event;
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
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
