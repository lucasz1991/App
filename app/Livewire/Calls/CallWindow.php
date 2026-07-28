<?php

namespace App\Livewire\Calls;

use App\Events\CallModerationActioned;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\RoomLifecycleService;
use Livewire\Component;

/**
 * Anruf-Fenster: Livewire haelt Raumdaten und Moderationsaktionen,
 * die eigentliche Medienlogik uebernimmt das Alpine-Modul resources/js/calls.js.
 *
 * Moderation ist server-autoritativ (RoomServiceClient an der SFU);
 * Reverb spiegelt Aktionen nur als UI-Feedback.
 */
class CallWindow extends Component
{
    public Room $room;

    public function mount(Room $room): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);

        abort_unless(
            $room->participantFor($user) !== null || $room->canModerate($user),
            403,
            __('app.calls_permission_denied'),
        );

        abort_unless($room->isActive(), 410, __('app.calls_ended'));

        // Moderatoren ohne Einladung (z. B. Admins) erhalten eine Teilnahme.
        $room->participants()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'role' => $room->canModerate($user) ? 'moderator' : 'speaker',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($user),
            ],
        );

        $this->room = $room;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            "echo-private:call.{$this->room->uuid},.call.answered" => '$refresh',
            "echo-private:call.{$this->room->uuid},.call.moderated" => '$refresh',
            "echo-private:call.{$this->room->uuid},.call.ended" => 'onCallEnded',
        ];
    }

    public function onCallEnded(): void
    {
        $this->redirectAfterCall();
    }

    /** Selbst auflegen; als letzter Moderator/Host beendet das den Anruf fuer alle. */
    public function leaveCall(RoomLifecycleService $lifecycle): void
    {
        $room = $this->room->fresh(['participants']);
        $me = $room->participantFor(auth()->user());

        if ($me && $me->connection === 'joined') {
            $lifecycle->markParticipantLeft($room, $me->livekit_identity);
        }

        $this->redirectAfterCall();
    }

    /** Anruf fuer alle beenden (nur Moderation). */
    public function endCall(RoomLifecycleService $lifecycle): void
    {
        abort_unless($this->room->canModerate(auth()->user()), 403, __('app.calls_permission_denied'));

        $lifecycle->endCall($this->room, 'ended');

        $this->redirectAfterCall();
    }

    /** Teilnehmer serverseitig stummschalten. */
    public function muteParticipant(int $participantId, LiveKitService $livekit): void
    {
        $target = $this->moderatableParticipant($participantId);

        $livekit->muteParticipantAudio($this->room, $target->livekit_identity);

        broadcast(new CallModerationActioned($this->room, (int) $target->user_id, 'mute'))->toOthers();
    }

    /** Teilnehmer aus dem Anruf entfernen. */
    public function removeParticipant(int $participantId, LiveKitService $livekit): void
    {
        $target = $this->moderatableParticipant($participantId);

        $livekit->removeParticipant($this->room, $target->livekit_identity);

        $target->forceFill(['connection' => 'disconnected', 'left_at' => now()])->save();

        broadcast(new CallModerationActioned($this->room, (int) $target->user_id, 'remove'))->toOthers();
    }

    /** Rolle live umschalten: Sprecher <-> nur zuhoeren. */
    public function toggleRole(int $participantId, LiveKitService $livekit): void
    {
        $target = $this->moderatableParticipant($participantId);

        $newRole = $target->role === 'viewer' ? 'speaker' : 'viewer';
        $target->forceFill(['role' => $newRole])->save();

        $livekit->setParticipantPublishing($this->room, $target->livekit_identity, $newRole !== 'viewer');

        broadcast(new CallModerationActioned($this->room, (int) $target->user_id, 'role', $newRole))->toOthers();
    }

    protected function moderatableParticipant(int $participantId): RoomParticipant
    {
        abort_unless($this->room->canModerate(auth()->user()), 403, __('app.calls_permission_denied'));

        $target = $this->room->participants()->findOrFail($participantId);

        // Host und die eigene Person sind vor Moderation geschuetzt.
        abort_if($target->role === 'host', 403);
        abort_if((int) $target->user_id === (int) auth()->id(), 422);

        return $target;
    }

    protected function redirectAfterCall(): void
    {
        $this->redirect(
            $this->room->chat_id
                ? route('chat', ['chat' => $this->room->chat_id])
                : route('dashboard'),
        );
    }

    public function render()
    {
        $this->room->load(['participants.user', 'chat']);

        return view('livewire.calls.call-window', [
            'canModerate' => $this->room->canModerate(auth()->user()),
            'me' => $this->room->participantFor(auth()->user()),
        ])->layout('layouts.master', ['contentMode' => 'viewport']);
    }
}
