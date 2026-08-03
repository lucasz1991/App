<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\StartChatLiveLocationRequest;
use App\Http\Requests\Chat\UpdateChatLiveLocationRequest;
use App\Http\Resources\ChatLiveLocationResource;
use App\Models\Chat;
use App\Models\ChatLiveLocation;
use App\Models\User;
use App\Services\Chat\ChatLiveLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatLiveLocationController extends Controller
{
    public function __construct(protected ChatLiveLocationService $locations) {}

    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $payload = $this->locations
            ->activeFor($user)
            ->map(fn (ChatLiveLocation $location): array => $this->resource($request, $location))
            ->values()
            ->all();

        return $this->noStore(response()->json(['live_locations' => $payload]));
    }

    public function store(StartChatLiveLocationRequest $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $location = $this->locations->start($chat, $user, $request->validated());

        return $this->noStore(response()->json([
            'live_location' => $this->resource($request, $location),
        ], 201));
    }

    public function update(
        UpdateChatLiveLocationRequest $request,
        ChatLiveLocation $liveLocation,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $location = $this->locations->update($liveLocation, $user, $request->validated());

        return $this->noStore(response()->json([
            'live_location' => $this->resource($request, $location),
        ]));
    }

    public function destroy(Request $request, ChatLiveLocation $liveLocation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $location = $this->locations->stop($liveLocation, $user);

        return $this->noStore(response()->json([
            'live_location' => $this->resource($request, $location),
        ]));
    }

    /** @return array<string, mixed> */
    private function resource(Request $request, ChatLiveLocation $location): array
    {
        $location->loadMissing('message');

        return (new ChatLiveLocationResource($location))->toArray($request);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
