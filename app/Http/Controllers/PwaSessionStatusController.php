<?php

namespace App\Http\Controllers;

use App\Support\Push\WebPushConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PwaSessionStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Read-only recovery after OS suspension: no permission decision,
        // session renewal, or replay of an interrupted write action.
        return response()->json([
            'authenticated' => true,
            'csrf_token' => $request->session()->token(),
            'account_binding' => WebPushConfiguration::accountBinding($request->user()->getAuthIdentifier()),
        ])->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
