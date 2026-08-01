<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\CallChat;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageReaction;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CallChatInteractionSecurityTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();
        Storage::fake('private');
        config(['broadcasting.default' => 'log']);
    }

    public function test_call_chat_rejects_reactions_to_the_senders_own_message(): void
    {
        [$owner, , $room, $chat] = $this->callChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'body' => 'Eigene Call-Nachricht',
        ]);

        Livewire::actingAs($owner)
            ->test(CallChat::class, ['room' => $room])
            ->call('react', $message->id, '👍')
            ->assertForbidden();

        $this->assertDatabaseMissing('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_history_mode_is_locked_passive_and_has_no_poll_or_mutation_controls(): void
    {
        [$owner, $guest, $room, $chat] = $this->callChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'body' => 'Historische Nachricht',
        ]);
        ChatMessageReaction::create([
            'chat_message_id' => $message->id,
            'user_id' => $guest->id,
            'emoji' => '👍',
        ]);

        $component = Livewire::actingAs($owner)
            ->test(CallChat::class, ['room' => $room, 'readOnly' => true])
            ->assertSet('readOnly', true)
            ->assertSee('Historische Nachricht')
            ->assertSee('👍')
            ->assertDontSeeHtml('wire:poll.5s="refreshMessages"')
            ->assertDontSeeHtml('x-on:contextmenu.prevent="openActions')
            ->assertDontSeeHtml('$wire.startReply')
            ->assertDontSeeHtml('$wire.react')
            ->assertDontSeeHtml('$wire.deleteMessage');

        $component->call('startReply', $message->id)->assertForbidden();

        $locked = (new ReflectionProperty(CallChat::class, 'readOnly'))->getAttributes(Locked::class);
        $this->assertCount(1, $locked);
    }

    public function test_room_end_is_rederived_before_a_pending_reply_mutation(): void
    {
        [$owner, , $room, $chat] = $this->callChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'body' => 'Noch laufend',
        ]);
        $component = Livewire::actingAs($owner)
            ->test(CallChat::class, ['room' => $room])
            ->assertSet('readOnly', false);

        $room->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);

        $component->call('startReply', $message->id)->assertForbidden();
    }

    /** @return array{User, User, Room, Chat} */
    private function callChat(): array
    {
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $chat = Chat::create([
            'type' => 'call',
            'name' => 'Call-Chat',
            'created_by' => $owner->id,
        ]);
        $joinedAt = now()->subMinute();
        $chat->participants()->attach([
            $owner->id => [
                'joined_at' => $joinedAt,
                'last_opened_at' => $joinedAt,
                'hidden_at' => null,
            ],
            $guest->id => [
                'joined_at' => $joinedAt,
                'last_opened_at' => $joinedAt,
                'hidden_at' => null,
            ],
        ]);
        $room = Room::create([
            'name' => 'Testanruf',
            'type' => 'direct',
            'status' => 'active',
            'owner_id' => $owner->id,
            'call_chat_id' => $chat->id,
            'started_at' => now()->subMinute(),
        ]);
        $room->participants()->createMany([
            [
                'user_id' => $owner->id,
                'role' => 'host',
                'connection' => 'joined',
                'livekit_identity' => 'user-'.$owner->id,
                'joined_at' => $joinedAt,
            ],
            [
                'user_id' => $guest->id,
                'role' => 'speaker',
                'connection' => 'joined',
                'livekit_identity' => 'user-'.$guest->id,
                'joined_at' => $joinedAt,
            ],
        ]);

        return [$owner, $guest, $room, $chat];
    }
}
