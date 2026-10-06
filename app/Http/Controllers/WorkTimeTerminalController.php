<?php

namespace App\Http\Controllers;

use App\Services\Operations\WorkTimeTerminalService;
use Illuminate\Http\Request;

class WorkTimeTerminalController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless(config('operations.terminal_enabled', false), 404);
        abort_if(app()->environment('production') && ! $request->isSecure(), 400, 'HTTPS erforderlich.');
        abort_if(strlen($request->getContent()) > 65536, 413);
    }

    public function authenticate(Request $request, WorkTimeTerminalService $service)
    {
        $this->guard($request);
        $data = $request->validate(['user_id' => 'required|integer|min:1', 'pin' => 'required|string|regex:/^[0-9]{6,10}$/', 'terminal_id' => 'required|uuid']);

        return response()->json($service->authenticate($data['user_id'], $data['pin'], $data['terminal_id'], $request->ip()))->header('Cache-Control', 'private, no-store');
    }

    public function capture(Request $request, WorkTimeTerminalService $service)
    {
        $this->guard($request);
        $data = $request->validate(['terminal_id' => 'required|uuid', 'event' => 'required|array', 'location' => 'sometimes|array']);

        return response()->json($service->capture($request->bearerToken() ?? '', $data['terminal_id'], $data['event'], $data['location'] ?? []))->header('Cache-Control', 'private, no-store');
    }
}
