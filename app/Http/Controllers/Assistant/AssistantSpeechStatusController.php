<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantSpeechRouter;
use App\Support\Ai\AssistantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssistantSpeechStatusController extends Controller
{
    public function __invoke(Request $request, AssistantSpeechRouter $speech): JsonResponse
    {
        app(AssistantAccess::class)->authorize($request->user());

        return response()->json($speech->status())->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
