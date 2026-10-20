<?php

namespace Tests\Feature;

use App\Livewire\Calls\CallChat;
use App\Livewire\ChatBox;
use App\Livewire\Communication;
use App\Livewire\CommunicationPage;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Room;
use App\Models\RoomInvitation;
use App\Models\User;
use App\Services\Calls\CallConversationService;
use App\Services\Calls\CallTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CommunicationModuleTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = sys_get_temp_dir().'/railtime-communication-module-tests-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_10_15_220000_add_communication_calls_and_meetings.php'))->up();
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        config(['app.locale' => 'de', 'broadcasting.default' => 'log', 'call_recording.enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15T20:00:00Z'));
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            $temporaryRoot = realpath(sys_get_temp_dir());
            $compiledPath = realpath($this->compiledViews);
            if ($temporaryRoot && $compiledPath && dirname($compiledPath) === $temporaryRoot
                && str_starts_with(basename($compiledPath), 'railtime-communication-module-tests-')) {
                File::deleteDirectory($compiledPath);
            }
            parent::tearDown();
            putenv($this->previousCompiledEnvironment[0] === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$this->previousCompiledEnvironment[0]);
            foreach (['_ENV', '_SERVER'] as $index => $environment) {
                if ($this->previousCompiledEnvironment[$index + 1] === null) {
                    unset($GLOBALS[$environment]['VIEW_COMPILED_PATH']);
                } else {
                    $GLOBALS[$environment]['VIEW_COMPILED_PATH'] = $this->previousCompiledEnvironment[$index + 1];
                }
            }
        }
    }

    public function test_global_host_still_renders_for_a_chat_user_without_call_permissions(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $this->assertFalse($user->hasRbacPermission('calls.join'));

        Livewire::actingAs($user)->test(Communication::class)
            ->assertOk()
            ->assertSeeHtml('data-communication-host')
            ->assertSet('activeRoomUuid', null);
    }

    public function test_hidden_embedded_chat_mount_does_not_select_or_mark_the_last_chat_read(): void
    {
        [$recipient, $sender, $chat] = $this->chatFixture();
        $this->chatMessage($chat, $sender);

        Livewire::actingAs($recipient)->test(ChatBox::class, ['embedded' => true])
            ->assertSet('selectedChatId', null)
            ->assertSet('conversationVisible', false)
            ->call('pollTick')
            ->assertSet('selectedChatId', null);

        $this->assertNull($this->lastReadAt($chat, $recipient));
    }

    public function test_hidden_selected_chat_keeps_new_messages_unread_until_it_is_visible_again(): void
    {
        [$recipient, $sender, $chat] = $this->chatFixture();
        $this->chatMessage($chat, $sender);
        $component = Livewire::actingAs($recipient)->test(ChatBox::class, ['embedded' => true])
            ->call('openChat', $chat->id)
            ->assertSet('selectedChatId', $chat->id)
            ->call('setConversationVisibility', false)
            ->assertSet('conversationVisible', false);
        $previousReadAt = $this->lastReadAt($chat, $recipient);
        $this->assertNotNull($previousReadAt);
        $this->travel(5)->minutes();
        $this->chatMessage($chat, $sender);

        $component->call('pollTick')->call('refreshChat', $chat->id)->call('refreshTimeline');
        $this->assertSame($previousReadAt, $this->lastReadAt($chat, $recipient));

        $component->call('setConversationVisibility', true)->assertDispatched('inbox:refresh');
        $this->assertNotSame($previousReadAt, $this->lastReadAt($chat, $recipient));
    }

    public function test_communication_chat_event_cannot_open_a_foreign_or_hidden_conversation(): void
    {
        [$recipient, $sender, $chat] = $this->chatFixture();
        $foreign = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $foreign->participants()->attach($sender->id);
        Livewire::actingAs($recipient)->test(ChatBox::class, ['embedded' => true])
            ->dispatch('communication:chat', chatId: $foreign->id)->assertForbidden();

        $chat->participants()->updateExistingPivot($recipient->id, ['hidden_at' => now()]);
        Livewire::actingAs($recipient)->test(ChatBox::class, ['embedded' => true])
            ->dispatch('communication:chat', chatId: $chat->id)->assertForbidden();
    }

    public function test_communication_header_filter_updates_only_to_supported_chat_filters(): void
    {
        [$recipient] = $this->chatFixture();

        $component = Livewire::actingAs($recipient)->test(ChatBox::class, ['embedded' => true])
            ->dispatch('communication:filter', filter: 'groups')
            ->assertSet('listFilter', 'groups');

        $component->dispatch('communication:filter', filter: 'forged')
            ->assertSet('listFilter', 'all');
    }

    public function test_embedded_call_chat_reads_only_while_its_actual_panel_is_visible(): void
    {
        [$host, $guest, $room] = $this->roomFixture();
        $chat = app(CallConversationService::class)->createForRoom($room, $host);
        app(CallConversationService::class)->attachParticipant($room, $guest);
        $chat->participants()->updateExistingPivot($host->id, ['last_read_at' => now()->subDay()]);
        $this->chatMessage($chat, $guest);
        $previousReadAt = $this->lastReadAt($chat, $host);

        $component = Livewire::actingAs($host)->test(CallChat::class, ['room' => $room, 'embedded' => true])
            ->assertSet('visible', false)->call('refreshMessages');
        $this->assertSame($previousReadAt, $this->lastReadAt($chat, $host));

        $component->call('setVisible', true);
        $visibleReadAt = $this->lastReadAt($chat, $host);
        $this->assertNotSame($previousReadAt, $visibleReadAt);
        $component->call('setVisible', false);
        $this->travel(5)->minutes();
        $this->chatMessage($chat, $guest);
        $component->call('refreshMessages');
        $this->assertSame($visibleReadAt, $this->lastReadAt($chat, $host));
    }

    public function test_own_valid_pending_invitation_deep_link_reaches_the_global_call_entry(): void
    {
        [$host, $guest, $room] = $this->roomFixture();
        $invitation = $this->invitation($room, $host, $guest);
        $this->assertFalse($room->fresh()->mayJoin($guest));

        Livewire::actingAs($guest)->test(CommunicationPage::class, ['room' => $room])
            ->assertOk()->assertSet('roomUuid', $room->uuid);
        $this->assertSame('pending', $invitation->fresh()->status);
    }

    public function test_mixed_native_transcript_keeps_voice_composer_and_balanced_livewire_morph_blocks(): void
    {
        [$recipient, $sender, $chat] = $this->chatFixture();
        $this->chatMessage($chat, $sender);
        $room = Room::create(['name' => 'Native timeline fixture', 'type' => 'direct', 'status' => 'ended', 'owner_id' => $sender->id, 'chat_id' => $chat->id, 'settings' => ['video' => true]]);
        app(CallTimelineService::class)->ensureForRoom($room);
        $this->chatMessage($chat, $recipient);
        $component = Livewire::actingAs($recipient)->test(ChatBox::class, ['embedded' => true])
            ->call('openChat', $chat->id)->assertOk()
            ->assertSeeHtml('data-chat-call-event')->assertSee('Videoanruf')->assertSee('Sprachnachricht');
        $html = $component->html();
        $this->assertSame(substr_count($html, '<!--[if BLOCK]><![endif]-->'), substr_count($html, '<!--[if ENDBLOCK]><![endif]-->'));
        $component->call('pollTick')->assertOk()->assertSeeHtml('data-chat-call-event');
    }

    public function test_host_allows_pending_invitation_then_native_call_window_accepts_once(): void
    {
        [$host, $guest, $room] = $this->roomFixture();
        $invitation = $this->invitation($room, $host, $guest);
        $component = Livewire::actingAs($guest)->test(Communication::class)
            ->call('openCall', $room->uuid)
            ->assertOk()->assertSet('activeRoomUuid', $room->uuid)
            ->assertDispatched('communication:call-ready');
        $this->assertSame('accepted', $invitation->fresh()->status);
        $acceptedEvents = $room->events()->where('type', 'accepted')->count();
        $this->assertSame(1, $acceptedEvents);

        $component->call('openCall', $room->uuid)
            ->assertSet('activeRoomUuid', $room->uuid);
        $this->assertSame($acceptedEvents, $room->events()->where('type', 'accepted')->count());
    }

    #[DataProvider('deniedInvitations')]
    public function test_room_entry_preserves_invitation_and_removal_bans(string $status, bool $expired, bool $removed): void
    {
        [$host, $guest, $room] = $this->roomFixture();
        $invitation = $this->invitation($room, $host, $guest, $status, $expired);
        if ($removed) {
            $room->participants()->where('user_id', $guest->id)->update(['connection' => 'disconnected', 'role' => 'viewer']);
        }

        Livewire::actingAs($guest)->test(CommunicationPage::class, ['room' => $room])->assertForbidden();
        Livewire::actingAs($guest)->test(Communication::class)
            ->call('openCall', $room->uuid)->assertForbidden();
        $this->assertSame($status, $invitation->fresh()->status);
    }

    public static function deniedInvitations(): array
    {
        return [
            'declined' => ['declined', false, false],
            'missed' => ['missed', false, false],
            'expired pending' => ['pending', true, false],
            'removed accepted' => ['accepted', false, true],
            'removed pending' => ['pending', false, true],
        ];
    }

    public function test_host_keeps_one_active_room_and_ignores_stale_release(): void
    {
        [$host, , $room] = $this->roomFixture();
        $otherRoom = Room::create(['name' => 'Second fixture room', 'type' => 'meeting', 'status' => 'active', 'owner_id' => $host->id]);
        $otherRoom->participants()->create(['user_id' => $host->id, 'role' => 'host', 'connection' => 'left', 'joined_at' => now()->subMinute(), 'left_at' => now(), 'livekit_identity' => 'user-'.$host->id]);

        $component = Livewire::actingAs($host)->test(Communication::class)
            ->call('openCall', $room->uuid)->assertSet('activeRoomUuid', $room->uuid)
            ->call('openCall', $otherRoom->uuid)->assertSet('activeRoomUuid', $room->uuid)
            ->assertDispatched('swal:toast')
            ->call('releaseCall', $otherRoom->uuid)->assertSet('activeRoomUuid', $room->uuid)
            ->assertNotDispatched('communication:call-released');
        $this->assertSame('active', $room->fresh()->status);

        $component->call('releaseCall', $room->uuid)->assertSet('activeRoomUuid', null)
            ->assertDispatched('communication:call-released')->assertDispatched('chat:timeline-refresh');
        $this->assertSame('active', $room->fresh()->status);
    }

    public function test_nonparticipant_and_ended_room_cannot_be_opened(): void
    {
        [$host, , $room] = $this->roomFixture();
        $stranger = $this->callUser();
        Livewire::actingAs($stranger)->test(Communication::class)->call('openCall', $room->uuid)->assertForbidden();
        $room->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
        Livewire::actingAs($host)->test(Communication::class)->call('openCall', $room->uuid)->assertStatus(410);
    }

    private function chatFixture(): array
    {
        $recipient = User::factory()->create(['role' => 'staff']);
        $sender = User::factory()->create(['role' => 'staff']);
        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([$recipient->id, $sender->id]);

        return [$recipient, $sender, $chat];
    }

    private function chatMessage(Chat $chat, User $sender): void
    {
        ChatMessage::create(['chat_id' => $chat->id, 'user_id' => $sender->id, 'body' => 'Synthetic unread communication message']);
    }

    private function lastReadAt(Chat $chat, User $recipient): ?string
    {
        return DB::table('chat_user')->where('chat_id', $chat->id)->where('user_id', $recipient->id)->value('last_read_at');
    }

    private function callUser(): User
    {
        $user = User::factory()->create(['role' => 'staff']);
        $team = $user->ownedTeams()->create(['name' => 'Communication fixture team', 'personal_team' => true, 'rbac_permissions' => ['calls.join' => true]]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }

    private function roomFixture(): array
    {
        $host = $this->callUser();
        $guest = $this->callUser();
        $room = Room::create(['name' => 'Communication call fixture', 'type' => 'direct', 'status' => 'active', 'owner_id' => $host->id]);
        $room->participants()->create(['user_id' => $host->id, 'role' => 'host', 'connection' => 'joined', 'joined_at' => now(), 'livekit_identity' => 'user-'.$host->id]);
        $room->participants()->create(['user_id' => $guest->id, 'role' => 'speaker', 'connection' => 'invited', 'livekit_identity' => 'user-'.$guest->id]);

        return [$host, $guest, $room];
    }

    private function invitation(Room $room, User $host, User $guest, string $status = 'pending', bool $expired = false): RoomInvitation
    {
        return $room->invitations()->create(['inviter_id' => $host->id, 'invitee_id' => $guest->id, 'status' => $status, 'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(3)]);
    }
}
