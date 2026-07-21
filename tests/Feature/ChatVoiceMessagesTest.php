<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatVoiceMessagesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();

        Schema::create('chats', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('direct');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Storage::fake('private');
        Cache::flush();
    }

    public function test_recording_is_sent_as_voice_message_without_text_or_attachment_type(): void
    {
        [$sender, , $chat] = $this->directChatFixture();

        Livewire::actingAs($sender)
            ->test(ChatBox::class)
            ->set('voiceUpload', UploadedFile::fake()->create('aufnahme.webm', 128, 'audio/webm'))
            ->call('sendVoice', true)
            ->assertHasNoErrors();

        $message = ChatMessage::query()->where('chat_id', $chat->id)->sole();

        $this->assertSame('', $message->body);
        $this->assertSame('voice', $message->message_type);
        $this->assertTrue($message->view_once);
        $this->assertSame('voice', $message->files()->sole()->type);
    }

    public function test_sender_can_delete_own_message_and_its_private_file_but_not_another_users_message(): void
    {
        [$sender, $recipient, $chat] = $this->directChatFixture();
        $own = $this->voiceMessage($chat, $sender);
        $foreign = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Bleibt bestehen',
            'message_type' => 'text',
        ]);
        $ownPath = $own->files()->sole()->path;

        Livewire::actingAs($sender)
            ->test(ChatBox::class)
            ->call('deleteMessage', $own->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('chat_messages', ['id' => $own->id]);
        Storage::disk('private')->assertMissing($ownPath);

        Livewire::actingAs($sender)
            ->test(ChatBox::class)
            ->call('deleteMessage', $foreign->id)
            ->assertForbidden();

        $this->assertDatabaseHas('chat_messages', ['id' => $foreign->id]);
    }

    public function test_view_once_voice_requires_the_single_server_issued_playback_token(): void
    {
        [$sender, $recipient, $chat] = $this->directChatFixture();
        $message = $this->voiceMessage($chat, $sender, true);
        $file = $message->files()->sole();

        $this->actingAs($recipient)
            ->get(route('chat.attachments', ['file' => $file]))
            ->assertGone();

        Livewire::actingAs($recipient)
            ->test(ChatBox::class)
            ->call('requestVoicePlayback', $message->id)
            ->assertDispatched('chat:voice-ready');

        $this->assertDatabaseHas('chat_message_views', [
            'chat_message_id' => $message->id,
            'user_id' => $recipient->id,
        ]);

        $token = Cache::get(ChatMessage::voicePlaybackCacheKey($message->id, $recipient->id));
        $this->assertIsString($token);

        $this->actingAs($recipient)
            ->get(route('chat.attachments', ['file' => $file, 'voice_token' => $token]))
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=0, no-store, private');

        Livewire::actingAs($recipient)
            ->test(ChatBox::class)
            ->call('finishVoicePlayback', $message->id);

        $this->actingAs($recipient)
            ->get(route('chat.attachments', ['file' => $file, 'voice_token' => $token]))
            ->assertGone();

        Livewire::actingAs($recipient)
            ->test(ChatBox::class)
            ->call('requestVoicePlayback', $message->id)
            ->assertDispatched('chat:voice-consumed');
    }

    /** @return array{0: User, 1: User, 2: Chat} */
    protected function directChatFixture(): array
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([
            $sender->id => ['last_read_at' => now(), 'last_opened_at' => now()],
            $recipient->id => ['last_read_at' => null, 'last_opened_at' => null],
        ]);

        return [$sender, $recipient, $chat];
    }

    protected function voiceMessage(Chat $chat, User $sender, bool $viewOnce = false): ChatMessage
    {
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => '',
            'message_type' => 'voice',
            'view_once' => $viewOnce,
        ]);
        $path = "uploads/chat/{$chat->id}/voice-{$message->id}.webm";
        Storage::disk('private')->put($path, 'voice-content');
        $message->files()->create([
            'name' => 'Sprachnachricht.webm',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'audio/webm',
            'type' => 'voice',
            'size' => 13,
            'user_id' => $sender->id,
        ]);

        return $message;
    }
}
