<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Tools\FilePools\FilePreviewModal;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatAttachmentPreviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();

        Storage::fake('private');
    }

    public function test_chat_image_is_inline_for_participants_and_forbidden_for_others(): void
    {
        [$participant, $outsider, $file] = $this->chatImageFixture();

        $this->actingAs($participant)
            ->get(route('chat.attachments', ['file' => $file]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->actingAs($outsider)
            ->get(route('chat.attachments', ['file' => $file]))
            ->assertForbidden();
    }

    public function test_global_file_preview_accepts_chat_participants_and_rejects_others(): void
    {
        [$participant, $outsider, $file] = $this->chatImageFixture();

        Livewire::actingAs($participant)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertSet('open', true)
            ->assertSet('fileId', $file->id);

        Livewire::actingAs($outsider)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertForbidden();
    }

    public function test_attachment_routes_and_preview_respect_joined_cleared_and_hidden_timestamps(): void
    {
        $sender = User::factory()->create();
        $participant = User::factory()->create();
        $chat = Chat::create(['type' => 'group', 'name' => 'Dienst', 'created_by' => $sender->id]);
        $joinedAt = Carbon::parse('2026-07-25 10:00:00');

        $chat->participants()->attach([
            $sender->id => ['joined_at' => Carbon::parse('2026-07-25 08:00:00')],
            $participant->id => ['joined_at' => $joinedAt],
        ]);

        $oldFile = $this->createChatImageAt($chat, $sender, Carbon::parse('2026-07-25 09:00:00'), 'alt.png');
        $visibleFile = $this->createChatImageAt($chat, $sender, Carbon::parse('2026-07-25 11:00:00'), 'sichtbar.png');

        $this->actingAs($participant)
            ->get(route('chat.attachments', ['file' => $oldFile]))
            ->assertForbidden();

        Livewire::actingAs($participant)
            ->test(FilePreviewModal::class)
            ->call('openWith', $oldFile->id)
            ->assertForbidden();

        $this->actingAs($participant)
            ->get(route('chat.attachments', ['file' => $visibleFile]))
            ->assertOk();

        Livewire::actingAs($participant)
            ->test(FilePreviewModal::class)
            ->call('openWith', $visibleFile->id)
            ->assertSet('open', true);

        DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $participant->id)
            ->update(['cleared_at' => Carbon::parse('2026-07-25 12:00:00')]);

        $this->actingAs($participant)
            ->get(route('chat.attachments', ['file' => $visibleFile]))
            ->assertForbidden();

        Livewire::actingAs($participant)
            ->test(FilePreviewModal::class)
            ->call('openWith', $visibleFile->id)
            ->assertForbidden();

        $newFile = $this->createChatImageAt($chat, $sender, Carbon::parse('2026-07-25 13:00:00'), 'neu.png');

        $this->actingAs($participant)
            ->get(route('chat.attachments', ['file' => $newFile]))
            ->assertOk();

        DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $participant->id)
            ->update(['hidden_at' => Carbon::parse('2026-07-25 14:00:00')]);

        $this->actingAs($participant)
            ->get(route('chat.attachments', ['file' => $newFile]))
            ->assertForbidden();

        Livewire::actingAs($participant)
            ->test(FilePreviewModal::class)
            ->call('openWith', $newFile->id)
            ->assertForbidden();
    }

    /** @return array{0: User, 1: User, 2: File} */
    protected function chatImageFixture(): array
    {
        $participant = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();

        $chat = Chat::create([
            'type' => 'direct',
            'created_by' => $participant->id,
        ]);
        $chat->participants()->attach([
            $participant->id => ['last_read_at' => now()],
            $recipient->id => ['last_read_at' => null],
        ]);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $participant->id,
            'body' => 'Bild',
        ]);

        $path = "uploads/chat/{$chat->id}/bild.png";
        Storage::disk('private')->put($path, 'fake-png-content');

        $file = $message->files()->create([
            'name' => 'bild.png',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => 16,
        ]);

        return [$participant, $outsider, $file];
    }

    protected function createChatImageAt(Chat $chat, User $sender, Carbon $createdAt, string $name): File
    {
        $this->travelTo($createdAt);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => $name,
        ]);
        $path = "uploads/chat/{$chat->id}/{$name}";
        Storage::disk('private')->put($path, 'fake-png-content');

        return $message->files()->create([
            'name' => $name,
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => 16,
        ]);
    }
}
