<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OpenChatSoundTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

    }

    public function test_opening_a_chat_uses_existing_messages_as_silent_baseline(): void
    {
        [$recipient, $sender, $chat] = $this->directChat();
        $existingMessage = $this->incomingMessage($chat, $sender, 'Schon vorhanden');

        Livewire::actingAs($recipient)
            ->test(ChatBox::class)
            ->assertSet('latestIncomingMessageId', $existingMessage->id)
            ->call('pollTick')
            ->assertNotDispatched('rt:inbox-increased');
    }

    public function test_new_incoming_message_in_open_chat_dispatches_polling_sound(): void
    {
        [$recipient, $sender, $chat] = $this->directChat();

        $component = Livewire::actingAs($recipient)
            ->test(ChatBox::class)
            ->assertSet('latestIncomingMessageId', 0);

        $message = $this->incomingMessage($chat, $sender, 'Gerade eingegangen');

        $component
            ->call('pollTick')
            ->assertSet('latestIncomingMessageId', $message->id)
            ->assertDispatched('rt:inbox-increased', source: 'chat');
    }

    public function test_own_message_does_not_dispatch_an_incoming_sound(): void
    {
        [$recipient, $sender, $chat] = $this->directChat();

        $component = Livewire::actingAs($recipient)
            ->test(ChatBox::class);

        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Eigene Nachricht',
        ]);

        $component
            ->call('pollTick')
            ->assertNotDispatched('rt:inbox-increased');
    }

    /**
     * @return array{User, User, Chat}
     */
    protected function directChat(): array
    {
        $recipient = User::factory()->create();
        $sender = User::factory()->create();
        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([
            $recipient->id => ['last_read_at' => now(), 'last_opened_at' => null],
            $sender->id => ['last_read_at' => now(), 'last_opened_at' => null],
        ]);

        return [$recipient, $sender, $chat];
    }

    protected function incomingMessage(Chat $chat, User $sender, string $body): ChatMessage
    {
        return ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => $body,
        ]);
    }
}
