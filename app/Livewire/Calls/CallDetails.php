<?php

namespace App\Livewire\Calls;

use App\Models\Room;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CallDetails extends Component
{
    #[Locked]
    public bool $embedded = false;

    public Room $room;

    public function mount(Room $room, bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $user = auth()->user();

        abort_unless($user && $room->callChat()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->exists(), 403, __('app.calls_permission_denied'));

        $this->room = $room;
    }

    public function render()
    {
        $this->room->load([
            'owner',
            'participants.user',
            'events.actor',
            'callChat',
            'recording',
        ]);

        return view('livewire.calls.call-details')
            ->layout('layouts.master');
    }
}
