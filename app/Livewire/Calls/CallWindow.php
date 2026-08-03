<?php

namespace App\Livewire\Calls;

use App\Events\CallModerationActioned;
use App\Models\ChatLiveLocation;
use App\Models\Room;
use App\Models\RoomInvitation;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Calls\CallConversationService;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\RoomEventRecorder;
use App\Services\Calls\RoomLifecycleService;
use App\Services\Chat\ChatLiveLocationService;
use Livewire\Attributes\Renderless;
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

    public array $inviteeIds = [];

    public function mount(Room $room, CallConversationService $conversations): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);

        abort_unless($room->isActive(), 410, __('app.calls_ended'));

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
            abort_unless(
                app(CallInvitationService::class)->accept($pending),
                403,
                __('app.calls_permission_denied'),
            );
        }

        abort_unless($room->mayJoin($user), 403, __('app.calls_permission_denied'));

        $room->loadMissing('owner');
        if (! $room->call_chat_id && $room->owner) {
            $conversations->createForRoom($room, $room->owner);
        }
        $conversations->attachParticipant($room, $user);

        $this->room = $room;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            "echo-private:call.{$this->room->uuid},.call.answered" => 'onWaitingChanged',
            "echo-private:call.{$this->room->uuid},.call.ringing" => 'onWaitingChanged',
            "echo-private:call.{$this->room->uuid},.call.missed" => 'onWaitingChanged',
            "echo-private:call.{$this->room->uuid},.call.moderated" => '$refresh',
            "echo-private:call.{$this->room->uuid},.call.ended" => 'onCallEnded',
        ];
    }

    /**
     * Wer noch klingelt, ist Server-Wahrheit; die Anzeige (Animation, Text,
     * Freizeichen) gehoert Alpine. Alpine wertet x-data nach einem Livewire-
     * Re-Render aber NICHT neu aus – die aktuelle Liste muss deshalb als
     * Browser-Ereignis kommen, nicht nur als neu gerendertes Markup.
     */
    #[Renderless]
    public function onWaitingChanged(): void
    {
        $this->dispatch('rt-call-waiting', waiting: $this->waitingFor());
    }

    public function onCallEnded(): void
    {
        $this->redirectAfterCall();
    }

    /**
     * Eingeladene, die weder angenommen noch abgelehnt haben.
     *
     * 'ringing' unterscheidet die zwei Zustaende, die den Anrufer wirklich
     * interessieren: Einladung nur verschickt ("wird angerufen") oder von der
     * Gegenseite als laeutend bestaetigt ("klingelt").
     *
     * @return array<int, array<string, mixed>>
     */
    public function waitingFor(): array
    {
        return $this->room->invitations()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with('invitee')
            ->get()
            ->map(fn (RoomInvitation $invitation): array => [
                'id' => (int) $invitation->id,
                'name' => (string) ($invitation->invitee?->name ?? ''),
                'avatar' => $invitation->invitee?->profile_photo_url,
                'ringing' => $invitation->isRinging(),
            ])
            ->values()
            ->all();
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
    public function removeParticipant(
        int $participantId,
        LiveKitService $livekit,
        RoomEventRecorder $events,
        ChatLiveLocationService $liveLocations,
    ): void {
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

        if ($this->room->call_chat_id && $target->user_id) {
            $liveLocations->stopForUserInChat(
                (int) $this->room->call_chat_id,
                (int) $target->user_id,
                ChatLiveLocation::STOP_CALL_REMOVED,
            );
        }

        $events->record($this->room, 'removed', $target->user_id, [
            'removed_by' => (int) auth()->id(),
        ]);

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

    public function inviteUsers(CallInvitationService $invitations): void
    {
        abort_unless($this->room->canModerate(auth()->user()), 403, __('app.calls_permission_denied'));

        $ids = collect($this->inviteeIds)->map(fn ($id) => (int) $id)->filter()->unique();
        $existing = $this->room->participants()->pluck('user_id');
        $users = User::query()
            ->whereKey($ids)
            ->where('status', true)
            ->get()
            ->reject(fn (User $user) => $existing->contains($user->id));

        $sent = $invitations->invite($this->room, auth()->user(), $users);
        $this->inviteeIds = [];
        $this->room->refresh();

        // Nachtraeglich Eingeladene gehoeren sofort in die Klingel-Anzeige.
        $this->onWaitingChanged();

        $this->dispatch(
            'swal:toast',
            type: $sent->isEmpty() ? 'warning' : 'success',
            text: trans_choice('app.calls_invited_count', $sent->count(), ['count' => $sent->count()]),
        );
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
            $this->room->call_chat_id
                ? route('calls.history', $this->room)
                : ($this->room->chat_id
                    ? route('chat', ['chat' => $this->room->chat_id])
                    : route('calls.index')),
        );
    }

    public function render()
    {
        $this->room->load(['participants.user', 'chat', 'callChat']);

        return view('livewire.calls.call-window', [
            'canModerate' => $this->room->canModerate(auth()->user()),
            'me' => $this->room->participantFor(auth()->user()),
            'inviteCandidates' => $this->room->canModerate(auth()->user())
                ? User::query()
                    ->where('status', true)
                    ->whereNotIn('id', $this->room->participants->pluck('user_id')->filter())
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'profile_photo_path'])
                    ->filter(fn (User $user) => $user->isAdmin() || $user->hasRbacPermission('calls.join'))
                : collect(),
        ])->layout('layouts.master', ['contentMode' => 'viewport', 'chrome' => false]);
    }
}
