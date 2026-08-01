<?php

namespace App\Livewire\Calls;

use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Calls\CallConversationService;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\RoomEventRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Anrufe: Verlauf aller Gespraeche, an denen der Nutzer beteiligt war.
 *
 * Gemeinsamer Hub fuer Chat-Anrufe, offene Meetings und deren Historie.
 */
class CallHistory extends Component
{
    use WithPagination;

    /** all|missed|active|meetings|recordings */
    public string $filter = 'all';

    public string $name = '';

    public bool $video = true;

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'missed', 'active', 'meetings', 'recordings'], true)
            ? $filter
            : 'all';
        $this->resetPage();
    }

    /** @return array<string, array<int, string>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
        ];
    }

    public function createMeeting(
        LiveKitService $livekit,
        CallConversationService $conversations,
        RoomEventRecorder $events,
    ) {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.start'), 403);
        $this->validate();

        $room = DB::transaction(function () use ($user, $conversations, $events): Room {
            $room = Room::create([
                'name' => trim($this->name),
                'type' => 'meeting',
                'status' => 'pending',
                'owner_id' => $user->id,
                'settings' => ['video' => $this->video],
            ]);

            $room->participants()->create([
                'user_id' => $user->id,
                'role' => 'host',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($user),
            ]);

            $conversations->createForRoom($room, $user);
            $events->record($room, 'created', $user, ['type' => 'meeting']);

            return $room;
        });

        if (! $livekit->createRoom($room)) {
            $room->forceFill([
                'status' => 'cancelled',
                'ended_at' => now(),
                'ended_reason' => 'infrastructure_unavailable',
            ])->save();
            $events->record($room, 'cancelled', $user, ['reason' => 'infrastructure_unavailable']);

            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        $this->reset(['name', 'video']);
        $this->video = true;

        return redirect()->route('calls.window', $room);
    }

    public function join(
        int $roomId,
        CallConversationService $conversations,
        RoomEventRecorder $events,
    ) {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);

        $room = Room::query()->where('type', 'meeting')->findOrFail($roomId);
        abort_unless($room->isActive(), 410, __('app.calls_ended'));

        RoomParticipant::firstOrCreate(
            ['room_id' => $room->id, 'user_id' => $user->id],
            [
                'role' => 'speaker',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($user),
            ],
        );

        $conversations->attachParticipant($room, $user);
        $events->record($room, 'accepted', $user);

        return redirect()->route('calls.window', $room);
    }

    /** Raeume, an denen der Nutzer beteiligt war – neueste zuerst. */
    protected function rooms(): LengthAwarePaginator
    {
        $userId = auth()->id();

        return Room::query()
            ->with(['owner', 'chat', 'callChat', 'participants.user'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->when($this->filter === 'active', fn ($q) => $q->whereIn('status', ['pending', 'active']))
            ->when($this->filter === 'meetings', fn ($q) => $q->where('type', 'meeting'))
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
            'liveMeetings' => Room::query()
                ->with(['owner', 'participants'])
                ->where('type', 'meeting')
                ->whereIn('status', ['pending', 'active'])
                ->latest('id')
                ->get(),
            'canStart' => auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.start'),
        ])->layout('layouts.master');
    }
}
