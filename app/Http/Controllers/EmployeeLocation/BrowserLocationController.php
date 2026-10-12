<?php

namespace App\Http\Controllers\EmployeeLocation;

use App\Http\Controllers\Controller;
use App\Services\EmployeeLocation\BrowserLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BrowserLocationController extends Controller
{
    public function __construct(private BrowserLocationService $service) {}

    public function status(Request $request): JsonResponse
    {
        return $this->json($this->service->status($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->json($this->service->capture($request->user(), $request->all()));
    }

    public function destroy(Request $request): Response
    {
        $this->service->forgetCurrent($request->user());

        return response()->noContent()->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
