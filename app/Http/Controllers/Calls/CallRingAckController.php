<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\RoomInvitation;
use App\Services\Calls\CallInvitationService;
use Illuminate\Http\JsonResponse;

/**
 * Klingel-Rueckmeldung der installierten App.
 *
 * Der Service Worker ruft diesen Endpunkt auf, sobald er eine Anruf-
 * Benachrichtigung tatsaechlich anzeigt. Damit erfaehrt der Anrufer auch dann
 * "klingelt", wenn die Gegenseite gar keinen Tab offen hat.
 *
 * Die Autorisierung leistet die SIGNATUR der URL, nicht die Sitzung: der
 * Service Worker laeuft ohne garantierten Cookie-Kontext (bei iOS/Safari haeufig
 * ganz ohne), und die signierte, kurzlebige URL steckt ausschliesslich in der
 * verschluesselten Push-Nutzlast genau dieses einen Empfaengers.
 */
class CallRingAckController extends Controller
{
    public function __invoke(RoomInvitation $invitation, CallInvitationService $invitations): JsonResponse
    {
        $reported = $invitations->markRinging($invitation, 'app');

        return response()->json(['ringing' => $reported || $invitation->isRinging()]);
    }
}
