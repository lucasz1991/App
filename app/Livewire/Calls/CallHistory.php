<?php

namespace App\Livewire\Calls;

use App\Events\CallInvitationAnswered;
use App\Models\Room;
use App\Models\RoomInvitation;
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
                'team_id' => $user->current_team_id,
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

        [$room, $answeredInvitation, $recordAccepted] = DB::transaction(function () use ($roomId, $user): array {
            $room = Room::query()
                ->where('type', 'meeting')
                ->lockForUpdate()
                ->findOrFail($roomId);

            abort_unless($room->isActive(), 410, __('app.calls_ended'));

            $participant = $room->participants()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            // Eine Moderationsentfernung ist eine ausdrueckliche Sperre. Der
            // offene Meeting-Hub darf sie weder zuruecksetzen noch durch einen
            // Call-Chat-Anhang faktisch umgehen.
            abort_if($participant?->isRemoved(), 403, __('app.calls_permission_denied'));

            $participantWasCreated = false;

            if (! $participant) {
                $participant = $room->participants()->create([
                    'user_id' => $user->id,
                    'role' => 'speaker',
                    'connection' => 'invited',
                    'livekit_identity' => LiveKitService::identityFor($user),
                ]);
                $participantWasCreated = true;
            } elseif ($participant->connectionState() === 'left') {
                $participant->forceFill([
                    'connection' => 'invited',
                    'left_at' => null,
                ])->save();
            }

            $latestInvitation = $room->invitations()
                ->where('invitee_id', $user->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $answeredInvitation = null;
            $recordAccepted = $participantWasCreated;

            if ($latestInvitation?->status === 'pending') {
                // Ein ausdruecklicher Klick im offenen Hub gilt auch bei einer
                // inzwischen abgelaufenen Klingel-Einladung als Annahme.
                $latestInvitation->forceFill([
                    'status' => 'accepted',
                    'responded_at' => now(),
                ])->save();
                $answeredInvitation = $latestInvitation;
                $recordAccepted = true;
            } elseif ($latestInvitation && in_array($latestInvitation->status, ['declined', 'missed', 'expired'], true)) {
                // Den alten Versuch unveraendert lassen. Der bewusste spaetere
                // Open-Meeting-Beitritt ist ein neuer, nachvollziehbarer
                // akzeptierter Versuch und wird dadurch zur neuesten Wahrheit
                // fuer Room::mayJoin().
                $answeredInvitation = $room->invitations()->create([
                    'inviter_id' => $room->owner_id,
                    'invitee_id' => $user->id,
                    'status' => 'accepted',
                    'expires_at' => now(),
                    'responded_at' => now(),
                ]);
                $recordAccepted = true;
            }

            return [$room, $answeredInvitation, $recordAccepted];
        });

        // Erst nach der gesperrten Zustands- und Entfernungspruefung wird der
        // persistente Call-Chat freigeschaltet.
        abort_unless($room->mayJoin($user), 403, __('app.calls_permission_denied'));
        $conversations->attachParticipant($room, $user);

        if ($recordAccepted) {
            $events->record($room, 'accepted', $user, ['source' => 'open_meeting']);
        }

        if ($answeredInvitation instanceof RoomInvitation) {
            broadcast(new CallInvitationAnswered($answeredInvitation))->toOthers();
        }

        return redirect()->route('calls.window', $room);
    }

    /** Raeume, an denen der Nutzer beteiligt war – neueste zuerst. */
    protected function rooms(): LengthAwarePaginator
    {
        $userId = auth()->id();

        return Room::query()
            ->with([
                'owner',
                'chat',
                'callChat.participants:id,name,profile_photo_path',
                'participants.user',
                'recording',
                'invitations' => fn ($query) => $query->where('invitee_id', $userId)->latest('id'),
            ])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->when($this->filter === 'active', fn ($q) => $q->whereIn('status', ['pending', 'active']))
            ->when($this->filter === 'meetings', fn ($q) => $q->where('type', 'meeting'))
            ->when($this->filter === 'missed', fn ($q) => $q->whereHas(
                'invitations',
                fn ($i) => $i
                    ->where('invitee_id', $userId)
                    ->whereIn('status', ['missed', 'declined', 'expired'])
                    ->whereNotExists(function ($newer): void {
                        $newer->selectRaw('1')
                            ->from('room_invitations as newer_invitation')
                            ->whereColumn('newer_invitation.room_id', 'room_invitations.room_id')
                            ->whereColumn('newer_invitation.invitee_id', 'room_invitations.invitee_id')
                            ->whereColumn('newer_invitation.id', '>', 'room_invitations.id');
                    }),
            ))
            ->when($this->filter === 'recordings', fn ($q) => $q->whereHas('recording'))
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
