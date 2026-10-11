<?php

namespace App\Http\Controllers;

use App\Services\Personnel\UserAppRuntimeReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAppRuntimeStatusController extends Controller
{
    public function store(Request $request, UserAppRuntimeReceiptService $service): JsonResponse
    {
        abort_unless($request->isJson(), 415, 'Eine App-Statusmeldung benötigt JSON.');
        abort_if(strlen($request->getContent()) > 2048, 413, 'App-Statusmeldung ist zu groß.');
        $receipt = $service->record($request->user(), $request->all());

        return response()->json(['recorded' => true, 'received_at' => $receipt->received_at->toISOString()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
