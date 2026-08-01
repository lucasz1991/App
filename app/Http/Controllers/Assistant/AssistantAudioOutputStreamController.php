<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Ai\SpeechServiceClient;
use App\Services\Ai\SpeechServiceException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssistantAudioOutputStreamController extends Controller
{
    public function __invoke(Request $request, SpeechServiceClient $speech): Response
    {
        $validated = $request->validate([
            'text' => [
                'required',
                'string',
                'max:'.(int) config('assistant.speech.max_text_characters', 4000),
                static fn (string $attribute, mixed $value, \Closure $fail) => trim((string) $value) === ''
                    ? $fail('Der Text darf nicht leer sein.')
                    : null,
            ],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
        ]);

        try {
            $result = $speech->synthesize(
                trim($validated['text']),
                (float) ($validated['speed'] ?? 1.0),
            );

            return response($result['audio'], 200, [
                'Content-Type' => 'audio/wav',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $result['request_id'] ?? '',
            ]);
        } catch (SpeechServiceException $exception) {
            $requestId = $exception->requestId ?: (string) Str::uuid();

            Log::warning('RailTime assistant speech synthesis failed.', [
                'request_id' => $requestId,
                'reason_code' => $exception->reasonCode,
                'upstream_status' => $exception->upstreamStatus,
            ]);

            return response([
                'message' => 'Die Audioausgabe ist gerade nicht verfügbar.',
                'request_id' => $requestId,
            ], $exception->upstreamStatus === 429 ? 429 : 503, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }
}
