<?php

namespace App\Livewire\Calls;

use App\Models\Room;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Anrufe: Verlauf aller Gespraeche, an denen der Nutzer beteiligt war.
 *
 * Bewusst nur lesend. Gestartet werden Anrufe im Chat (Direkt-/Gruppenanruf)
 * oder unter Meetings (freier Raum) – hier geht es um Nachvollziehbarkeit:
 * Wer hat wann angerufen, wurde angenommen, wie lange lief es.
 */
class CallHistory extends Component
{
    use WithPagination;

    /** all|missed|active */
    public string $filter = 'all';

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'missed', 'active'], true) ? $filter : 'all';
        $this->resetPage();
    }

    /** Raeume, an denen der Nutzer beteiligt war – neueste zuerst. */
    protected function rooms(): LengthAwarePaginator
    {
        $userId = auth()->id();

        return Room::query()
            ->with(['owner', 'chat', 'participants.user'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->when($this->filter === 'active', fn ($q) => $q->whereIn('status', ['pending', 'active']))
            ->when($this->filter === 'missed', fn ($q) => $q->whereHas(
                'invitations',
                fn ($i) => $i->where('invitee_id', $userId)->whereIn('status', ['missed', 'declined', 'expired']),
            ))
            ->latest('id')
            ->paginate(15);
    }

    public function render()
    {
        $userId = auth()->id();

        return view('livewire.calls.call-history', [
            'rooms' => $this->rooms(),
            'currentUserId' => $userId,
        ])->layout('layouts.master');
    }
}
