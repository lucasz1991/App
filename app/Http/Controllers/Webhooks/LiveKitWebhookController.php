<?php

namespace App\Http\Controllers\Webhooks;

use Agence104\LiveKit\WebhookReceiver;
use App\Http\Controllers\Controller;
use App\Models\Room;
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
    public function __invoke(Request $request, RoomLifecycleService $lifecycle): Response
    {
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

        $roomName = $event->getRoom()?->getName();

        if (! $roomName) {
            return response('ok');
        }

        $room = Room::where('uuid', $roomName)->first();

        if (! $room) {
            return response('ok');
        }

        $identity = $event->getParticipant()?->getIdentity() ?? '';

        match ($event->getEvent()) {
            'room_started' => $lifecycle->markActive($room),
            'room_finished' => $lifecycle->markEnded($room, 'finished'),
            'participant_joined' => $lifecycle->markParticipantJoined($room, $identity),
            'participant_left' => $lifecycle->markParticipantLeft($room, $identity),
            default => null,
        };

        return response('ok');
    }
}
