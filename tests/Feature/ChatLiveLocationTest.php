<?php

namespace Tests\Feature;

use App\Events\ChatLiveLocationChanged;
use App\Events\ChatMessageReceived;
use App\Events\ChatMessageSent;
use App\Http\Middleware\LogActivity;
use App\Models\Chat;
use App\Models\ChatLiveLocation;
use App\Models\ChatMessage;
use App\Models\Room;
use App\Models\User;
use App\Services\Chat\ChatLiveLocationService;
use App\Services\Chat\ChatMessageInteractionService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatLiveLocationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LogActivity::class,
            ThrottleRequests::class,
        ]);
        $this->buildMinimalRailTimeSchema();
        Event::fake([
            ChatLiveLocationChanged::class,
            ChatMessageReceived::class,
            ChatMessageSent::class,
        ]);
        Notification::fake();
    }

    public function test_owner_can_start_and_update_an_encrypted_timed_live_location(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 10:00:00', 'Europe/Berlin'));
        [$sender, , $chat] = $this->directChat();

        $response = $this->actingAs($sender)->postJson(
            route('chat.live-locations.store', $chat),
            $this->position([
                'duration_minutes' => 90,
                'latitude' => 52.5200084,
                'longitude' => 13.4049541,
                'accuracy' => 7.36,
            ]),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('live_location.chat_id', $chat->id)
            ->assertJsonPath('live_location.duration_minutes', 90)
            ->assertJsonPath('live_location.status', 'active')
            ->assertJsonPath('live_location.version', 1)
            ->assertJsonPath('live_location.position.latitude', 52.520008)
            ->assertJsonPath('live_location.position.longitude', 13.404954)
            ->assertJsonPath('live_location.position.accuracy', 7.4);
        $this->assertPrivateNoStore($response->headers->get('Cache-Control'));

        $location = ChatLiveLocation::query()->with('message')->firstOrFail();
        $response->assertSessionHas(
            'chat.live_location_share_ids',
            fn (array $ids): bool => $ids === [$location->uuid],
        );
        $this->assertSame('live_location', $location->message->message_type);
        $this->assertSame($sender->id, (int) $location->user_id);
        $this->assertSame(5400, $location->remainingSeconds());
        $this->assertTrue($location->expires_at->equalTo(now()->addMinutes(90)));
        $this->assertStringEndsWith(
            '/chat/live-locations/'.$location->uuid,
            $response->json('live_location.routes.update'),
        );

        $rawPosition = (string) DB::table('chat_live_locations')->value('latest_position');
        $this->assertStringNotContainsString('latitude', $rawPosition);
        $this->assertStringNotContainsString('52.520008', $rawPosition);
        $this->assertStringNotContainsString('13.404954', $rawPosition);

        $this->actingAs($sender)
            ->patchJson(
                route('chat.live-locations.update', $location),
                $this->position([
                    'latitude' => 53.551086,
                    'longitude' => 9.993682,
                    'accuracy' => 4.21,
                ]),
            )
            ->assertOk()
            ->assertJsonPath('live_location.version', 2)
            ->assertJsonPath('live_location.position.latitude', 53.551086)
            ->assertJsonPath('live_location.position.longitude', 9.993682);

        $location->refresh();
        $this->assertSame(2, $location->version);
        $this->assertSame(53.551086, $location->position()['latitude']);

        Event::assertDispatched(ChatMessageSent::class);
        Event::assertDispatched(ChatMessageReceived::class);
        Event::assertDispatched(ChatLiveLocationChanged::class, function (
            ChatLiveLocationChanged $event,
        ) use ($location, $chat): bool {
            $payload = $event->broadcastWith();

            return $event->chatId === $chat->id
                && $event->liveLocationId === $location->uuid
                && array_keys($payload) === [
                    'chatId',
                    'messageId',
                    'liveLocationId',
                    'version',
                    'status',
                    'expiresAt',
                ]
                && ! array_key_exists('position', $payload)
                && ! array_key_exists('latitude', $payload)
                && ! array_key_exists('longitude', $payload);
        });
    }

    public function test_duration_membership_and_hidden_chat_rules_are_enforced(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();

        $this->actingAs($sender)
            ->postJson(
                route('chat.live-locations.store', $chat),
                $this->position(['duration_minutes' => 20]),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration_minutes');

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->postJson(
                route('chat.live-locations.store', $chat),
                $this->position(['duration_minutes' => 15]),
            )
            ->assertForbidden();

        $chat->participants()->updateExistingPivot($recipient->id, ['hidden_at' => now()]);
        $this->actingAs($recipient)
            ->postJson(
                route('chat.live-locations.store', $chat),
                $this->position(['duration_minutes' => 15]),
            )
            ->assertForbidden();

        $this->assertDatabaseCount('chat_live_locations', 0);
    }

    public function test_new_share_replaces_the_previous_share_and_active_endpoint_recovers_only_it(): void
    {
        [$sender, , $chat] = $this->directChat();

        $first = $this->startLocation($sender, $chat, 15);
        $second = $this->startLocation($sender, $chat, 45, [
            'latitude' => 48.137154,
            'longitude' => 11.576124,
        ]);

        $first->refresh();
        $this->assertSame('stopped', $first->status());
        $this->assertSame(ChatLiveLocation::STOP_REPLACED, $first->stop_reason);
        $this->assertSame(2, $first->version);
        $this->assertTrue($second->refresh()->isActive());

        $response = $this->actingAs($sender)
            ->getJson(route('chat.live-locations.active'))
            ->assertOk()
            ->assertJsonCount(1, 'live_locations')
            ->assertJsonPath('live_locations.0.id', $second->uuid)
            ->assertJsonPath('live_locations.0.chat_id', $chat->id);

        $this->assertPrivateNoStore($response->headers->get('Cache-Control'));
        $this->assertSame('active', $response->json('live_locations.0.status'));
        $this->assertDatabaseCount('chat_messages', 2);
    }

    public function test_active_resume_and_coordinate_updates_are_limited_to_the_originating_session(): void
    {
        [$sender, , $chat] = $this->directChat();
        $location = $this->startLocation($sender, $chat, 30);
        $originalPosition = $location->position();

        $this->flushSession();

        $this->actingAs($sender)
            ->getJson(route('chat.live-locations.active'))
            ->assertOk()
            ->assertJsonCount(0, 'live_locations');

        $this->actingAs($sender)
            ->patchJson(
                route('chat.live-locations.update', $location),
                $this->position([
                    'latitude' => 48.775846,
                    'longitude' => 9.182932,
                ]),
            )
            ->assertForbidden();

        $this->assertSame($originalPosition, $location->refresh()->position());

        // The account owner may still explicitly stop the share from another
        // signed-in device; only passive resume and coordinate writes are bound.
        $this->actingAs($sender)
            ->deleteJson(route('chat.live-locations.destroy', $location))
            ->assertOk()
            ->assertJsonPath('live_location.status', 'stopped');
    }

    public function test_only_owner_can_sync_or_stop_and_stop_is_idempotent(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $location = $this->startLocation($sender, $chat, 30);

        $this->actingAs($recipient)
            ->patchJson(
                route('chat.live-locations.update', $location),
                $this->position(),
            )
            ->assertForbidden();
        $this->actingAs($recipient)
            ->deleteJson(route('chat.live-locations.destroy', $location))
            ->assertForbidden();

        $this->actingAs($sender)
            ->deleteJson(route('chat.live-locations.destroy', $location))
            ->assertOk()
            ->assertJsonPath('live_location.status', 'stopped')
            ->assertJsonPath('live_location.version', 2);
        $this->actingAs($sender)
            ->deleteJson(route('chat.live-locations.destroy', $location))
            ->assertOk()
            ->assertJsonPath('live_location.status', 'stopped')
            ->assertJsonPath('live_location.version', 2);

        $location->refresh();
        $this->assertSame(ChatLiveLocation::STOP_USER, $location->stop_reason);
        $this->assertSame(2, $location->version);
    }

    public function test_expired_share_rejects_further_position_updates(): void
    {
        [$sender, , $chat] = $this->directChat();
        $location = $this->startLocation($sender, $chat, 15);
        $this->travel(16)->minutes();

        $this->actingAs($sender)
            ->patchJson(
                route('chat.live-locations.update', $location),
                $this->position(),
            )
            ->assertStatus(410);

        $location->refresh();
        $this->assertSame('expired', $location->status());
        $this->assertSame(1, $location->version);
        $this->assertNull($location->stopped_at);
    }

    public function test_call_chat_requires_an_active_non_removed_participant_and_stops_on_end(): void
    {
        [$owner, $participant, $chat, $room] = $this->callChat();
        $location = $this->startLocation($owner, $chat, 60);

        $this->actingAs($participant)
            ->patchJson(
                route('chat.live-locations.update', $location),
                $this->position(),
            )
            ->assertForbidden();

        $room->update([
            'status' => 'ended',
            'ended_at' => now(),
            'ended_reason' => 'completed',
        ]);

        $this->actingAs($owner)
            ->patchJson(
                route('chat.live-locations.update', $location),
                $this->position(),
            )
            ->assertStatus(410);

        $location->refresh();
        $this->assertSame('stopped', $location->status());
        $this->assertSame(ChatLiveLocation::STOP_CALL_ENDED, $location->stop_reason);
        Notification::assertNothingSent();
    }

    public function test_lifecycle_stop_helpers_isolate_user_and_chat_scope(): void
    {
        [$firstUser, $secondUser, $chat] = $this->directChat();
        $first = $this->startLocation($firstUser, $chat, 60);
        $second = $this->startLocation($secondUser, $chat, 60, [
            'latitude' => 50.110924,
            'longitude' => 8.682127,
        ]);
        $service = app(ChatLiveLocationService::class);

        $this->assertSame(1, $service->stopForUserInChat(
            $chat,
            $firstUser,
            ChatLiveLocation::STOP_CHAT_LEFT,
        ));
        $this->assertSame(ChatLiveLocation::STOP_CHAT_LEFT, $first->refresh()->stop_reason);
        $this->assertTrue($second->refresh()->isActive());

        $this->assertSame(1, $service->stopForChat($chat, ChatLiveLocation::STOP_CALL_ENDED));
        $this->assertSame(ChatLiveLocation::STOP_CALL_ENDED, $second->refresh()->stop_reason);
        $this->assertSame(0, $service->stopForChat($chat, ChatLiveLocation::STOP_CALL_ENDED));
    }

    public function test_logout_stops_all_of_the_users_active_shares_only(): void
    {
        [$firstUser, $secondUser, $firstChat] = $this->directChat();
        $thirdUser = User::factory()->create();
        $secondChat = Chat::create([
            'type' => 'direct',
            'created_by' => $firstUser->id,
        ]);
        $this->attachChatParticipants($secondChat, [$firstUser, $thirdUser]);

        $first = $this->startLocation($firstUser, $firstChat, 60);
        $second = $this->startLocation($firstUser, $secondChat, 90, [
            'latitude' => 49.00689,
            'longitude' => 8.403653,
        ]);
        $otherUsersShare = $this->startLocation($secondUser, $firstChat, 30, [
            'latitude' => 51.227741,
            'longitude' => 6.773456,
        ]);

        $this->actingAs($firstUser);
        Auth::guard('web')->logout();

        $this->assertSame(ChatLiveLocation::STOP_LOGOUT, $first->refresh()->stop_reason);
        $this->assertSame(ChatLiveLocation::STOP_LOGOUT, $second->refresh()->stop_reason);
        $this->assertSame('stopped', $first->status());
        $this->assertSame('stopped', $second->status());
        $this->assertTrue($otherUsersShare->refresh()->isActive());
    }

    public function test_export_never_contains_coordinates_and_tombstone_erases_location_payload(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $location = $this->startLocation($sender, $chat, 45, [
            'latitude' => 47.376887,
            'longitude' => 8.541694,
        ]);

        $content = $this->actingAs($recipient)
            ->get(route('chat.export', $chat))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('45 min', $content);
        $this->assertStringNotContainsString('47.376887', $content);
        $this->assertStringNotContainsString('8.541694', $content);
        $this->assertStringNotContainsString('latitude', $content);
        $this->assertStringNotContainsString('longitude', $content);

        $message = $location->message()->firstOrFail();
        app(ChatMessageInteractionService::class)->tombstone($chat, $sender, $message->id);

        $this->assertDatabaseMissing('chat_live_locations', ['id' => $location->id]);
        $this->assertSoftDeleted('chat_messages', ['id' => $message->id]);
        $tombstone = ChatMessage::withTrashed()->findOrFail($message->id);
        $this->assertSame('deleted', $tombstone->message_type);
        $this->assertSame('', $tombstone->body);
    }

    /** @return array{User, User, Chat} */
    private function directChat(): array
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $chat = Chat::create([
            'type' => 'direct',
            'created_by' => $sender->id,
        ]);
        $this->attachChatParticipants($chat, [$sender, $recipient]);

        return [$sender, $recipient, $chat];
    }

    /** @return array{User, User, Chat, Room} */
    private function callChat(): array
    {
        $owner = User::factory()->create();
        $participant = User::factory()->create();
        $chat = Chat::create([
            'type' => 'call',
            'name' => 'Zeitlich begrenzter Standort-Call',
            'created_by' => $owner->id,
        ]);
        $this->attachChatParticipants($chat, [$owner, $participant]);
        $room = Room::create([
            'name' => 'Aktiver Standort-Call',
            'type' => 'direct',
            'status' => 'active',
            'owner_id' => $owner->id,
            'call_chat_id' => $chat->id,
            'started_at' => now()->subMinute(),
            'connected_at' => now()->subMinute(),
        ]);
        $room->participants()->createMany([
            [
                'user_id' => $owner->id,
                'role' => 'host',
                'connection' => 'joined',
                'joined_at' => now()->subMinute(),
            ],
            [
                'user_id' => $participant->id,
                'role' => 'speaker',
                'connection' => 'joined',
                'joined_at' => now()->subMinute(),
            ],
        ]);

        return [$owner, $participant, $chat, $room];
    }

    /** @param list<User> $users */
    private function attachChatParticipants(Chat $chat, array $users): void
    {
        $joinedAt = now()->subDay();
        $rows = [];

        foreach ($users as $user) {
            $rows[$user->id] = [
                'last_read_at' => $joinedAt,
                'last_opened_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ];
        }

        $chat->participants()->attach($rows);
    }

    /**
     * @param  array<string, int|float|null>  $overrides
     * @return array<string, int|float|null>
     */
    private function position(array $overrides = []): array
    {
        return array_merge([
            'latitude' => 52.520008,
            'longitude' => 13.404954,
            'accuracy' => 8.4,
            'altitude' => 34.2,
            'altitude_accuracy' => 5.8,
            'heading' => 120.5,
            'speed' => 1.25,
        ], $overrides);
    }

    private function assertPrivateNoStore(?string $cacheControl): void
    {
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    /**
     * @param  array<string, int|float|null>  $position
     */
    private function startLocation(
        User $user,
        Chat $chat,
        int $duration,
        array $position = [],
    ): ChatLiveLocation {
        $response = $this->actingAs($user)
            ->postJson(
                route('chat.live-locations.store', $chat),
                $this->position(array_merge($position, ['duration_minutes' => $duration])),
            )
            ->assertCreated();

        return ChatLiveLocation::query()
            ->where('uuid', $response->json('live_location.id'))
            ->firstOrFail();
    }
}
