<?php

namespace Tests\Feature;

use App\Events\CallTimelineChanged;
use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\Meetings;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CommunicationMeeting;
use App\Models\Room;
use App\Models\User;
use App\Services\Calls\CallAvailabilityService;
use App\Services\Calls\CallConversationService;
use App\Services\Calls\CallInfrastructureUnavailable;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\CallTimelineService;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\MeetingPlanningService;
use App\Services\Calls\RoomLifecycleService;
use App\Services\Chat\ChatMessageInteractionService;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CommunicationBackendTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_10_15_220000_add_communication_calls_and_meetings.php'))->up();
        $this->withoutMiddleware(LogActivity::class);
        config(['broadcasting.default' => 'log', 'call_recording.enabled' => false]);
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Event::fake([CallTimelineChanged::class]);
        $this->partialMock(LiveKitService::class, function ($mock): void {
            $mock->shouldReceive('createRoom', 'deleteRoom')->andReturnTrue()->byDefault();
            $mock->shouldReceive('hasConnectedParticipants')->andReturnFalse()->byDefault();
        });
    }

    public function test_call_start_creates_one_typed_original_chat_row_and_private_call_chat_stays_private(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host, false);
        $message = ChatMessage::where('call_room_id', $room->id)->sole();
        $this->assertTrue($message->isCall());
        $this->assertSame($chat->id, $message->chat_id);
        $this->assertSame('Sprachanruf', $message->body);
        $this->assertNotSame($chat->id, $room->call_chat_id);
        $this->assertNull($room->callChat->participantFor($guest));
        app(CallTimelineService::class)->ensureForRoom($room);
        app(CallConversationService::class)->createForRoom($room, $host);
        $this->assertSame(1, ChatMessage::where('call_room_id', $room->id)->count());
        $this->assertSame($room->created_at->timestamp, $message->created_at->timestamp);
        Notification::assertNothingSent();
    }

    public function test_summary_event_waits_for_commit_and_does_not_survive_rollback(): void
    {
        [$host, , $chat] = $this->chat();
        DB::beginTransaction();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        Event::assertNotDispatched(CallTimelineChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(CallTimelineChanged::class);
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_typed_call_reference_rejects_a_different_chat(): void
    {
        [$host, , $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $other = Chat::create(['type' => 'group', 'name' => 'Fremd', 'created_by' => $host->id]);
        $this->expectException(HttpException::class);
        (new ChatMessage)->forceFill(['chat_id' => $other->id, 'call_room_id' => $room->id, 'user_id' => $host->id, 'message_type' => 'call', 'body' => 'fake'])->save();
    }

    public function test_declined_and_missed_are_personal_and_do_not_grant_private_history(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $room->participants()->create(['user_id' => $guest->id, 'role' => 'speaker', 'connection' => 'invited']);
        $invitation = $room->invitations()->create(['inviter_id' => $host->id, 'invitee_id' => $guest->id, 'status' => 'declined', 'expires_at' => now()]);
        $message = ChatMessage::where('call_room_id', $room->id)->sole();
        $projection = app(CallTimelineService::class)->projectionFor($message, $guest);
        $this->assertSame('declined', $projection['status']);
        $this->assertFalse($projection['can_open_history']);
        $this->assertFalse($projection['can_join']);
        $this->assertSame('ringing', app(CallTimelineService::class)->projectionFor($message, $host)['status']);
        $invitation->update(['status' => 'missed']);
        $this->assertSame('missed', app(CallTimelineService::class)->projectionFor($message, $guest)['status']);
        $this->assertArrayNotHasKey('participants', $projection);
        $this->assertArrayNotHasKey('call_chat_id', $projection);
    }

    public function test_clearing_chat_hides_its_older_call_summary(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $message = ChatMessage::where('call_room_id', $room->id)->sole();
        $chat->participants()->updateExistingPivot($guest->id, ['cleared_at' => now()->addSecond()]);
        $this->expectException(HttpException::class);
        app(CallTimelineService::class)->projectionFor($message, $guest);
    }

    public function test_legacy_approximated_connection_has_no_fabricated_exact_duration(): void
    {
        [$host, , $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $room->forceFill(['connected_at' => now()->subMinute(), 'ended_at' => now(), 'status' => 'ended'])->save();
        $room->events()->create(['type' => 'created', 'external_id' => 'backfill:room:'.$room->id.':created', 'occurred_at' => now()]);
        $projection = app(CallTimelineService::class)->projectionFor(ChatMessage::where('call_room_id', $room->id)->sole(), $host);
        $this->assertTrue($projection['duration_is_estimated']);
        $this->assertNull($projection['duration_seconds']);
    }

    public function test_system_call_cannot_be_replied_reacted_or_deleted(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $message = ChatMessage::where('call_room_id', $room->id)->sole();
        $interactions = app(ChatMessageInteractionService::class);
        foreach (['reply', 'react', 'delete'] as $operation) {
            try {
                match ($operation) {
                    'reply' => $interactions->replyTarget($chat, $guest, $message->id),
                    'react' => $interactions->toggleReaction($chat, $guest, $message->id, '👍'),
                    'delete' => $interactions->tombstone($chat, $host, $message->id),
                };
                $this->fail('System call action must be denied.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame('call', $message->fresh()->message_type);
        $this->assertSame(0, $message->reactions()->count());
    }

    public function test_termin_and_rsvp_create_no_room_or_ringing_invitation(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        app(MeetingPlanningService::class)->respond($meeting, $guest, 'accepted');
        $this->assertSame(0, Room::count());
        $this->assertDatabaseCount('room_invitations', 0);
        $this->assertSame('accepted', $meeting->participants()->where('user_id', $guest->id)->value('response'));
        $this->assertSame('UTC', $meeting->starts_at->timezoneName);
        $this->assertSame('Europe/Berlin', $meeting->timezone);
        Notification::assertNothingSent();
    }

    public function test_organizer_start_is_idempotent_and_join_requires_explicit_membership(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        $service = app(MeetingPlanningService::class);
        $room = $service->start($meeting, $host);
        $this->assertSame($room->id, $service->start($meeting, $host)->id);
        $this->assertSame(1, Room::count());
        $this->assertSame(1, ChatMessage::where('call_room_id', $room->id)->count());
        $this->assertNull($room->participantFor($guest));
        $joined = $service->join($meeting, $guest);
        $this->assertTrue($joined->mayJoin($guest));
        $this->assertNotNull($joined->callChat->participantFor($guest));
        $outsider = $this->user();
        $this->assertFalse($room->canAccessMeeting($outsider));
        $this->assertFalse(Room::accessibleMeetings($outsider)->whereKey($room->id)->exists());
        $this->expectException(HttpException::class);
        $service->joinRoom($room, $outsider);
    }

    public function test_removed_meeting_member_stays_banned(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        $service = app(MeetingPlanningService::class);
        $room = $service->start($meeting, $host);
        $service->join($meeting, $guest);
        $room->participants()->where('user_id', $guest->id)->update(['connection' => 'disconnected']);
        $this->expectException(HttpException::class);
        $service->join($meeting, $guest);
    }

    public function test_nonorganizer_cannot_start_or_cancel_meeting(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        foreach (['start', 'cancel'] as $method) {
            try {
                app(MeetingPlanningService::class)->{$method}($meeting, $guest);
                $this->fail('Organizer action must be denied.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame(0, Room::count());
    }

    public function test_cancelled_plan_cannot_start_and_live_plan_cannot_be_silently_cancelled(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        $service = app(MeetingPlanningService::class);
        $service->cancel($meeting, $host);
        try {
            $service->start($meeting, $host);
            $this->fail('Cancelled plan cannot start.');
        } catch (HttpException $error) {
            $this->assertSame(410, $error->getStatusCode());
        }
        $other = $this->plan($host, $guest);
        $service->start($other, $host);
        $this->expectException(HttpException::class);
        $service->cancel($other, $host);
    }

    public function test_media_failure_preserves_plan_and_marks_failed_session_without_ringing(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        app(LiveKitService::class)->shouldReceive('createRoom')->once()->andReturnFalse();
        try {
            app(MeetingPlanningService::class)->start($meeting, $host);
            $this->fail('Failed media must not report a working session.');
        } catch (CallInfrastructureUnavailable) {
            $this->assertSame('scheduled', $meeting->fresh()->status);
            $this->assertSame('cancelled', Room::sole()->status);
            $this->assertSame('infrastructure_unavailable', Room::sole()->ended_reason);
            $this->assertDatabaseCount('room_invitations', 0);
        }
    }

    public function test_nonexistent_dst_time_and_bad_timezone_are_rejected(): void
    {
        [$host, $guest] = $this->chat();
        $this->expectException(ValidationException::class);
        app(MeetingPlanningService::class)->plan($host, ['title' => 'DST Planung', 'starts_at' => '2027-03-28T02:30', 'ends_at' => '2027-03-28T04:00', 'timezone' => 'Europe/Berlin', 'participant_ids' => [$guest->id]]);
    }

    public function test_embedded_meeting_details_and_rsvp_do_not_redirect_or_join(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        Livewire::actingAs($guest)->test(Meetings::class, ['embedded' => true])
            ->assertSee($meeting->title)->call('openMeeting', $meeting->id)
            ->assertSet('pane', 'details')->call('respond', $meeting->id, 'accepted')
            ->assertNoRedirect()->assertNotDispatched('communication:call');
        $this->assertSame(0, Room::count());
    }

    public function test_embedded_native_start_dispatches_same_call_contract_without_redirect(): void
    {
        [$host] = $this->chat();
        Livewire::actingAs($host)->test(Meetings::class, ['embedded' => true])
            ->set('name', 'Direkte Besprechung')->call('createMeeting')
            ->assertNoRedirect()->assertDispatched('communication:call');
        $this->assertNotNull(Room::sole()->call_chat_id);
    }

    public function test_busy_gate_blocks_a_second_room_but_allows_the_current_room(): void
    {
        [$host, , $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $availability = app(CallAvailabilityService::class);
        $this->assertSame($room->id, $availability->activeRoomFor($host)->id);
        $availability->assertAvailable($host, $room);
        $this->expectException(HttpException::class);
        app(MeetingPlanningService::class)->startInstant($host, 'Zweiter Anruf');
    }

    public function test_pure_incoming_invitation_does_not_block_until_explicitly_accepted(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $participant = $room->participants()->create(['user_id' => $guest->id, 'role' => 'speaker', 'connection' => 'invited']);
        $invite = $room->invitations()->create(['inviter_id' => $host->id, 'invitee_id' => $guest->id, 'status' => 'pending', 'expires_at' => now()->addMinute()]);
        $availability = app(CallAvailabilityService::class);
        $this->assertNull($availability->activeRoomFor($guest));
        $invite->update(['status' => 'accepted']);
        $this->assertSame($room->id, $availability->activeRoomFor($guest)->id);
        $participant->update(['connection' => 'left']);
        $this->assertNull($availability->activeRoomFor($guest));
        $participant->update(['connection' => 'disconnected']);
        $this->assertNull($availability->activeRoomFor($guest));
    }

    public function test_entry_helper_rejects_removed_expired_and_cancelled_private_plan(): void
    {
        [$host, $guest] = $this->chat();
        $plan = $this->plan($host, $guest);
        $service = app(MeetingPlanningService::class);
        $room = $service->start($plan, $host);
        $participant = $room->participants()->create(['user_id' => $guest->id, 'role' => 'speaker', 'connection' => 'invited']);
        $invite = $room->invitations()->create(['inviter_id' => $host->id, 'invitee_id' => $guest->id, 'status' => 'pending', 'expires_at' => now()->addMinute()]);
        $this->assertTrue($room->fresh()->mayEnter($guest));
        $this->assertSame('pending', $invite->fresh()->status);
        $participant->update(['connection' => 'disconnected']);
        $this->assertFalse($room->fresh()->mayEnter($guest));
        $participant->update(['connection' => 'invited']);
        $invite->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($room->fresh()->mayEnter($guest));
        $invite->update(['expires_at' => now()->addMinute()]);
        $plan->update(['status' => 'cancelled']);
        $this->assertFalse($room->fresh()->mayEnter($guest));
    }

    public function test_meeting_time_roundtrip_uses_utc_even_when_application_timezone_is_berlin(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = app(MeetingPlanningService::class)->plan($host, ['title' => 'Zeitzonen Test', 'starts_at' => '2027-06-01T14:00', 'ends_at' => '2027-06-01T15:00', 'timezone' => 'Europe/Berlin', 'participant_ids' => [$guest->id]]);
        $fresh = $meeting->fresh();
        $this->assertSame('2027-06-01 12:00', $fresh->starts_at->format('Y-m-d H:i'));
        $this->assertSame('UTC', $fresh->starts_at->timezoneName);
        $this->assertSame('14:00', $fresh->starts_at->setTimezone('Europe/Berlin')->format('H:i'));
    }

    public function test_rescheduling_requires_new_rsvp_without_creating_media_or_changing_old_chat(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        $chatId = $meeting->chat_id;
        $service = app(MeetingPlanningService::class);
        $service->respond($meeting, $guest, 'accepted');
        $service->reschedule($meeting, $host, ['title' => 'Neue Übergabe', 'starts_at' => '2027-06-01T14:00', 'ends_at' => '2027-06-01T15:00', 'timezone' => 'Europe/Berlin', 'video' => true]);
        $this->assertSame($chatId, $meeting->fresh()->chat_id);
        $this->assertSame('pending', $meeting->participants()->where('user_id', $guest->id)->value('response'));
        $this->assertSame('accepted', $meeting->participants()->where('user_id', $host->id)->value('response'));
        $this->assertSame(0, Room::count());
        $this->assertSame(0, DB::table('room_invitations')->count());
    }

    public function test_embedded_pagination_never_tracks_the_working_page_url(): void
    {
        [$host] = $this->chat();
        $component = Livewire::actingAs($host)->withQueryParams(['meetingsPage' => 19])
            ->test(Meetings::class, ['embedded' => true])->assertSet('paginators.meetingsPage', 1);
        $this->assertArrayNotHasKey('url', $component->effects);
        $component->dispatch('communication:call-ready')->assertOk();
        $component->dispatch('communication:call-released')->assertOk();
    }

    public function test_missing_schema_has_visible_unavailable_planning_and_preserves_instant_meetings(): void
    {
        [$host] = $this->chat();
        (require database_path('migrations/2026_10_15_220000_add_communication_calls_and_meetings.php'))->down();
        Livewire::actingAs($host)->test(Meetings::class, ['embedded' => true])
            ->assertSee('Die Terminplanung ist noch nicht verfügbar.')->assertViewHas('planningReady', false)
            ->set('name', 'Sofort ohne Planung')->call('createMeeting')->assertDispatched('communication:call');
    }

    public function test_forged_loss_of_settings_marker_does_not_make_a_private_room_public(): void
    {
        [$host, $guest] = $this->chat();
        $meeting = $this->plan($host, $guest);
        $room = app(MeetingPlanningService::class)->start($meeting, $host);
        $room->forceFill(['settings' => ['video' => true]])->save();
        $outside = $this->user();
        $this->assertFalse($room->canAccessMeeting($outside));
        $this->assertFalse(Room::accessibleMeetings($outside)->whereKey($room->id)->exists());
        $this->assertTrue(Room::accessibleMeetings($guest)->whereKey($room->id)->exists());
    }

    public function test_timeline_updates_do_not_create_an_extra_message_or_modify_its_chronology(): void
    {
        [$host, , $chat] = $this->chat();
        $lifecycle = app(RoomLifecycleService::class);
        $room = $lifecycle->createForChat($chat, $host);
        $message = ChatMessage::where('call_room_id', $room->id)->sole();
        $oldTime = $message->created_at->toIso8601String();
        $lifecycle->markEnded($room);
        $lifecycle->markEnded($room->fresh());
        $lifecycle->markParticipantJoined($room->fresh(), 'user-'.$host->id);
        $this->assertSame('ended', $room->fresh()->status);
        $this->assertSame(1, ChatMessage::where('call_room_id', $room->id)->count());
        $this->assertSame($oldTime, $message->fresh()->created_at->toIso8601String());
        Notification::assertNothingSent();
    }

    public function test_stale_room_webhooks_cannot_resurrect_or_replace_a_terminal_state(): void
    {
        [$host, , $chat] = $this->chat();
        $lifecycle = app(RoomLifecycleService::class);
        $room = $lifecycle->createForChat($chat, $host);
        $stale = $room->fresh();
        $lifecycle->cancel($room, 'cancelled');
        $lifecycle->markParticipantJoined($stale, 'user-'.$host->id);
        $lifecycle->markActive($stale);
        $lifecycle->markEnded($stale, 'finished');
        $this->assertSame('cancelled', $room->fresh()->status);
        $this->assertSame('cancelled', $room->fresh()->ended_reason);
        $this->assertFalse($room->fresh()->participantFor($host)->isConnected());
        $this->assertSame(1, $room->events()->where('type', 'cancelled')->count());
        $this->assertSame(0, $room->events()->whereIn('type', ['started', 'participant_joined', 'ended'])->count());
    }

    public function test_invitation_acceptance_rechecks_busy_state_under_the_user_reservation(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $incoming = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $incoming->participants()->create(['user_id' => $guest->id, 'role' => 'speaker', 'connection' => 'invited']);
        $invite = $incoming->invitations()->create(['inviter_id' => $host->id, 'invitee_id' => $guest->id, 'status' => 'pending', 'expires_at' => now()->addMinute()]);
        $outgoing = app(MeetingPlanningService::class)->startInstant($guest, 'Eigenes Gespräch');
        try {
            app(CallInvitationService::class)->accept($invite);
            $this->fail('Concurrent call cannot be accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('pending', $invite->fresh()->status);
        $this->assertNull($incoming->callChat->participantFor($guest));
        $outgoing->participants()->where('user_id', $guest->id)->update(['connection' => 'left']);
        $this->assertTrue(app(CallInvitationService::class)->accept($invite));
        $this->assertSame('accepted', $invite->fresh()->status);
    }

    public function test_batch_loaded_call_projection_adds_no_database_queries(): void
    {
        [$host, , $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $message = ChatMessage::where('call_room_id', $room->id)
            ->with(['callRoom.participants', 'callRoom.invitations', 'callRoom.events', 'callRoom.callChat.participants'])->sole();
        $message->setRelation('chat', $chat->fresh('participants'));
        $host->load('currentTeam');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $projection = app(CallTimelineService::class)->projectionFor($message, $host);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
        $this->assertTrue($projection['can_join']);
        $this->assertTrue($projection['can_open_history']);
    }

    public function test_stale_timeout_and_decline_cannot_replace_an_accepted_invitation(): void
    {
        [$host, $guest, $chat] = $this->chat();
        $room = app(RoomLifecycleService::class)->createForChat($chat, $host);
        $room->participants()->create(['user_id' => $guest->id, 'role' => 'speaker', 'connection' => 'invited']);
        $invite = $room->invitations()->create(['inviter_id' => $host->id, 'invitee_id' => $guest->id, 'status' => 'pending', 'expires_at' => now()->addMinute()]);
        $staleTimeout = $invite->fresh();
        $staleDecline = $invite->fresh();
        $service = app(CallInvitationService::class);
        $this->assertTrue($service->accept($invite));
        $service->expire($staleTimeout);
        $this->assertFalse($service->decline($staleDecline));
        $this->assertFalse($service->accept($invite));
        $this->assertSame('accepted', $invite->fresh()->status);
        $this->assertSame(1, $room->events()->where('type', 'accepted')->count());
        $this->assertSame(0, $room->events()->whereIn('type', ['declined', 'missed'])->count());
    }

    public function test_migration_compiles_mysql_identifiers_without_a_database_connection(): void
    {
        $connection = new MySqlConnection(
            fn () => throw new \LogicException('DDL compilation must not connect.'),
            'isolated_ddl', '', ['version' => '8.0.36'],
        );
        $connection->useDefaultSchemaGrammar();
        $statements = [];
        $compile = function (string $table, \Closure $callback, bool $create = false) use ($connection, &$statements): void {
            $blueprint = new Blueprint($connection, $table);
            if ($create) {
                $blueprint->create();
            }
            $callback($blueprint);
            array_push($statements, ...$blueprint->toSql());
            foreach ($blueprint->getCommands() as $command) {
                if ($command->index) {
                    $this->assertLessThanOrEqual(64, strlen($command->index), $command->index);
                }
            }
        };
        Schema::shouldReceive('table')->twice()->andReturnUsing($compile);
        Schema::shouldReceive('create')->twice()
            ->andReturnUsing(fn ($table, $callback) => $compile($table, $callback, true));
        (require database_path('migrations/2026_10_15_220000_add_communication_calls_and_meetings.php'))->up();
        $this->assertStringContainsString('`comm_meeting_participant_meeting_fk`', implode("\n", $statements));
    }

    protected function plan(User $host, User $guest): CommunicationMeeting
    {
        return app(MeetingPlanningService::class)->plan($host, [
            'title' => 'Geplante Übergabe', 'starts_at' => now()->addDay()->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDay()->addHour()->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i'),
            'timezone' => 'Europe/Berlin', 'participant_ids' => [$guest->id], 'video' => false,
        ]);
    }

    protected function chat(): array
    {
        $host = $this->user();
        $guest = $this->user();
        $chat = Chat::create(['type' => 'direct', 'created_by' => $host->id]);
        $chat->participants()->attach([$host->id => ['joined_at' => now()->subDay()], $guest->id => ['joined_at' => now()->subDay()]]);

        return [$host, $guest, $chat];
    }

    protected function user(): User
    {
        $user = User::factory()->create();
        $team = $user->ownedTeams()->create(['name' => 'Rechte '.$user->id, 'personal_team' => true, 'rbac_permissions' => ['calls.join' => true, 'calls.start' => true]]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }
}
