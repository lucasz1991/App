<?php

namespace App\Livewire\Concerns\Chat;

use App\Models\Chat;
use App\Models\User;
use App\Services\Calls\CallAvailabilityService;
use App\Services\Calls\CallInfrastructureUnavailable;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\RoomLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anruf aus dem offenen Chat: Raum unter Sperre anlegen oder dem laufenden
 * Anruf beitreten und die uebrigen Teilnehmer anklingeln.
 */
trait StartsChatCalls
{
    /**
     * Anruf im ausgewaehlten Chat starten und alle Teilnehmer anklingeln.
     *
     * @param  bool  $video  false = reiner Sprachanruf. Der Unterschied liegt
     *                       allein darin, ob die Kamera beim Beitritt aktiviert wird; Raum,
     *                       Klingeln und Moderation sind identisch. Jeder kann die Kamera im
     *                       Gespraech jederzeit zuschalten.
     *
     * Die Dienste werden bewusst ueber app() geholt statt per Methoden-
     * Injection: sonst muesste der bool-Parameter hinter den Klassen stehen
     * und liesse sich aus dem View nicht mehr sauber uebergeben.
     */
    public function startCall(bool $video = true)
    {
        $lifecycle = app(RoomLifecycleService::class);
        $invitations = app(CallInvitationService::class);

        abort_unless(auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.start'), 403);

        if (! Schema::hasTable('rooms')) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        $chat = $this->myChat((int) $this->selectedChatId);

        $activeTarget = $chat->activeRoom()->first();
        if (app(CallAvailabilityService::class)->activeRoomFor(auth()->user(), $activeTarget)) {
            $this->dispatch('swal:toast', type: 'warning', text: 'Bitte den laufenden Anruf zuerst beenden.');

            return null;
        }

        // Pruefen und Anlegen unter einer Sperre auf der Chat-Zeile: ohne sie
        // erzeugen zwei gleichzeitige Klicks zwei konkurrierende Raeume, und
        // die beiden Haelften des Chats klingeln in getrennten Anrufen.
        try {
            $result = DB::transaction(function () use ($chat, $lifecycle, $video) {
                User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
                Chat::whereKey($chat->id)->lockForUpdate()->first();

                $existing = $chat->activeRoom()->first();

                if ($existing) {
                    return ['room' => $existing, 'created' => false];
                }

                return ['room' => $lifecycle->createForChat($chat, auth()->user(), $video), 'created' => true];
            });
        } catch (CallInfrastructureUnavailable) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        // Laeuft bereits ein Anruf, direkt beitreten statt doppelt zu starten.
        if (! $result['created']) {
            $this->dispatch('communication:call', roomUuid: $result['room']->uuid);

            return null;
        }

        $invitations->invite(
            $result['room'],
            auth()->user(),
            $chat->participants()->where('users.id', '!=', auth()->id())->where('users.status', true)->get(),
        );

        // Der Anruf oeffnet sich im persistenten Kommunikationsmodul, nicht
        // ueber eine eigene Seite.
        $this->dispatch('communication:call', roomUuid: $result['room']->uuid);

        return null;
    }
}
