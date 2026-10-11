<?php

namespace App\Http\Controllers\EmployeeLocation;

use App\Http\Controllers\Controller;
use App\Services\EmployeeLocation\EmployeeLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeLocationApiController extends Controller
{
    public function register(Request $request, EmployeeLocationService $service): JsonResponse
    {
        return response()->json($service->register($request->all()), 201)->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, EmployeeLocationService $service): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|min:32|max:128']);

        return response()->json($service->start($request->attributes->get('employee_location_installation'), $data['code']));
    }

    public function permit(Request $request, EmployeeLocationService $service): JsonResponse
    {
        return response()->json($service->permit($request->attributes->get('employee_location_installation')));
    }

    public function point(Request $request, EmployeeLocationService $service): JsonResponse
    {
        return response()->json($service->point($request->attributes->get('employee_location_installation'), $request->all()));
    }

    public function stop(Request $request, EmployeeLocationService $service): Response
    {
        $service->stop($request->attributes->get('employee_location_installation'));

        return response()->noContent();
    }
}
