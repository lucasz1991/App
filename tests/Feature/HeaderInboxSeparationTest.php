<?php

namespace Tests\Feature;

use App\Livewire\Tools\HeaderInbox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class HeaderInboxSeparationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    private User $recipient;

    private User $sender;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = sys_get_temp_dir().'/railtime-header-inbox-tests-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.locale' => 'de', 'app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        Http::preventStrayRequests();
        $this->recipient = User::factory()->create(['role' => 'staff', 'name' => 'Inbox Fixture', 'email' => 'header-inbox@example.invalid']);
        $this->sender = User::factory()->create(['role' => 'staff', 'name' => 'Chat Sender Fixture', 'email' => 'header-sender@example.invalid']);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            $temporaryRoot = realpath(sys_get_temp_dir());
            $compiledPath = realpath($this->compiledViews);
            if ($temporaryRoot && $compiledPath && dirname($compiledPath) === $temporaryRoot
                && str_starts_with(basename($compiledPath), 'railtime-header-inbox-tests-')) {
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

    public function test_dropdown_contains_only_own_normal_messages_and_badges_exclude_chats(): void
    {
        $this->message('Unread normal fixture');
        $this->message('Read normal fixture', ['status' => 2]);
        $this->message('Foreign private fixture', ['to_user' => $this->sender->id]);
        $chat = $this->chat();
        $this->chatMessage($chat, ['body' => 'Private chat preview fixture']);
        $this->chatMessage($chat);

        Livewire::actingAs($this->recipient)->test(HeaderInbox::class)
            ->assertSet('unreadMessagesCount', 1)
            ->assertSet('unreadChatMessagesCount', 2)
            ->assertViewHas('totalUnreadCount', 1)
            ->assertSeeHtml('data-app-badge-count="1"')
            ->assertSeeHtml('data-inbox-total="1"')
            ->assertSeeHtml('data-inbox-panel="messages"')
            ->assertSee('Unread normal fixture')
            ->assertSee('Read normal fixture')
            ->assertDontSee('Foreign private fixture')
            ->assertDontSee('Private chat preview fixture')
            ->assertDontSeeHtml('data-inbox-tabs')
            ->assertDontSeeHtml('data-inbox-panel="chats"')
            ->assertDontSeeHtml('inboxTab')
            ->assertNotDispatched('rt:inbox-increased');
    }

    public function test_chat_polling_preserves_notification_identity_and_chat_id_without_a_chat_badge(): void
    {
        $chat = $this->chat();
        $component = Livewire::actingAs($this->recipient)->test(HeaderInbox::class)
            ->assertNotDispatched('rt:inbox-increased');
        $message = $this->chatMessage($chat);

        $component->call('loadInbox')
            ->assertSet('unreadChatMessagesCount', 1)
            ->assertViewHas('totalUnreadCount', 0)
            ->assertSeeHtml('wire:poll.60s="loadInbox"')
            ->assertDispatched('rt:inbox-increased', function ($event, array $payload) use ($message, $chat): bool {
                return $payload['source'] === 'chat' && count($payload['notifications']) === 1
                    && $payload['notifications'][0]['notification_id'] === 'chat-message:'.$message->id
                    && $payload['notifications'][0]['category'] === 'chat'
                    && $payload['notifications'][0]['chatId'] === $chat->id;
            });

        $component->call('loadInbox')->assertNotDispatched('rt:inbox-increased');
    }

    public function test_normal_and_chat_notifications_keep_the_combined_fallback_payload(): void
    {
        $chat = $this->chat();
        $component = Livewire::actingAs($this->recipient)->test(HeaderInbox::class);
        $normal = $this->message('Fresh normal fixture');
        $chatMessage = $this->chatMessage($chat);

        $component->call('loadInbox')
            ->assertViewHas('totalUnreadCount', 1)
            ->assertDispatched('rt:inbox-increased', function ($event, array $payload) use ($normal, $chatMessage): bool {
                return $payload['source'] === 'both'
                    && array_column($payload['notifications'], 'notification_id') === ['message:'.$normal->id, 'chat-message:'.$chatMessage->id]
                    && array_column($payload['notifications'], 'category') === ['messages', 'chat'];
            });
    }

    public function test_normal_message_polling_and_silent_refresh_still_work(): void
    {
        $component = Livewire::actingAs($this->recipient)->test(HeaderInbox::class);
        $this->message('Silent refresh fixture');
        $component->dispatch('inbox:refresh', notify: false)
            ->assertSet('unreadMessagesCount', 1)
            ->assertNotDispatched('rt:inbox-increased');
        $normal = $this->message('New message fixture');
        $component->call('loadInbox')
            ->assertViewHas('totalUnreadCount', 2)
            ->assertDispatched('rt:inbox-increased', function ($event, array $payload) use ($normal): bool {
                return $payload['source'] === 'inbox' && count($payload['notifications']) === 1
                    && $payload['notifications'][0]['notification_id'] === 'message:'.$normal->id;
            });
    }

    public function test_chat_fallback_preserves_membership_read_hidden_joined_and_cleared_boundaries(): void
    {
        $visible = $this->chat(['joined_at' => now()->subHour(), 'cleared_at' => now()->subMinutes(30), 'last_read_at' => now()->subMinutes(10)]);
        $expected = $this->chatMessage($visible, ['created_at' => now()]);
        $this->chatMessage($visible, ['user_id' => $this->recipient->id]);
        $this->chatMessage($visible, ['created_at' => now()->subMinutes(15)]);
        $this->chatMessage($visible, ['created_at' => now()->subHours(2)]);
        $this->chatMessage($visible, ['deleted_at' => now()]);
        $this->chatMessage($this->chat(['hidden_at' => now()]));
        $this->chatMessage($this->chat(['joined_at' => now()->addMinute()]));
        $this->chatMessage($this->chat(['cleared_at' => now()->addMinute()]));
        $this->chatMessage($this->chat([], 'call'));
        $foreign = Chat::create(['type' => 'direct', 'created_by' => $this->sender->id]);
        $this->chatMessage($foreign);

        Livewire::actingAs($this->recipient)->test(HeaderInbox::class)
            ->assertSet('unreadChatMessagesCount', 1)
            ->assertSet('latestUnreadChatMessageId', $expected->id)
            ->assertViewHas('totalUnreadCount', 0)
            ->assertNotDispatched('rt:inbox-increased');
    }

    public function test_authentication_loss_clears_both_fallback_counters_without_a_notification(): void
    {
        $this->message('Unread fixture');
        $this->chatMessage($this->chat());
        $this->actingAs($this->recipient);
        $inbox = new HeaderInbox;
        $inbox->loadInbox(notify: false);
        $this->assertSame(1, $inbox->unreadMessagesCount);
        $this->assertSame(1, $inbox->unreadChatMessagesCount);

        Auth::logout();
        $inbox->loadInbox();
        $this->assertSame(0, $inbox->unreadMessagesCount);
        $this->assertSame(0, $inbox->unreadChatMessagesCount);
        $this->assertSame(0, $inbox->latestUnreadMessageId);
        $this->assertSame(0, $inbox->latestUnreadChatMessageId);
        $this->assertCount(0, $inbox->receivedMessages);
    }

    private function message(string $subject, array $attributes = []): Message
    {
        return Message::create(array_replace([
            'subject' => $subject, 'message' => 'Synthetic normal message body',
            'from_user' => $this->sender->id, 'to_user' => $this->recipient->id, 'status' => 1,
        ], $attributes));
    }

    private function chat(array $pivot = [], string $type = 'direct'): Chat
    {
        $chat = Chat::create(['type' => $type, 'created_by' => $this->sender->id]);
        $chat->participants()->attach($this->recipient->id, $pivot);

        return $chat;
    }

    private function chatMessage(Chat $chat, array $attributes = []): ChatMessage
    {
        return ChatMessage::forceCreate(array_replace([
            'chat_id' => $chat->id, 'user_id' => $this->sender->id,
            'body' => 'Synthetic chat message', 'created_at' => now(),
        ], $attributes));
    }
}
