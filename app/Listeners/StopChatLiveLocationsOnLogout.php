<?php

namespace App\Listeners;

use App\Models\ChatLiveLocation;
use App\Models\User;
use App\Services\Chat\ChatLiveLocationService;
use Illuminate\Auth\Events\Logout;

class StopChatLiveLocationsOnLogout
{
    public function __construct(protected ChatLiveLocationService $locations) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->locations->stopForUser($event->user, ChatLiveLocation::STOP_LOGOUT);
    }
}
