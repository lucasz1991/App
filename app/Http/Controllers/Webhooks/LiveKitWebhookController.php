<?php

namespace App\Http\Controllers\Webhooks;

use Agence104\LiveKit\WebhookReceiver;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\Calls\CallRecordingService;
use App\Services\Calls\RoomLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * LiveKit-Webhooks halten die Datenbank als fuehrende Wahrheit aktuell –
 * auch wenn Tabs abstuerzen oder Reverb ausfaellt. Alle Uebergaenge im
 * RoomLifecycleService sind idempotent, da LiveKit Webhooks wiederholt.
 */
class LiveKitWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        RoomLifecycleService $lifecycle,
        CallRecordingService $recordings,
    ): Response {
        try {
            $receiver = new WebhookReceiver(
                config('livekit.api_key'),
                config('livekit.api_secret'),
            );

            $event = $receiver->receive(
                $request->getContent(),
                $request->header('Authorization'),
            );
        } catch (\Throwable $exception) {
            Log::warning('LiveKit-Webhook mit ungueltiger Signatur abgewiesen.', [
                'error_class' => $exception::class,
            ]);

            return response('invalid signature', 403);
        }

        // Egress-Ereignisse besitzen haeufig kein room-Objekt. Sie muessen vor
        // der bisherigen Room-Extraktion verarbeitet werden, sonst gingen
        // ACTIVE, Abschluss und Aufnahmefehler still verloren.
        if (in_array($event->getEvent(), CallRecordingService::EGRESS_WEBHOOK_EVENTS, true)) {
            $roomToEnd = $recordings->handleWebhook($event);

            if ($roomToEnd?->isActive()) {
                $lifecycle->endCall($roomToEnd, 'recording_failed');
            }

            return response('ok');
        }

        $roomName = $event->getRoom()?->getName();

        if (! $roomName) {
            return response('ok');
        }

        $room = Room::where('uuid', $roomName)->first();

        if (! $room) {
            return response('ok');
        }

        $identity = $event->getParticipant()?->getIdentity() ?? '';

        switch ($event->getEvent()) {
            case 'room_started':
                $lifecycle->markActive($room);
                break;
            case 'room_finished':
                $lifecycle->markEnded($room, 'finished');
                break;
            case 'participant_joined':
                $lifecycle->markParticipantJoined($room, $identity);
                $recordings->unlockParticipantIfReady($room, $identity);
                break;
            case 'participant_left':
                $lifecycle->markParticipantLeft($room, $identity);
                break;
        }

        return response('ok');
    }
}
