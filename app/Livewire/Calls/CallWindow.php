<?php

namespace App\Livewire\Calls;

use App\Events\CallModerationActioned;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Calls\CallInvitationService;
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
            $room->mayJoin($user) || $room->canModerate($user),
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

        // Jeder Einstiegsweg muss dieselbe Zustandsaenderung ausloesen. Der
        // Deep-Link der Web-Push-Benachrichtigung fuehrt direkt hierher und
        // umgeht das Overlay – ohne diesen Schritt bliebe die Einladung
        // 'pending' und der Ring-Timeout-Job wuerde den laufenden Anruf
        // nachtraeglich als verpasst markieren.
        $pending = $room->invitations()
            ->where('invitee_id', $user->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pending) {
            app(CallInvitationService::class)->accept($pending);
        }

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
        $me = $room?->participantFor(auth()->user());

        // Bewusst ohne Pruefung auf connection === 'joined': dieser Zustand
        // entsteht erst durch den participant_joined-Webhook. Wer waehrend des
        // Klingelns auflegt, waere sonst wirkungslos aufgelegt.
        if ($room && $me) {
            $lifecycle->leave($room, $me);
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

        // Durchsetzung passiert an der SFU. Kam der Aufruf dort nicht an, darf
        // die Oberflaeche keinen Erfolg melden – sonst haelt der Moderator
        // jemanden fuer stumm, der weiter senden kann.
        if (! $livekit->muteParticipantAudio($this->room, $target->livekit_identity)) {
            $this->moderationFailed();

            return;
        }

        broadcast(new CallModerationActioned($this->room, (int) $target->user_id, 'mute'))->toOthers();
    }

    /** Teilnehmer aus dem Anruf entfernen. */
    public function removeParticipant(int $participantId, LiveKitService $livekit): void
    {
        $target = $this->moderatableParticipant($participantId);

        if (! $livekit->removeParticipant($this->room, $target->livekit_identity)) {
            $this->moderationFailed();

            return;
        }

        // 'disconnected' sperrt den Wiedereintritt (Room::mayJoin); die Rolle
        // faellt zusaetzlich auf 'viewer', damit ein noch gueltiges, bereits
        // ausgestelltes Token an der SFU nicht mehr senden darf.
        $target->forceFill([
            'connection' => 'disconnected',
            'left_at' => now(),
            'role' => 'viewer',
        ])->save();

        broadcast(new CallModerationActioned($this->room, (int) $target->user_id, 'remove'))->toOthers();
    }

    /** Rolle live umschalten: Sprecher <-> nur zuhoeren. */
    public function toggleRole(int $participantId, LiveKitService $livekit): void
    {
        $target = $this->moderatableParticipant($participantId);

        $newRole = $target->role === 'viewer' ? 'speaker' : 'viewer';

        // Erst die SFU, dann die DB: sonst zeigt die Liste eine Rolle an, die
        // an der Medienebene nie durchgesetzt wurde.
        if (! $livekit->setParticipantPublishing($this->room, $target->livekit_identity, $newRole !== 'viewer')) {
            $this->moderationFailed();

            return;
        }

        $target->forceFill(['role' => $newRole])->save();

        broadcast(new CallModerationActioned($this->room, (int) $target->user_id, 'role', $newRole))->toOthers();
    }

    protected function moderationFailed(): void
    {
        $this->dispatch(
            'swal:toast',
            type: 'error',
            text: __('app.calls_moderation_failed'),
        );
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
