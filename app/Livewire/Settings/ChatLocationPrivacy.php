<?php

namespace App\Livewire\Settings;

use App\Models\ChatLiveLocation;
use App\Models\ChatLocationPoint;
use App\Models\User;
use App\Services\Chat\ChatLocationHistoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ChatLocationPrivacy extends Component
{
    #[Locked]
    public int $ownerUserId = 0;

    public function mount(): void
    {
        $this->ownerUserId = (int) $this->actor()->id;
    }

    public function revokeAll(ChatLocationHistoryService $history): void
    {
        $actor = $this->actor();
        abort_unless(Schema::hasTable('chat_location_points'), 409);
        ChatLiveLocation::where('user_id', $actor->id)->whereNotNull('profile_history_accepted_at')
            ->whereNull('profile_history_withdrawn_at')->chunkById(100, function ($shares) use ($history, $actor): void {
                foreach ($shares as $share) {
                    $history->withdraw($share, $actor);
                }
            });
        $this->dispatch('chat-profile-history-withdrawn');
    }

    public function render(): View
    {
        $actor = $this->actor();
        $ready = Schema::hasTable('chat_location_points');
        $count = $ready ? ChatLocationPoint::where('user_id', $actor->id)->where('measured_at', '>', now()->utc()->subDays(7))->count() : 0;
        $shares = $ready ? ChatLiveLocation::where('user_id', $actor->id)->whereNotNull('profile_history_accepted_at')
            ->whereNull('profile_history_withdrawn_at')->count() : 0;

        return view('livewire.settings.chat-location-privacy', ['pointCount' => $count, 'shareCount' => $shares, 'ready' => $ready]);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && ($this->ownerUserId === 0 || (int) $actor->id === $this->ownerUserId), 403);
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isActive(), 403);

        return $actor;
    }
}
