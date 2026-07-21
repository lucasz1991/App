<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Tools\FilePools\FilePreviewModal;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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

        Schema::create('chats', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('direct');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

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

    /** @return array{0: User, 1: User, 2: \App\Models\File} */
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
}
