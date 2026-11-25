<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Models\Room;
use App\Services\Calls\CallAvailabilityService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** One persistent owner for communication, independent of the current page. */
class Communication extends Component
{
    #[Locked]
    public ?string $activeRoomUuid = null;

    #[Locked]
    public ?string $historyRoomUuid = null;

    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->refreshUnread();
    }

    #[On('inbox:refresh')]
    public function refreshUnread(): void
    {
        $userId = (int) auth()->id();

        $this->unreadCount = ChatMessage::query()
            ->visibleToParticipant($userId)
            ->excludingCallChats()
            ->unreadByParticipant($userId)
            ->count();
    }

    public function openCall(string $roomUuid): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isAdmin() || $user->hasRbacPermission('calls.join')), 403);
        $room = Room::where('uuid', $roomUuid)->firstOrFail();
        abort_unless($room->isActive(), 410, __('app.calls_ended'));
        abort_unless($room->mayEnter($user), 403);

        if ($this->activeRoomUuid && $this->activeRoomUuid !== $room->uuid) {
            $this->dispatch('swal:toast', type: 'warning', text: 'Bitte den laufenden Anruf zuerst beenden.');
            $this->dispatch('communication:call-ready', roomUuid: $this->activeRoomUuid);

            return;
        }

        app(CallAvailabilityService::class)->assertAvailable($user, $room);

        $this->activeRoomUuid = $room->uuid;
        $this->dispatch('communication:call-ready', roomUuid: $room->uuid, name: $room->name);
    }

    #[On('communication:call-ended')]
    public function releaseCall(string $roomUuid): void
    {
        if ($this->activeRoomUuid !== $roomUuid) {
            return;
        }

        $this->activeRoomUuid = null;
        $this->dispatch('communication:call-released');
        $this->dispatch('chat:timeline-refresh');
    }

    #[On('communication:history')]
    public function openHistory(string $roomUuid): void
    {
        $room = Room::where('uuid', $roomUuid)->firstOrFail();
        abort_unless($room->callChat()->whereHas('participants', fn ($query) => $query
            ->where('users.id', auth()->id())->whereNull('chat_user.hidden_at'))->exists(), 403);
        $this->historyRoomUuid = $room->uuid;
        $this->dispatch('communication:history-ready');
    }

    public function render()
    {
        return view('livewire.communication', [
            'callsAvailable' => Schema::hasTable('rooms') && Schema::hasTable('room_participants')
                && Schema::hasTable('room_invitations') && Schema::hasTable('room_events'),
        ]);
    }
}
