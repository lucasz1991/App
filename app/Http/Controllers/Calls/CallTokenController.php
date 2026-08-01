<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\Calls\CallRecordingService;
use App\Services\Calls\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bewusst ein klassischer Controller: Das Frontend holt das Token per fetch()
 * unmittelbar vor room.connect() – so ist es immer frisch und landet nie im
 * Livewire-Snapshot.
 */
class CallTokenController extends Controller
{
    public function store(
        Request $request,
        Room $room,
        LiveKitService $livekit,
        CallRecordingService $recordings,
    ): JsonResponse {
        $user = $request->user();

        if (! $user->isAdmin()) {
            Gate::authorize('calls.join');
        }

        abort_unless($room->isActive(), 410, __('app.calls_ended'));

        $participant = $room->participantFor($user);

        // Nicht die blosse Teilnehmerzeile entscheidet (die entsteht schon beim
        // Klingeln), sondern der aktuelle Einladungs- und Teilnahmestatus.
        abort_unless(
            $participant !== null && $room->mayJoin($user),
            403,
            __('app.calls_permission_denied'),
        );

        if (! $livekit->isConfigured()) {
            return response()->json([
                'message' => 'LiveKit ist serverseitig noch nicht konfiguriert (LIVEKIT_API_KEY/SECRET).',
            ], 503);
        }

        $recording = null;
        $mediaPublishingAllowed = $participant->canPublish();

        if ($recordings->enabled()) {
            if (! $recordings->infrastructureReady()) {
                return response()->json([
                    'message' => 'Die verpflichtende Aufzeichnung ist noch nicht vollstaendig konfiguriert.',
                    'recording' => [
                        'enabled' => true,
                        'status' => 'unavailable',
                        'canPublish' => false,
                    ],
                ], 503);
            }

            $acknowledgement = $recordings->currentAcknowledgement($user);

            if (! $acknowledgement) {
                return response()->json([
                    'message' => 'Vor dem Beitritt muss der aktuelle Aufnahmehinweis bestaetigt werden.',
                    'recording' => [
                        'enabled' => true,
                        'requiresAcknowledgement' => true,
                        'policyVersion' => $recordings->policyVersion(),
                        'canPublish' => false,
                    ],
                ], 428);
            }

            $recordings->attachAcknowledgement($participant, $acknowledgement);
            $recording = $recordings->ensureRequested($room);

            if ($recording->status->isTerminal()) {
                return response()->json([
                    'message' => 'Der Anruf wurde beendet, weil keine lueckenlose Aufzeichnung bereitsteht.',
                    'recording' => [
                        'enabled' => true,
                        'status' => $recording->status->value,
                        'canPublish' => false,
                    ],
                ], 503);
            }

            // Bis EGRESS_ACTIVE darf der Browser zwar beitreten und empfangen,
            // aber keine Kamera-/Mikrofon-Tracks an LiveKit publizieren.
            $mediaPublishingAllowed = $recording->status->permitsPublishing()
                && $participant->canPublish();

            // Der Browser ist waehrend des Egress-Starts bereits mit einem
            // receive-only Token verbunden. Sobald ACTIVE erreicht ist,
            // schaltet diese Abfrage deshalb auch die bestehende LiveKit-
            // Teilnahme frei; nur ein neues Token zurueckzugeben wuerde die
            // laufende Verbindung nicht nachtraeglich berechtigen.
            if ($mediaPublishingAllowed) {
                $mediaPublishingAllowed = (bool) $participant->livekit_identity
                    && $recordings->unlockParticipantIfReady($room, $participant->livekit_identity);
            }
        }

        return response()->json([
            'token' => $livekit->issueToken($room, $user, $participant, $mediaPublishingAllowed),
            'wsUrl' => (string) config('livekit.ws_url'),
            'iceServers' => $livekit->iceServers(),
            'identity' => LiveKitService::identityFor($user),
            'role' => $participant->role,
            'recording' => $recording ? [
                'enabled' => true,
                'requiresAcknowledgement' => false,
                'policyVersion' => $recordings->policyVersion(),
                'status' => $recording->status->value,
                'startDeadlineAt' => $recording->start_deadline_at->toIso8601String(),
                'canPublish' => $mediaPublishingAllowed,
            ] : [
                'enabled' => false,
                'status' => 'disabled',
                'canPublish' => $mediaPublishingAllowed,
            ],
        ]);
    }
}
