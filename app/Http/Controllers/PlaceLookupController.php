<?php

namespace App\Http\Controllers;

use App\Services\Routing\TomTomAddressSearchService;
use App\Services\Routing\WorksiteLookup;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaceLookupController extends Controller
{
    public function search(Request $request, TomTomAddressSearchService $service): JsonResponse
    {
        $data = $request->validate(['query' => 'required|string|min:3|max:200', 'country' => 'nullable|string|max:2', 'places' => 'sometimes|boolean']);
        return response()->json($service->suggest($data['query'], strtoupper($data['country'] ?? ''), $data['places'] ?? false))->header('Cache-Control', 'private, no-store');
    }

    public function reverse(Request $request, TomTomAddressSearchService $service): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180']);
        return response()->json($service->reverse((float) $data['latitude'], (float) $data['longitude']))->header('Cache-Control', 'private, no-store');
    }

    public function choose(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|size:64', 'proof' => 'required|string|size:64',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'input' => 'required|array:query,country,city,postal,street',
            'input.query' => 'required|string|max:200', 'input.country' => 'nullable|string|max:2',
            'input.street' => 'nullable|string|max:200', 'input.city' => 'nullable|string|max:200', 'input.postal' => 'nullable|string|max:30',
            'signature' => 'required|string|size:64']);
        $input = array_map(fn ($value) => (string) $value, $data['input']);
        abort_unless(hash_equals(WorksiteLookup::signature($input), $data['signature'])
            && hash_equals(hash_hmac('sha256', $data['key'].WorksiteLookup::key($input), config('app.key')), $data['proof']), 403);
        $result = WorksiteLookup::cached($input);
        $point = collect($result['alternatives'] ?? [])->first(fn ($point) =>
            (float) $point['latitude'] === (float) $data['latitude'] && (float) $point['longitude'] === (float) $data['longitude']);
        abort_unless($point, 409);
        $request->session()->put('worksite.'.$data['key'], [$point['latitude'], $point['longitude']]);
        return response()->json(['selected' => true]);
    }

    public function resolve(Request $request, WorksiteLookup $service): JsonResponse
    {
        $data = $request->validate(['query' => 'required|string|min:3|max:200', 'country' => 'nullable|string|max:16',
            'street' => 'nullable|string|max:200', 'city' => 'nullable|string|max:200', 'postal' => 'nullable|string|max:30', 'signature' => 'required|string|size:64']);
        $input = ['query' => $data['query'], 'country' => $data['country'] ?? '', 'city' => $data['city'] ?? '', 'postal' => $data['postal'] ?? '', 'street' => $data['street'] ?? ''];
        abort_unless(hash_equals(WorksiteLookup::signature($input), $data['signature']), 403);
        try {
            $service->resolve($input);
        } catch (LockTimeoutException) {
            return response()->json(['retry' => true], 409);
        }
        return response()->json(['resolved' => true])->header('Cache-Control', 'private, no-store');
    }
}
