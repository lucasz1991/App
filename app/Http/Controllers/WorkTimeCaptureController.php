<?php

namespace App\Http\Controllers;

use App\Services\Operations\WorkTimeCaptureService;
use Illuminate\Http\Request;

class WorkTimeCaptureController extends Controller
{
    public function bootstrap(Request $request, WorkTimeCaptureService $service)
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 400, 'HTTPS erforderlich.');
        $data = $request->validate(['device_id' => 'nullable|uuid', 'event_keys' => 'sometimes|array|max:500', 'event_keys.*' => 'uuid|distinct']);

        return response()->json($service->bootstrap($request->user(), $data['device_id'] ?? null, $data['event_keys'] ?? []))->header('Cache-Control', 'no-store, private');
    }

    public function sync(Request $request, WorkTimeCaptureService $service)
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 400, 'HTTPS erforderlich.');
        abort_if(strlen($request->getContent()) > 65536, 413);
        $data = $request->validate(['device_id' => 'required|uuid', 'events' => 'required|array|min:1|max:50']);

        return response()->json($service->ingest($request->user(), $data['device_id'], $data['events']))->header('Cache-Control', 'no-store, private');
    }
}
