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
    public function accept(int $invitationId, CallInvitationService $invitations)
    {
        $invitation = $this->ownInvitation($invitationId);

        if (! $invitation->isPending() || ! $invitation->room->isActive()) {
            return null;
        }

        $invitations->accept($invitation);

        return redirect()->route('calls.window', $invitation->room);
    }

    public function decline(int $invitationId, CallInvitationService $invitations): void
    {
        $invitation = $this->ownInvitation($invitationId);

        if ($invitation->isPending()) {
            $invitations->decline($invitation);
        }
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
