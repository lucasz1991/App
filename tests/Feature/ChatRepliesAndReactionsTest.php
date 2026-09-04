<?php

namespace Tests\Feature;

use App\Events\ChatMessageReactionChanged;
use App\Http\Middleware\LogActivity;
use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageReaction;
use App\Models\User;
use App\Services\Chat\ChatMessageInteractionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatRepliesAndReactionsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        Storage::fake('private');
    }

    public function test_chat_header_uses_recent_activity_for_actual_presence(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();

        activity()
            ->causedBy($recipient)
            ->event('presence')
            ->log('Chat presence test');

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->assertSeeHtml('data-chat-presence="online"');
    }

    public function test_reply_is_bound_to_a_visible_message_in_the_same_chat(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $original = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Bitte Gleis 4 prüfen',
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->call('beginReply', $original->id)
            ->assertSet('replyingToMessageId', $original->id)
            ->set('messageText', 'Wird erledigt.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('replyingToMessageId', null);

        $reply = ChatMessage::query()->latest('id')->firstOrFail();

        $this->assertSame($original->id, (int) $reply->reply_to_message_id);
        $this->assertSame('Wird erledigt.', $reply->body);

        $outsider = User::factory()->create();

        Livewire::actingAs($outsider)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->assertForbidden();
    }

    public function test_cleared_history_cannot_be_used_as_a_reply_or_reaction_target(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $this->travelTo(Carbon::parse('2026-08-01 08:00:00'));
        $hiddenMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Nicht mehr sichtbar',
        ]);
        $chat->participants()->updateExistingPivot($sender->id, ['cleared_at' => now()->addHour()]);

        foreach ([
            'beginReply' => [],
            'toggleReaction' => ['👍'],
            'setReaction' => ['👍'],
            'removeReaction' => [],
        ] as $method => $arguments) {
            try {
                $component = Livewire::actingAs($sender)
                    ->test(ChatBox::class, ['selectedChatId' => $chat->id]);

                $component->call($method, $hiddenMessage->id, ...$arguments);

                $this->fail("{$method} accepted a message outside the visible history.");
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(ChatMessage::class, $exception->getModel());
            }
        }
    }

    public function test_reaction_toggles_replaces_and_rejects_non_allowlisted_emoji(): void
    {
        Event::fake([ChatMessageReactionChanged::class]);
        [$sender, $recipient, $chat] = $this->directChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Abfahrt bestätigt',
        ]);
        $component = Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id]);

        $component->call('toggleReaction', $message->id, '👍');
        $this->assertDatabaseHas('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
            'emoji' => '👍',
        ]);

        $component->call('toggleReaction', $message->id, '❤️');
        $this->assertSame(1, ChatMessageReaction::query()->where('chat_message_id', $message->id)->count());
        $this->assertDatabaseHas('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
            'emoji' => '❤️',
        ]);

        $component->call('toggleReaction', $message->id, '❤️');
        $this->assertDatabaseMissing('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->call('toggleReaction', $message->id, '🚂')
            ->assertStatus(422);

        $this->assertSame(
            ['👍', '❤️', '😂', '😮', '😢', '🙏'],
            ChatMessageInteractionService::QUICK_REACTIONS,
        );
        $this->assertSame([
            '😀', '😃', '😄', '😁', '😊', '😍', '🥰', '😘', '😎', '🤔',
            '🙄', '😬', '😢', '😭', '😡', '🤯', '😴', '👏', '🙌', '👍',
            '👎', '❤️', '💔', '🔥', '🎉', '✅', '👀', '🙏', '💯',
        ], ChatMessageInteractionService::ALLOWED_REACTIONS);
        Event::assertDispatchedTimes(ChatMessageReactionChanged::class, 3);
    }

    public function test_sender_cannot_react_to_their_own_message(): void
    {
        [$sender, , $chat] = $this->directChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Eigene Nachricht',
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->call('toggleReaction', $message->id, '👍')
            ->assertForbidden();

        $this->assertDatabaseMissing('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
        ]);
    }

    public function test_setting_a_reaction_is_idempotent_and_removal_is_explicit(): void
    {
        Event::fake([ChatMessageReactionChanged::class]);
        [$sender, $recipient, $chat] = $this->directChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Bitte eindeutig reagieren',
        ]);
        $component = Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id]);

        $component
            ->call('setReaction', $message->id, '👍')
            ->call('setReaction', $message->id, '👍');

        $this->assertDatabaseCount('chat_message_reactions', 1);
        $this->assertDatabaseHas('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
            'emoji' => '👍',
        ]);

        $component->call('setReaction', $message->id, '❤️');
        $this->assertDatabaseCount('chat_message_reactions', 1);
        $this->assertDatabaseHas('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
            'emoji' => '❤️',
        ]);

        $component->call('removeReaction', $message->id);
        $this->assertDatabaseMissing('chat_message_reactions', [
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
        ]);
        Event::assertDispatchedTimes(ChatMessageReactionChanged::class, 4);
    }

    public function test_reaction_users_are_eager_loaded_for_the_polled_transcript(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Wer hat reagiert?',
        ]);
        ChatMessageReaction::create([
            'chat_message_id' => $message->id,
            'user_id' => $sender->id,
            'emoji' => ChatMessageInteractionService::QUICK_REACTIONS[0],
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->assertViewHas('messages', function ($messages) use ($message): bool {
                $renderedMessage = $messages->firstWhere('id', $message->id);
                $reaction = $renderedMessage?->reactions->first();

                return $reaction?->relationLoaded('user') === true
                    && $reaction->user?->name !== null;
            });
    }

    public function test_delete_creates_content_free_tombstone_and_keeps_reply_context_safe(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $original = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Vertraulicher Inhalt',
        ]);
        $reply = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'reply_to_message_id' => $original->id,
            'body' => 'Verstanden',
        ]);
        $path = "uploads/chat/{$chat->id}/plan.pdf";
        Storage::disk('private')->put($path, 'content');
        $original->files()->create([
            'name' => 'plan.pdf',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'application/pdf',
            'type' => 'chat',
            'size' => 7,
            'user_id' => $sender->id,
        ]);
        ChatMessageReaction::create([
            'chat_message_id' => $original->id,
            'user_id' => $recipient->id,
            'emoji' => '👍',
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->call('deleteMessage', $original->id);

        $deleted = ChatMessage::withTrashed()->findOrFail($original->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame('', $deleted->body);
        $this->assertSame('deleted', $deleted->message_type);
        $this->assertSame(__('app.chat_original_message_unavailable'), $deleted->replyPreviewText());
        $this->assertDatabaseMissing('files', ['fileable_id' => $original->id, 'fileable_type' => ChatMessage::class]);
        $this->assertDatabaseMissing('chat_message_reactions', ['chat_message_id' => $original->id]);
        Storage::disk('private')->assertMissing($path);
        $this->assertSame($original->id, (int) $reply->fresh()->reply_to_message_id);
        $this->assertTrue($reply->fresh()->replyTo->trashed());

        try {
            app(ChatMessageInteractionService::class)->setReaction(
                $chat,
                $recipient,
                $original->id,
                ChatMessageInteractionService::QUICK_REACTIONS[0],
            );
            $this->fail('A tombstoned message accepted a new reaction.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(ChatMessage::class, $exception->getModel());
        }
    }

    public function test_view_once_reply_preview_never_contains_message_body(): void
    {
        $message = new ChatMessage([
            'body' => 'Darf niemals im Zitat stehen',
            'message_type' => 'voice',
            'view_once' => true,
        ]);

        $this->assertSame(__('app.chat_view_once_voice_message'), $message->replyPreviewText());
        $this->assertStringNotContainsString('niemals', $message->replyPreviewText());
    }

    public function test_history_starts_with_latest_hundred_and_loads_older_by_id_cursor(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();

        foreach (range(1, 120) as $number) {
            ChatMessage::create([
                'chat_id' => $chat->id,
                'user_id' => $recipient->id,
                'body' => "Nachricht {$number}",
            ]);
        }

        $component = Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->assertSet('oldestLoadedMessageId', 21)
            ->assertViewHas('messages', fn ($messages): bool => $messages->count() === 100
                && $messages->first()->id === 21
                && $messages->last()->id === 120)
            ->assertViewHas('hasOlderMessages', true)
            ->call('loadOlderMessages')
            ->assertSet('oldestLoadedMessageId', 1)
            ->assertViewHas('messages', fn ($messages): bool => $messages->count() === 120
                && $messages->first()->id === 1
                && $messages->last()->id === 120)
            ->assertViewHas('hasOlderMessages', false);

        $component->assertDispatched('chat:older-loaded');
    }

    public function test_export_contains_safe_reply_metadata_and_grouped_reactions(): void
    {
        [$sender, $recipient, $chat] = $this->directChat();
        $viewOnce = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Geheimer Einmalinhalt',
            'message_type' => 'voice',
            'view_once' => true,
        ]);
        $reply = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'reply_to_message_id' => $viewOnce->id,
            'body' => 'Sicher beantwortet',
        ]);
        ChatMessageReaction::create([
            'chat_message_id' => $reply->id,
            'user_id' => $recipient->id,
            'emoji' => '✅',
        ]);

        $content = $this->actingAs($recipient)
            ->get(route('chat.export', $chat))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(__('app.chat_view_once_voice_message'), $content);
        $this->assertStringContainsString('✅ ×1', $content);
        $this->assertStringNotContainsString('Geheimer Einmalinhalt', $content);
    }

    public function test_attachment_allowlist_is_enforced_on_the_server(): void
    {
        [$sender, , $chat] = $this->directChat();

        Livewire::actingAs($sender)
            ->test(ChatBox::class, ['selectedChatId' => $chat->id])
            ->set('uploads', [UploadedFile::fake()->create('payload.php', 2, 'application/x-php')])
            ->call('send')
            ->assertHasErrors(['uploads.0']);

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_call_chats_are_not_exposed_in_the_normal_chat_box(): void
    {
        [$sender, $recipient, $normalChat] = $this->directChat();
        $callChat = Chat::create([
            'type' => 'call',
            'name' => 'Interner Call-Chat',
            'created_by' => $sender->id,
        ]);
        $callChat->participants()->attach([
            $sender->id => ['joined_at' => now()->subMinute(), 'last_opened_at' => now()],
            $recipient->id => ['joined_at' => now()->subMinute(), 'last_opened_at' => now()],
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $normalChat->id)
            ->assertDontSee('Interner Call-Chat');

        try {
            Livewire::actingAs($sender)
                ->test(ChatBox::class, ['selectedChatId' => $callChat->id]);
            $this->fail('A call chat was opened in the normal ChatBox.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Chat::class, $exception->getModel());
        }
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
        $joinedAt = now()->subDay();
        $chat->participants()->attach([
            $sender->id => [
                'last_read_at' => $joinedAt,
                'last_opened_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
            $recipient->id => [
                'last_read_at' => null,
                'last_opened_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
        ]);

        return [$sender, $recipient, $chat];
    }
}
