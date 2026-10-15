<?php

namespace App\Livewire;

use App\Models\Room;
use Livewire\Component;

/** Existing URLs remain deep links; the persistent host owns the actual UI. */
class CommunicationPage extends Component
{
    public string $tab = 'chats';

    public ?string $roomUuid = null;

    public ?string $historyRoomUuid = null;

    public ?int $chatId = null;

    public function mount(?Room $room = null): void
    {
        $this->tab = match (request()->route()?->getName()) {
            'calls.index', 'calls.window', 'calls.history' => 'calls',
            'meetings' => 'meetings',
            default => 'chats',
        };
        $this->chatId = request()->integer('chat') ?: null;
        if ($room?->exists) {
            if (request()->route()?->getName() === 'calls.history') {
                abort_unless($room->callChat()->whereHas('participants', fn ($q) => $q
                    ->where('users.id', auth()->id())->whereNull('chat_user.hidden_at'))->exists(), 403);
                $this->historyRoomUuid = $room->uuid;

                return;
            }
            abort_unless(auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.join'), 403);
            abort_unless($room->isActive(), 410);
            abort_unless($room->mayEnter(auth()->user()), 403);
            $this->roomUuid = $room->uuid;
        }
    }

    public function render()
    {
        return view('livewire.communication-page')->layout('layouts.master', ['contentMode' => 'viewport']);
    }
}
