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
    private const SESSION_SHARE_IDS = 'chat.live_location_share_ids';

    private const MAX_SESSION_SHARES = 100;

    public function __construct(protected ChatLiveLocationService $locations) {}

    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $locations = $this->locations->activeFor($user, $this->sessionShareIds($request));
        $this->writeSessionShareIds($request, $locations->pluck('uuid')->all());

        $payload = $locations
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
        $this->rememberSessionShare($request, (string) $location->uuid);

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
        abort_unless(
            in_array((string) $liveLocation->uuid, $this->sessionShareIds($request), true),
            403,
        );

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
        $this->forgetSessionShare($request, (string) $liveLocation->uuid);

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

    /** @return list<string> */
    private function sessionShareIds(Request $request): array
    {
        return collect((array) $request->session()->get(self::SESSION_SHARE_IDS, []))
            ->filter(fn (mixed $id): bool => is_string($id)
                && $id !== ''
                && strlen($id) <= 64)
            ->unique()
            ->take(-self::MAX_SESSION_SHARES)
            ->values()
            ->all();
    }

    private function rememberSessionShare(Request $request, string $shareId): void
    {
        $ids = [...$this->sessionShareIds($request), $shareId];

        $this->writeSessionShareIds($request, $ids);
    }

    private function forgetSessionShare(Request $request, string $shareId): void
    {
        $ids = array_values(array_filter(
            $this->sessionShareIds($request),
            fn (string $id): bool => $id !== $shareId,
        ));

        $this->writeSessionShareIds($request, $ids);
    }

    /** @param  array<int, mixed>  $shareIds */
    private function writeSessionShareIds(Request $request, array $shareIds): void
    {
        $ids = collect($shareIds)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->take(-self::MAX_SESSION_SHARES)
            ->values()
            ->all();

        if ($ids === []) {
            $request->session()->forget(self::SESSION_SHARE_IDS);

            return;
        }

        $request->session()->put(self::SESSION_SHARE_IDS, $ids);
    }
}
