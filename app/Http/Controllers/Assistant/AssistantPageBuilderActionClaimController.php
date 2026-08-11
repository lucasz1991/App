<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantPendingActionStore;
use App\Support\Ai\AssistantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class AssistantPageBuilderActionClaimController extends Controller
{
    public function __invoke(
        Request $request,
        AssistantPendingActionStore $pendingActions,
    ): JsonResponse {
        $user = app(AssistantAccess::class)->authorize($request->user());
        $validated = $request->validate([
            'action_token' => ['required', 'string', 'regex:/\A[a-zA-Z0-9]{48}\z/'],
        ]);
        $token = (string) $validated['action_token'];

        $lock = Cache::lock(
            'assistant:pagebuilder-claim:'.(int) $user->getAuthIdentifier().':'.hash('sha256', $token),
            5,
        );
        $effect = $lock->get(fn () => $pendingActions->claimPageBuilderEffect($user, $token));

        abort_unless(is_array($effect), 422);

        return response()->json(['action' => $effect])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
