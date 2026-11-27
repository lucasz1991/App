<?php

namespace App\Livewire\Concerns\Chat;

use App\Events\ChatMessageReceived;
use App\Events\ChatMessageSent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\ChatMessageInteractionService;
use App\Support\Push\PushDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Textnachrichten und Anhaenge: Eingabe pruefen, Nachricht anlegen, Dateien
 * im privaten Speicher ablegen und den Versand abschliessen (Ereignisse,
 * Push, Posteingang, Scrollen ans Ende).
 *
 * storeMessageUpload() und finishSending() nutzt auch der Sprachnachrichten-
 * Concern.
 */
trait SendsChatMessages
{
    public string $messageText = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function send(): void
    {
        $this->validate([
            'messageText' => ['nullable', 'string', 'max:5000'],
            'uploads' => ['array', 'max:5'],
            'uploads.*' => [
                'file',
                'max:20480',
                'mimetypes:audio/*,video/*,image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,text/plain,application/zip,application/x-zip-compressed',
            ],
        ]);

        if (trim($this->messageText) === '' && $this->uploads === []) {
            $this->addError('messageText', __('app.chat_message_or_attachment_required'));

            return;
        }

        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $replyTarget = app(ChatMessageInteractionService::class)
            ->replyTarget($chat, auth()->user(), $this->replyingToMessageId);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => auth()->id(),
            'reply_to_message_id' => $replyTarget?->id,
            'body' => trim($this->messageText),
            'message_type' => $this->uploads === [] ? 'text' : 'attachment',
            'view_once' => false,
        ]);

        try {
            foreach ($this->uploads as $uploadedFile) {
                $this->storeMessageUpload($message, $uploadedFile);
            }
        } catch (\Throwable $exception) {
            $message->files()->get()->each->delete();
            $message->forceDelete();

            throw $exception;
        }

        $this->messageText = '';
        $this->uploads = [];
        $this->replyingToMessageId = null;
        $this->finishSending($chat, $message);
    }

    public function removeUpload(int $index): void
    {
        if (! isset($this->uploads[$index])) {
            return;
        }

        $this->uploads[$index]->delete();
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    protected function storeMessageUpload(ChatMessage $message, mixed $uploadedFile, ?string $forcedType = null): void
    {
        $path = $uploadedFile->store('uploads/chat/'.$message->chat_id, 'private');
        $detectedMime = Storage::disk('private')->mimeType($path);
        $clientMime = strtolower((string) $uploadedFile->getClientMimeType());
        $isDeclaredMedia = str_starts_with($clientMime, 'audio/') || str_starts_with($clientMime, 'video/');
        $mime = ($isDeclaredMedia || ! $detectedMime || $detectedMime === 'application/octet-stream')
            ? $clientMime
            : $detectedMime;

        if ($forcedType === 'voice' && ! str_starts_with((string) $mime, 'audio/')) {
            $mime = match (strtolower((string) $uploadedFile->getClientOriginalExtension())) {
                'ogg' => 'audio/ogg',
                'm4a', 'mp4' => 'audio/mp4',
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'aac' => 'audio/aac',
                default => 'audio/webm',
            };
        }

        $message->files()->create([
            'name' => $uploadedFile->getClientOriginalName(),
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $mime,
            'type' => $forcedType ?? (str_starts_with((string) $mime, 'audio/')
                ? 'audio'
                : (str_starts_with((string) $mime, 'video/') ? 'video' : 'chat')),
            'size' => $uploadedFile->getSize(),
            'user_id' => auth()->id(),
        ]);
    }

    protected function finishSending(Chat $chat, ChatMessage $message): void
    {
        DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->whereNotNull('hidden_at')
            ->update([
                'hidden_at' => null,
                'updated_at' => now(),
            ]);

        $chat->touch();
        $chat->participants()->updateExistingPivot(auth()->id(), ['last_read_at' => now()]);

        $this->broadcastChatEvent(new ChatMessageSent($message));
        $this->broadcastChatEvent(new ChatMessageReceived($message));
        app(PushDelivery::class)->chatMessageReceived($message);
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
    }
}
