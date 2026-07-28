<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\Room;
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
    public function store(Request $request, Room $room, LiveKitService $livekit): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            Gate::authorize('calls.join');
        }

        abort_unless($room->isActive(), 410, __('app.calls_ended'));

        $participant = $room->participantFor($user);

        abort_unless($participant !== null, 403, __('app.calls_permission_denied'));

        if (! $livekit->isConfigured()) {
            return response()->json([
                'message' => 'LiveKit ist serverseitig noch nicht konfiguriert (LIVEKIT_API_KEY/SECRET).',
            ], 503);
        }

        return response()->json([
            'token' => $livekit->issueToken($room, $user, $participant),
            'wsUrl' => (string) config('livekit.ws_url'),
            'iceServers' => $livekit->iceServers(),
            'identity' => LiveKitService::identityFor($user),
            'role' => $participant->role,
        ]);
    }
}
