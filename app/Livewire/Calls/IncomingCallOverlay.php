<?php

namespace App\Livewire\Calls;

use App\Models\RoomInvitation;
use App\Services\Calls\CallInvitationService;
use Livewire\Component;

/**
 * Global im Master-Layout gemountetes Klingel-Overlay.
 *
 * Das Klingeln selbst (Anzeige, Ton, Countdown, Cross-Tab-Dedup) steuert
 * Alpine im View; hier leben nur die verbindlichen Annehmen-/Ablehnen-
 * Aktionen gegen die Datenbank.
 */
class IncomingCallOverlay extends Component
{
    /**
     * "Bei mir laeutet es gerade" — gemeldet, sobald das Overlay sichtbar wird.
     *
     * Damit erfaehrt der Anrufer, dass die Gegenseite eine offene Sitzung hat
     * und tatsaechlich klingelt; sein Fenster wechselt von "wird angerufen" auf
     * "klingelt". Fremde oder verschwundene Einladungen werden still ignoriert:
     * eine Statusmeldung darf nie einen Fehler im Overlay ausloesen.
     */
    public function reportRinging(int $invitationId, CallInvitationService $invitations): void
    {
        $invitation = RoomInvitation::with('room')->find($invitationId);

        if (! $invitation || (int) $invitation->invitee_id !== (int) auth()->id()) {
            return;
        }

        $invitations->markRinging($invitation, 'browser');
    }

    public function accept(int $invitationId, CallInvitationService $invitations)
    {
        $invitation = $this->ownInvitation($invitationId);

        // Ohne dieses Signal bliebe die Karte stehen: Alpine hat beim Klick
        // bereits stopRinging() ausgefuehrt und blockiert mit visible=true
        // jeden spaeteren eingehenden Anruf.
        if (! $invitation->room->isActive() || ! $invitations->accept($invitation)) {
            $this->dispatch('rt:call-gone', invitationId: $invitationId);

            return null;
        }

        return redirect()->route('calls.window', $invitation->room);
    }

    public function decline(int $invitationId, CallInvitationService $invitations): void
    {
        $invitation = $this->ownInvitation($invitationId);

        $invitations->decline($invitation);

        $this->dispatch('rt:call-gone', invitationId: $invitationId);
    }

    protected function ownInvitation(int $invitationId): RoomInvitation
    {
        $invitation = RoomInvitation::with('room')->findOrFail($invitationId);

        abort_unless((int) $invitation->invitee_id === (int) auth()->id(), 403);

        return $invitation;
    }

    public function render()
    {
        return view('livewire.calls.incoming-call-overlay');
    }
}
