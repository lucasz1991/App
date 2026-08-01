<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantSpeechException;
use App\Services\Ai\AssistantSpeechRouter;
use App\Support\Ai\AssistantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssistantAudioInputTranscriptionController extends Controller
{
    public function __invoke(Request $request, AssistantSpeechRouter $speech): JsonResponse
    {
        app(AssistantAccess::class)->authorize($request->user());

        $validated = $request->validate([
            'audio' => [
                'required',
                'file',
                'max:'.(int) config('assistant.speech.max_audio_kilobytes', 20480),
                'mimetypes:audio/webm,video/webm,audio/ogg,audio/wav,audio/x-wav,audio/wave,audio/mpeg,audio/mp4,video/mp4',
            ],
        ]);

        try {
            $result = $speech->transcribe($validated['audio']);

            return response()->json([
                'text' => $result['text'],
                'request_id' => $result['request_id'],
            ])->withHeaders([
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $result['request_id'] ?? '',
                'X-Speech-Provider' => $result['provider'],
                'X-Speech-Fallback' => $result['fallback'] ? 'true' : 'false',
            ]);
        } catch (AssistantSpeechException $exception) {
            $requestId = $exception->requestId ?: (string) Str::uuid();

            Log::warning('RailTime assistant transcription failed.', [
                'request_id' => $requestId,
                'reason_code' => $exception->reasonCode,
                'upstream_status' => $exception->upstreamStatus,
                'provider' => $exception->provider,
                'fallback_attempted' => $exception->fallbackAttempted,
            ]);

            return response()->json([
                'message' => 'Die Spracheingabe ist gerade nicht verfügbar.',
                'request_id' => $requestId,
            ], $exception->upstreamStatus === 429 ? 429 : 503)->withHeaders([
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }
}
