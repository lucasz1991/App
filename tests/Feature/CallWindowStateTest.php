<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\CallWindow;
use App\Models\ChatLiveLocation;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Calls\CallConversationService;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\RoomLifecycleService;
use App\Services\Chat\ChatLiveLocationService;
use App\Support\Push\PushDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CallWindowStateTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        $this->withoutMiddleware(LogActivity::class);
        config(['broadcasting.default' => 'log', 'call_recording.enabled' => false]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(PushDelivery::class)->shouldReceive('incomingCall')->andReturnNull();
        $this->mock(LiveKitService::class, function ($mock): void {
            $mock->shouldReceive('deleteRoom', 'removeParticipant', 'muteParticipantAudio', 'setParticipantPublishing')
                ->never()->byDefault();
        });
    }

    public function test_call_windows_pass_the_rooms_camera_mode_without_a_device_check_at_join(): void
    {
        [$host, , $room] = $this->roomWithParticipants();
        app()->setLocale('de');

        foreach (['call', 'meeting'] as $type) {
            foreach ([false, true] as $video) {
                $room->update(['type' => $type, 'settings' => ['video' => $video]]);
                $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
                $window->assertSeeHtml('startWithVideo: '.($video ? 'true' : 'false'))
                    ->assertDontSee('Geräte vor dem Beitritt prüfen')
                    ->assertDontSee('Am Anruf teilnehmen');
            }
        }
    }

    public function test_quality_rollout_resolves_each_direction_for_the_signed_in_participant(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $this->acceptedInvitation($room, $host, $guest);
        config([
            'livekit.quality_pilot_users' => '123456789, '.$host->id,
            'livekit.quality_upload' => 'active',
            'livekit.quality_download' => 'observe',
        ]);
        $enabled = ['upload' => 'active', 'download' => 'observe'];

        Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room])->assertViewHas('qualityPilot', $enabled);
        Livewire::actingAs($guest)->test(CallWindow::class, ['room' => $room])
            ->assertViewHas('qualityPilot', ['upload' => 'off', 'download' => 'off']);

        config(['livekit.quality_pilot_users' => '']);
        Livewire::actingAs($guest)->test(CallWindow::class, ['room' => $room])->assertViewHas('qualityPilot', $enabled);
    }

    public function test_full_call_window_loads_the_call_module_once_before_alpine_in_the_head(): void
    {
        [$host, , $room] = $this->roomWithParticipants();
        $response = $this->actingAs($host)->get(route('calls.window', $room))->assertOk();
        $html = $response->getContent();
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $callsFile = '/build/'.$manifest['resources/js/calls.js']['file'];
        $appFile = '/build/'.$manifest['resources/js/app.js']['file'];
        preg_match_all('/<script\b[^>]*\bsrc="([^"]+)"[^>]*>/', $html, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $calls = array_values(array_filter($scripts, fn (array $script): bool => str_ends_with($script[1][0], $callsFile)));
        $app = array_values(array_filter($scripts, fn (array $script): bool => str_ends_with($script[1][0], $appFile)));

        $this->assertCount(1, $calls);
        $this->assertCount(1, $app);
        $headEnd = strpos($html, '</head>');
        $this->assertNotFalse($headEnd);
        $this->assertLessThan($app[0][0][1], $calls[0][0][1]);
        $this->assertLessThan($headEnd, $calls[0][0][1]);
        $this->assertStringNotContainsString($callsFile, substr($html, $headEnd));
    }

    public function test_retained_window_cannot_clear_a_removal_by_hanging_up(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $this->acceptedInvitation($room, $host, $guest);
        $window = Livewire::actingAs($guest)->test(CallWindow::class, ['room' => $room]);

        $guestParticipant = $room->participantFor($guest);
        $guestParticipant->forceFill(['connection' => 'disconnected', 'role' => 'viewer', 'left_at' => now()])->save();

        $window->call('leaveCall')->assertNoRedirect()->assertDispatched('communication:call-ended', roomUuid: $room->uuid);

        $this->assertSame('disconnected', $guestParticipant->fresh()->connectionState());
        $this->assertSame('active', $room->fresh()->status);
        $this->assertFalse($room->fresh()->mayJoin($guest));
        $this->actingAs($guest)->postJson(route('calls.token', $room))->assertForbidden();
    }

    public function test_lifecycle_leave_keeps_a_removal_even_when_given_a_stale_participant(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $this->acceptedInvitation($room, $host, $guest);
        $stale = $room->participantFor($guest);

        $room->participants()->whereKey($stale->id)->update(['connection' => 'disconnected', 'role' => 'viewer']);
        app(RoomLifecycleService::class)->leave($room, $stale);

        $this->assertSame('disconnected', $stale->fresh()->connectionState());
        $this->assertFalse($room->fresh()->mayJoin($guest));
        $this->assertSame(0, $room->events()->where('type', 'participant_left')->count());
    }

    #[DataProvider('moderationActions')]
    public function test_removed_moderator_cannot_mutate_from_a_retained_window(string $action): void
    {
        [$host, $moderator, $room] = $this->roomWithParticipants('moderator', true);
        $this->acceptedInvitation($room, $host, $moderator);
        $target = $this->userWithCalls();
        $targetParticipant = $this->participant($room, $target);
        $window = Livewire::actingAs($moderator)->test(CallWindow::class, ['room' => $room]);

        $room->participants()->where('user_id', $moderator->id)
            ->update(['connection' => 'disconnected', 'role' => 'viewer']);

        $arguments = in_array($action, ['muteParticipant', 'removeParticipant', 'toggleRole'], true)
            ? [$targetParticipant->id]
            : [];
        $window->call($action, ...$arguments)->assertForbidden();

        $this->assertSame('active', $room->fresh()->status);
        $this->assertSame('joined', $targetParticipant->fresh()->connectionState());
        $this->assertSame('speaker', $targetParticipant->fresh()->role);
        $this->assertSame(1, $room->invitations()->count());
    }

    #[DataProvider('moderationActions')]
    public function test_ended_room_rejects_mutation_from_a_retained_window(string $action): void
    {
        [$host, , $room] = $this->roomWithParticipants();
        $target = $this->userWithCalls();
        $targetParticipant = $this->participant($room, $target);
        $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
        $room->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

        $arguments = in_array($action, ['muteParticipant', 'removeParticipant', 'toggleRole'], true)
            ? [$targetParticipant->id]
            : [];
        $window->call($action, ...$arguments)->assertStatus(410);

        $this->assertSame('ended', $room->fresh()->status);
        $this->assertSame(0, $room->invitations()->count());
        $this->assertSame('speaker', $targetParticipant->fresh()->role);
    }

    public function test_revoked_join_permission_blocks_existing_window_invites(): void
    {
        [$host, , $room] = $this->roomWithParticipants();
        $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
        $host->currentTeam->forceFill(['rbac_permissions' => ['calls.join' => false]])->save();
        $host->unsetRelation('currentTeam');

        $window->call('inviteUsers')->assertForbidden();
        $this->assertSame(0, $room->invitations()->count());
    }

    public function test_demoted_moderator_cannot_end_the_room_from_an_old_window(): void
    {
        [$host, $moderator, $room] = $this->roomWithParticipants('moderator');
        $this->acceptedInvitation($room, $host, $moderator);
        $window = Livewire::actingAs($moderator)->test(CallWindow::class, ['room' => $room]);
        $room->participants()->where('user_id', $moderator->id)->update(['role' => 'viewer']);

        $window->call('endCall')->assertForbidden();
        $this->assertSame('active', $room->fresh()->status);
    }

    public function test_normal_leave_is_idempotent_and_keeps_authorized_rejoin_available(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $this->acceptedInvitation($room, $host, $guest);
        $stale = $room->participantFor($guest);
        $lifecycle = app(RoomLifecycleService::class);

        $lifecycle->leave($room, $stale);
        $lifecycle->leave($room, $stale);

        $this->assertSame('left', $stale->fresh()->connectionState());
        $this->assertSame('active', $room->fresh()->status);
        $this->assertTrue($room->fresh()->mayJoin($guest));
        $this->assertSame(1, $room->events()->where('type', 'participant_left')->count());
    }

    public function test_personal_leave_does_not_end_an_accepted_guests_call_before_the_join_webhook(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $this->acceptedInvitation($room, $host, $guest);
        $room->participantFor($guest)->update(['connection' => 'invited']);

        app(RoomLifecycleService::class)->leave($room, $room->participantFor($host));

        $this->assertSame('active', $room->fresh()->status);
        $this->assertSame('left', $room->fresh()->participantFor($host)->connectionState());
    }

    public function test_leave_stops_the_own_live_location_when_the_left_webhook_was_faster(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $chat = app(CallConversationService::class)->createForRoom($room, $host);
        $participant = $room->participantFor($guest);
        $participant->forceFill(['connection' => 'left'])->save();
        $this->mock(ChatLiveLocationService::class)
            ->shouldReceive('stopForUserInChat')
            ->once()
            ->with($chat->id, $guest->id, ChatLiveLocation::STOP_CALL_LEFT)
            ->andReturn(0);

        app(RoomLifecycleService::class)->leave($room, $participant);

        $this->assertSame('active', $room->fresh()->status);
        $this->assertSame('left', $participant->fresh()->connectionState());
        $this->assertSame(0, $room->events()->where('type', 'participant_left')->count());
    }

    public function test_host_hangup_stops_ringing_even_when_the_left_webhook_was_faster(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $room->participantFor($guest)->forceFill(['connection' => 'invited'])->save();
        $invitation = $room->invitations()->create([
            'inviter_id' => $host->id,
            'invitee_id' => $guest->id,
            'status' => 'pending',
            'expires_at' => now()->addMinute(),
        ]);
        $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
        $livekit = app(LiveKitService::class);
        $livekit->shouldReceive('hasConnectedParticipants')->once()->andReturnNull();
        $livekit->shouldReceive('deleteRoom')->once()->andReturnTrue();

        app(RoomLifecycleService::class)->markParticipantLeft($room, 'user-'.$host->id);
        $this->assertSame('left', $room->fresh()->participantFor($host)->connectionState());
        $this->assertSame('active', $room->fresh()->status);

        $window->call('leaveCall')->assertNoRedirect()->assertDispatched('communication:call-ended', roomUuid: $room->uuid);
        $window->call('leaveCall')->assertNoRedirect()->assertDispatched('communication:call-ended', roomUuid: $room->uuid);

        $this->assertSame('cancelled', $room->fresh()->status);
        $this->assertSame('missed', $invitation->fresh()->status);
        $this->assertSame(1, $room->events()->where('type', 'participant_left')->count());
        $this->assertSame(1, $room->events()->where('type', 'cancelled')->count());
    }

    public function test_last_guest_leave_marks_empty_when_the_left_webhook_was_faster(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $room->participants()->update(['connection' => 'left']);

        app(RoomLifecycleService::class)->leave($room, $room->participantFor($guest));

        $this->assertSame('ended', $room->fresh()->status);
        $this->assertSame('empty', $room->fresh()->ended_reason);
        $this->assertSame(0, $room->events()->where('type', 'participant_left')->count());
    }

    #[DataProvider('reinvitableStates')]
    public function test_explicit_reinvite_reopens_a_past_attempt_only_after_acceptance(
        string $connection,
        string $invitationStatus,
        bool $expired,
    ): void {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $participant = $room->participantFor($guest);
        $participant->forceFill([
            'connection' => $connection,
            'role' => $connection === 'disconnected' ? 'viewer' : 'speaker',
            'left_at' => in_array($connection, ['left', 'disconnected'], true) ? now() : null,
        ])->save();
        $old = $room->invitations()->create([
            'inviter_id' => $host->id,
            'invitee_id' => $guest->id,
            'status' => $invitationStatus,
            'expires_at' => $expired ? now()->subMinute() : now()->addMinute(),
        ]);

        $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
        $window->assertViewHas('inviteCandidates', fn ($users) => $users->contains('id', $guest->id))
            ->set('inviteeIds', [$guest->id])
            ->call('inviteUsers')
            ->assertDispatched('rt-call-waiting');

        $latest = $room->invitations()->where('invitee_id', $guest->id)->latest('id')->firstOrFail();
        $this->assertNotSame($old->id, $latest->id);
        $this->assertSame('pending', $latest->status);
        $this->assertSame('invited', $participant->fresh()->connectionState());
        $this->assertSame('speaker', $participant->fresh()->role);
        $this->assertFalse($room->fresh()->mayJoin($guest));
        $this->assertSame([$latest->id], array_column($window->instance()->waitingFor(), 'id'));

        app(CallInvitationService::class)->accept($latest);
        $this->assertTrue($room->fresh()->mayJoin($guest));
        $this->assertSame([], $window->instance()->waitingFor());
        $this->assertSame($invitationStatus, $old->fresh()->status);
    }

    #[DataProvider('busyStates')]
    public function test_active_or_currently_joining_users_are_not_rung_again(
        string $connection,
        string $invitationStatus,
    ): void {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $participant = $room->participantFor($guest);
        $participant->forceFill(['connection' => $connection])->save();
        $room->invitations()->create([
            'inviter_id' => $host->id,
            'invitee_id' => $guest->id,
            'status' => $invitationStatus,
            'expires_at' => now()->addMinute(),
        ]);

        $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
        $window->assertViewHas('inviteCandidates', fn ($users) => ! $users->contains('id', $guest->id))
            ->set('inviteeIds', [$guest->id, $host->id])
            ->call('inviteUsers');

        $this->assertSame(1, $room->invitations()->count());
        $this->assertSame($connection, $participant->fresh()->connectionState());
    }

    public function test_invite_submission_rechecks_a_candidate_who_joined_since_render(): void
    {
        [$host, $guest, $room] = $this->roomWithParticipants();
        $guestParticipant = $room->participantFor($guest);
        $guestParticipant->forceFill(['connection' => 'left'])->save();
        $this->acceptedInvitation($room, $host, $guest);
        $window = Livewire::actingAs($host)->test(CallWindow::class, ['room' => $room]);
        $window->assertViewHas('inviteCandidates', fn ($users) => $users->contains('id', $guest->id));

        $guestParticipant->forceFill(['connection' => 'joined'])->save();
        $window->set('inviteeIds', [$guest->id])->call('inviteUsers');

        $this->assertSame(1, $room->invitations()->count());
        $this->assertSame('joined', $guestParticipant->fresh()->connectionState());
    }

    public static function moderationActions(): array
    {
        return array_map(fn (string $action): array => [$action], [
            'endCall', 'inviteUsers', 'muteParticipant', 'removeParticipant', 'toggleRole',
        ]);
    }

    public static function reinvitableStates(): array
    {
        return [
            'declined' => ['invited', 'declined', false],
            'missed' => ['invited', 'missed', false],
            'expired-pending' => ['invited', 'pending', true],
            'left' => ['left', 'accepted', false],
            'removed' => ['disconnected', 'accepted', false],
            'removed-while-ringing' => ['disconnected', 'pending', false],
        ];
    }

    public static function busyStates(): array
    {
        return [
            'connected' => ['joined', 'accepted'],
            'ringing' => ['invited', 'pending'],
            'accepted-and-joining' => ['invited', 'accepted'],
        ];
    }

    /** @return array{User, User, Room} */
    protected function roomWithParticipants(string $guestRole = 'speaker', bool $moderate = false): array
    {
        $host = $this->userWithCalls();
        $guest = $this->userWithCalls($moderate);
        $room = Room::create([
            'name' => 'Isolierter Anrufstatus',
            'type' => 'meeting',
            'status' => 'active',
            'owner_id' => $host->id,
        ]);
        $this->participant($room, $host, 'host');
        $this->participant($room, $guest, $guestRole);

        return [$host, $guest, $room];
    }

    protected function participant(Room $room, User $user, string $role = 'speaker'): RoomParticipant
    {
        return $room->participants()->create([
            'user_id' => $user->id,
            'role' => $role,
            'connection' => 'joined',
            'joined_at' => now(),
            'livekit_identity' => 'user-'.$user->id,
        ]);
    }

    protected function acceptedInvitation(Room $room, User $host, User $guest): void
    {
        $room->invitations()->create([
            'inviter_id' => $host->id,
            'invitee_id' => $guest->id,
            'status' => 'accepted',
            'expires_at' => now()->addMinute(),
            'responded_at' => now(),
        ]);
    }

    protected function userWithCalls(bool $moderate = false): User
    {
        $user = User::factory()->create();
        $team = $user->ownedTeams()->create([
            'name' => 'Testteam '.$user->id,
            'personal_team' => true,
            'rbac_permissions' => ['calls.join' => true, 'calls.moderate' => $moderate],
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }
}
