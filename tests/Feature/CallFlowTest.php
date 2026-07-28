<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\CallWindow;
use App\Livewire\Calls\IncomingCallOverlay;
use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\Room;
use App\Models\User;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\RoomLifecycleService;
use App\Support\Rbac\RbacCatalog;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CallFlowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        $this->withoutMiddleware(LogActivity::class);

        // Broadcasts sollen in Tests nie einen Reverb-Server kontaktieren.
        config(['broadcasting.default' => 'log']);

        // Der verzoegerte Ring-Timeout-Job wuerde auf der Sync-Queue sofort laufen.
        \Illuminate\Support\Facades\Queue::fake();

        // Kein Test darf einen echten Media-Server brauchen. Ohne diesen Fake
        // wuerde LiveKitService gegen LIVEKIT_URL aus der .env sprechen und die
        // Suite haenge davon ab, ob lokal gerade ein LiveKit laeuft.
        $this->fakeLiveKit();
    }

    /** @param array<string, bool> $results */
    protected function fakeLiveKit(array $results = []): void
    {
        $this->partialMock(LiveKitService::class, function ($mock) use ($results): void {
            $defaults = [
                'createRoom' => true,
                'deleteRoom' => true,
                'removeParticipant' => true,
                'muteParticipantAudio' => true,
                'setParticipantPublishing' => true,
            ];

            foreach (array_merge($defaults, $results) as $method => $result) {
                $mock->shouldReceive($method)->andReturn($result);
            }
        });
    }

    public function test_rbac_catalog_contains_call_permissions(): void
    {
        $permissions = RbacCatalog::allPermissions();

        $this->assertContains('calls.start', $permissions);
        $this->assertContains('calls.join', $permissions);
        $this->assertContains('calls.moderate', $permissions);
    }

    public function test_start_call_creates_room_invitations_and_redirects(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        Livewire::actingAs($caller)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->call('startCall')
            ->assertRedirect();

        $room = Room::firstOrFail();

        $this->assertSame('pending', $room->status);
        $this->assertSame($chat->id, $room->chat_id);
        $this->assertSame($caller->id, $room->owner_id);
        $this->assertSame('host', $room->participantFor($caller)->role);

        $this->assertDatabaseHas('room_invitations', [
            'room_id' => $room->id,
            'inviter_id' => $caller->id,
            'invitee_id' => $callee->id,
            'status' => 'pending',
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExpireCallInvitation::class);

        $this->assertDatabaseHas('room_participants', [
            'room_id' => $room->id,
            'user_id' => $callee->id,
            'connection' => 'invited',
        ]);
    }

    public function test_accepting_an_invitation_redirects_into_the_call(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        Livewire::actingAs($callee)
            ->test(IncomingCallOverlay::class)
            ->call('accept', $invitation->id)
            ->assertRedirect(route('calls.window', $room));

        $this->assertSame('accepted', $invitation->fresh()->status);
    }

    public function test_declining_an_invitation_marks_it_declined(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        Livewire::actingAs($callee)
            ->test(IncomingCallOverlay::class)
            ->call('decline', $invitation->id);

        $this->assertSame('declined', $invitation->fresh()->status);
    }

    public function test_foreign_invitations_cannot_be_answered(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(IncomingCallOverlay::class)
            ->call('accept', $invitation->id)
            ->assertForbidden();

        $this->assertSame('pending', $invitation->fresh()->status);
    }

    public function test_token_endpoint_rejects_non_participants(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        $room = $this->roomWithInvitation($caller, $callee, $chat);

        $stranger = User::factory()->create();
        $this->allowCalls($stranger);

        $this->actingAs($stranger)
            ->postJson(route('calls.token', $room))
            ->assertForbidden();
    }

    public function test_ended_rooms_do_not_issue_tokens(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $room->forceFill(['status' => 'ended'])->save();

        $this->actingAs($caller)
            ->postJson(route('calls.token', $room))
            ->assertStatus(410);
    }

    public function test_accepted_invitee_receives_a_join_token(): void
    {
        $this->configureLiveKit();

        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(CallInvitationService::class)->accept($room->invitations()->firstOrFail());

        $response = $this->actingAs($callee)
            ->postJson(route('calls.token', $room))
            ->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('speaker', $response->json('role'));
        $this->assertSame('user-'.$callee->id, $response->json('identity'));
    }

    public function test_declined_invitee_can_no_longer_obtain_a_token(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(CallInvitationService::class)->decline($room->invitations()->firstOrFail());

        // Die Teilnehmerzeile aus der Klingelphase existiert weiterhin – sie
        // darf allein kein Beitrittsrecht mehr sein.
        $this->assertNotNull($room->fresh()->participantFor($callee));

        $this->actingAs($callee)
            ->postJson(route('calls.token', $room))
            ->assertForbidden();
    }

    public function test_missed_invitee_can_no_longer_obtain_a_token(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(CallInvitationService::class)->expire($room->invitations()->firstOrFail());

        $this->actingAs($callee)
            ->postJson(route('calls.token', $room))
            ->assertForbidden();
    }

    public function test_a_new_invitation_reopens_a_declined_participation(): void
    {
        $this->configureLiveKit();

        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(CallInvitationService::class)->decline($room->invitations()->firstOrFail());

        app(CallInvitationService::class)->inviteOne($room->fresh(), $caller, $callee);
        app(CallInvitationService::class)->accept(
            $room->invitations()->where('status', 'pending')->firstOrFail(),
        );

        $this->actingAs($callee)
            ->postJson(route('calls.token', $room))
            ->assertOk();
    }

    public function test_removed_participant_cannot_rejoin_the_call(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(CallInvitationService::class)->accept($room->invitations()->firstOrFail());

        $target = $room->participantFor($callee);

        Livewire::actingAs($caller)
            ->test(CallWindow::class, ['room' => $room])
            ->call('removeParticipant', $target->id);

        $target->refresh();

        $this->assertSame('disconnected', $target->connectionState());
        // Rolle faellt auf 'viewer', damit ein noch gueltiges Token nicht sendet.
        $this->assertSame('viewer', $target->role);

        $this->actingAs($callee)
            ->postJson(route('calls.token', $room))
            ->assertForbidden();
    }

    public function test_host_hanging_up_while_ringing_cancels_the_call(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        // Niemand ist verbunden – 'joined' setzt erst der participant_joined-Webhook.
        Livewire::actingAs($caller)
            ->test(CallWindow::class, ['room' => $room])
            ->call('leaveCall');

        $this->assertSame('cancelled', $room->fresh()->status);
        $this->assertSame('missed', $invitation->fresh()->status);
    }

    public function test_room_ends_once_the_last_participant_leaves(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        $room->forceFill(['status' => 'active'])->save();
        $room->participants()->update(['connection' => 'joined']);

        $lifecycle = app(RoomLifecycleService::class);

        $lifecycle->markParticipantLeft($room, 'user-'.$caller->id);
        $this->assertSame('active', $room->fresh()->status);

        $lifecycle->markParticipantLeft($room, 'user-'.$callee->id);
        $this->assertSame('ended', $room->fresh()->status);
    }

    public function test_a_cancelled_room_no_longer_blocks_new_calls_in_the_chat(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(RoomLifecycleService::class)->cancel($room);

        $this->assertNull($chat->fresh()->activeRoom()->first());
    }

    public function test_moderation_fails_closed_when_the_sfu_rejects_it(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(CallInvitationService::class)->accept($room->invitations()->firstOrFail());

        // Der Medienserver nimmt den Aufruf nicht an.
        $this->fakeLiveKit(['removeParticipant' => false]);

        $target = $room->participantFor($callee);

        Livewire::actingAs($caller)
            ->test(CallWindow::class, ['room' => $room])
            ->call('removeParticipant', $target->id)
            ->assertDispatched('swal:toast');

        // Die DB darf keinen Erfolg vortaeuschen, den die SFU nie durchgesetzt hat.
        $this->assertSame('invited', $target->fresh()->connectionState());
        $this->assertSame('speaker', $target->fresh()->role);
    }

    public function test_call_moderate_permission_does_not_reach_into_foreign_calls(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        // Aussenstehender mit gesetztem Team-Recht, aber ohne Bezug zum Chat.
        $outsider = User::factory()->create();
        $outsider->ownedTeams()->create([
            'name' => 'Fremdes Team',
            'personal_team' => true,
            'rbac_permissions' => ['calls.join' => true, 'calls.moderate' => true],
        ]);
        $outsider->refresh();

        $this->assertFalse($room->canModerate($outsider));

        $this->actingAs($outsider)
            ->postJson(route('calls.token', $room))
            ->assertForbidden();
    }

    public function test_an_expired_invitation_can_no_longer_be_accepted(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        // Ring-Fenster verstrichen, ohne dass der Queue-Worker gelaufen ist.
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        Livewire::actingAs($callee)
            ->test(IncomingCallOverlay::class)
            ->call('accept', $invitation->id)
            ->assertDispatched('rt:call-gone');

        $this->assertSame('missed', $invitation->fresh()->status);
    }

    public function test_start_call_reports_an_unreachable_media_server(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();

        $this->fakeLiveKit(['createRoom' => false]);

        Livewire::actingAs($caller)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->call('startCall')
            ->assertNoRedirect()
            ->assertDispatched('swal:toast');

        // Kein halbfertiger Raum darf den Chat dauerhaft blockieren.
        $this->assertNull($chat->fresh()->activeRoom()->first());
    }

    public function test_a_late_join_webhook_does_not_revive_an_ended_room(): void
    {
        [$caller, $callee, $chat] = $this->directChatWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);

        app(RoomLifecycleService::class)->markEnded($room, 'ended');

        app(RoomLifecycleService::class)->markParticipantJoined($room, 'user-'.$callee->id);

        $this->assertSame('ended', $room->fresh()->status);
        $this->assertNotSame('joined', $room->fresh()->participantFor($callee)->connectionState());
    }

    protected function configureLiveKit(): void
    {
        config([
            'livekit.api_key' => 'RTTESTKEY',
            'livekit.api_secret' => str_repeat('s', 40),
        ]);
    }

    /**
     * @return array{User, User, Chat}
     */
    protected function directChatWithCallRights(): array
    {
        $caller = User::factory()->create();
        $callee = User::factory()->create();

        $this->allowCalls($caller);
        $this->allowCalls($callee);

        $chat = Chat::create(['type' => 'direct', 'created_by' => $caller->id]);
        $chat->participants()->attach([
            $caller->id => ['last_read_at' => now(), 'last_opened_at' => null],
            $callee->id => ['last_read_at' => now(), 'last_opened_at' => null],
        ]);

        return [$caller, $callee, $chat];
    }

    protected function roomWithInvitation(User $caller, User $callee, Chat $chat): Room
    {
        $room = Room::create([
            'name' => 'Testanruf',
            'type' => 'direct',
            'status' => 'pending',
            'owner_id' => $caller->id,
            'chat_id' => $chat->id,
        ]);

        $room->participants()->create([
            'user_id' => $caller->id,
            'role' => 'host',
            'connection' => 'invited',
            'livekit_identity' => 'user-'.$caller->id,
        ]);

        app(CallInvitationService::class)->inviteOne($room, $caller, $callee);

        return $room;
    }

    protected function allowCalls(User $user): void
    {
        $team = $user->ownedTeams()->create([
            'name' => 'Team '.$user->id,
            'personal_team' => true,
            'rbac_permissions' => [
                'calls.start' => true,
                'calls.join' => true,
            ],
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();
    }
}
