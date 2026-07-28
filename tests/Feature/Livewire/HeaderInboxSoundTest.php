<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Tools\HeaderInbox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Der Posteingangs-Zaehler stoesst den Benachrichtigungston ('rt:inbox-increased')
 * fuer den Polling-Fallback ohne Reverb an — aber nur, wenn im laufenden Betrieb
 * neue Ungelesen-Eintraege dazukommen, nie beim ersten Seitenaufbau.
 */
class HeaderInboxSoundTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

    }

    protected function unreadMessageFor(User $recipient, User $sender): Message
    {
        return Message::create([
            'subject' => 'Neue Dienstinfo',
            'message' => 'Bitte lesen.',
            'from_user' => $sender->id,
            'to_user' => $recipient->id,
            'status' => 1,
        ]);
    }

    public function test_mount_with_existing_unread_messages_stays_silent(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();
        $this->unreadMessageFor($recipient, $sender);

        Livewire::actingAs($recipient)
            ->test(HeaderInbox::class)
            ->assertSet('unreadMessagesCount', 1)
            ->assertNotDispatched('rt:inbox-increased');
    }

    public function test_new_unread_message_during_polling_dispatches_sound_event(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();

        $component = Livewire::actingAs($recipient)
            ->test(HeaderInbox::class)
            ->assertSet('unreadMessagesCount', 0);

        $message = $this->unreadMessageFor($recipient, $sender);

        $component->call('loadInbox')
            ->assertSet('unreadMessagesCount', 1)
            ->assertDispatched(
                'rt:inbox-increased',
                source: 'inbox',
                notifications: [[
                    'notification_id' => 'message:'.$message->id,
                    'category' => 'messages',
                    'title' => __('app.new_message'),
                    'body' => __('app.from').': '.$sender->name.' — '.$message->subject,
                ]],
            );
    }

    public function test_polling_without_new_messages_stays_silent(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();

        $component = Livewire::actingAs($recipient)->test(HeaderInbox::class);

        $this->unreadMessageFor($recipient, $sender);
        $component->call('loadInbox')->assertDispatched('rt:inbox-increased');

        // Unveraenderte Zaehler beim naechsten Poll -> kein weiterer Ton.
        $component->call('loadInbox')
            ->assertSet('unreadMessagesCount', 1)
            ->assertNotDispatched('rt:inbox-increased');
    }

    public function test_new_chat_message_during_polling_dispatches_sound_event(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([$sender->id, $recipient->id]);

        $component = Livewire::actingAs($recipient)
            ->test(HeaderInbox::class)
            ->assertSet('unreadChatMessagesCount', 0);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Hallo!',
        ]);

        $component->call('loadInbox')
            ->assertSet('unreadChatMessagesCount', 1)
            ->assertDispatched(
                'rt:inbox-increased',
                source: 'chat',
                notifications: [[
                    'notification_id' => 'chat-message:'.$message->id,
                    'category' => 'chat',
                    'chatId' => $chat->id,
                    'title' => __('app.new_chat_message'),
                    'body' => __('app.from').': '.$sender->name,
                ]],
            );
    }
}
