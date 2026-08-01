<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Services\Calls\CallRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallRecordingAcknowledgementController extends Controller
{
    public function __invoke(Request $request, CallRecordingService $recordings): JsonResponse
    {
        abort_unless($recordings->enabled(), 404);

        $validated = $request->validate([
            'accepted' => ['required', 'accepted'],
            'policyVersion' => ['required', 'string', Rule::in([$recordings->policyVersion()])],
        ]);

        $acknowledgement = $recordings->acknowledge(
            $request->user(),
            $validated['policyVersion'],
        );

        return response()->json([
            'acknowledged' => true,
            'policyVersion' => $acknowledgement->policy_version,
            'acceptedAt' => $acknowledgement->accepted_at->toIso8601String(),
        ]);
    }
}
