<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\IncomingCallOverlay;
use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\Room;
use App\Models\User;
use App\Services\Calls\CallInvitationService;
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
