<?php

namespace App\Livewire\Calls;

use App\Events\ChatMessageReceived;
use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageReactionChanged;
use App\Events\ChatMessageSent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\File;
use App\Models\Room;
use App\Services\Chat\ChatMessageInteractionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class CallChat extends Component
{
    use WithFileUploads;

    private const PAGE_SIZE = 100;

    public Room $room;

    public bool $readOnly = false;

    public string $messageText = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    #[Locked]
    public ?int $replyToMessageId = null;

    #[Locked]
    public ?int $oldestLoadedId = null;

    public function mount(Room $room, bool $readOnly = false): void
    {
        $this->room = $room;
        $this->readOnly = $readOnly || ! $room->isActive();

        $chat = $this->authorizedChat();
        $this->initializeCursor($chat);
        $this->markRead($chat);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $chatId = (int) $this->room->call_chat_id;

        return [
            "echo-private:chat.{$chatId},.chat.message.sent" => 'refreshMessages',
            "echo-private:chat.{$chatId},.chat.message.deleted" => 'refreshMessages',
            "echo-private:chat.{$chatId},.chat.message.reaction" => 'refreshMessages',
        ];
    }

    public function refreshMessages(): void
    {
        $this->markRead($this->authorizedChat());
    }

    public function loadOlder(): void
    {
        $chat = $this->authorizedChat();
        $query = $this->visibleMessagesQuery($chat);

        $currentOldest = $this->oldestLoadedId
            ?? (clone $query)->orderByDesc('id')->limit(self::PAGE_SIZE)->pluck('id')->min();

        if (! $currentOldest) {
            return;
        }

        $previousIds = (clone $query)
            ->where('id', '<', $currentOldest)
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE)
            ->pluck('id');

        if ($previousIds->isNotEmpty()) {
            $this->oldestLoadedId = (int) $previousIds->min();
            $this->dispatch('call-chat:older-loaded');
        }
    }

    public function startReply(int $messageId): void
    {
        $message = $this->visibleMessagesQuery($this->authorizedChat())->findOrFail($messageId);
        $this->replyToMessageId = (int) $message->id;
        $this->dispatch('call-chat:focus-composer');
    }

    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
    }

    public function react(
        int $messageId,
        string $emoji,
        ChatMessageInteractionService $interactions,
    ): void {
        $this->assertWritable();
        $chat = $this->authorizedChat();
        $reaction = $interactions->toggleReaction($chat, auth()->user(), $messageId, $emoji);

        broadcast(new ChatMessageReactionChanged(
            (int) $chat->id,
            $messageId,
            (int) auth()->id(),
            $reaction?->emoji,
        ));
    }

    public function deleteMessage(
        int $messageId,
        ChatMessageInteractionService $interactions,
    ): void {
        $this->assertWritable();
        $chat = $this->authorizedChat();
        $interactions->tombstone($chat, auth()->user(), $messageId);

        if ($this->replyToMessageId === $messageId) {
            $this->replyToMessageId = null;
        }

        $chat->touch();
        broadcast(new ChatMessageDeleted((int) $chat->id, $messageId));
    }

    public function send(): void
    {
        $chat = $this->authorizedChat();
        $this->assertWritable();

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

        $replyTo = null;
        if ($this->replyToMessageId) {
            $replyTo = $this->visibleMessagesQuery($chat)->findOrFail($this->replyToMessageId);
        }

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => auth()->id(),
            'body' => trim($this->messageText),
            'message_type' => $this->uploads === [] ? 'text' : 'attachment',
            'view_once' => false,
            'reply_to_message_id' => $replyTo?->id,
        ]);

        try {
            foreach ($this->uploads as $uploadedFile) {
                $this->storeUpload($message, $uploadedFile);
            }
        } catch (\Throwable $exception) {
            $message->files->each(function (File $file): void {
                Storage::disk($file->disk ?: 'private')->delete($file->path);
                $file->delete();
            });
            $message->forceDelete();

            throw $exception;
        }

        $this->messageText = '';
        $this->uploads = [];
        $this->replyToMessageId = null;
        $chat->touch();
        $this->markRead($chat);

        broadcast(new ChatMessageSent($message));
        broadcast(new ChatMessageReceived($message));
        $this->dispatch('call-chat:scroll-bottom');
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

    protected function authorizedChat(): Chat
    {
        $chat = $this->room->callChat()->with('participants')->firstOrFail();
        $participant = $chat->participantFor(auth()->id());

        abort_unless($participant && $participant->pivot?->hidden_at === null, 403, __('app.calls_permission_denied'));

        return $chat;
    }

    protected function assertWritable(): void
    {
        $room = $this->room->fresh();
        $participant = $room?->participantFor(auth()->id());

        abort_unless(
            ! $this->readOnly
                && $room?->isActive()
                && $participant
                && ! $participant->isRemoved(),
            403,
            __('app.calls_chat_read_only'),
        );
    }

    protected function visibleMessagesQuery(Chat $chat): Builder
    {
        $visibleSince = $chat->visibleSinceFor(auth()->id());

        return ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->when($visibleSince, fn (Builder $query) => $query->where('created_at', '>=', $visibleSince));
    }

    protected function initializeCursor(Chat $chat): void
    {
        $ids = $this->visibleMessagesQuery($chat)
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE)
            ->pluck('id');

        $this->oldestLoadedId = $ids->isEmpty() ? null : (int) $ids->min();
    }

    protected function markRead(Chat $chat): void
    {
        $chat->participants()->updateExistingPivot(auth()->id(), [
            'last_read_at' => now(),
            'last_opened_at' => now(),
        ]);
    }

    protected function storeUpload(ChatMessage $message, TemporaryUploadedFile $uploadedFile): void
    {
        $path = $uploadedFile->store('uploads/chat/'.$message->chat_id, 'private');
        $detectedMime = Storage::disk('private')->mimeType($path);
        $clientMime = strtolower((string) $uploadedFile->getClientMimeType());
        $mime = (! $detectedMime || $detectedMime === 'application/octet-stream')
            ? $clientMime
            : $detectedMime;

        $message->files()->create([
            'name' => $uploadedFile->getClientOriginalName(),
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $mime,
            'type' => str_starts_with((string) $mime, 'audio/')
                ? 'audio'
                : (str_starts_with((string) $mime, 'video/') ? 'video' : 'chat'),
            'size' => $uploadedFile->getSize(),
            'user_id' => auth()->id(),
        ]);
    }

    public function render()
    {
        $chat = $this->authorizedChat();
        $query = $this->visibleMessagesQuery($chat);

        if ($this->oldestLoadedId) {
            $messages = (clone $query)
                ->where('id', '>=', $this->oldestLoadedId)
                ->with([
                    'sender:id,name,profile_photo_path',
                    'files',
                    'replyTo' => fn ($relation) => $relation->withTrashed()->with('sender:id,name'),
                    'reactions.user:id,name',
                ])
                ->orderBy('id')
                ->get();
        } else {
            $messages = (clone $query)
                ->with([
                    'sender:id,name,profile_photo_path',
                    'files',
                    'replyTo' => fn ($relation) => $relation->withTrashed()->with('sender:id,name'),
                    'reactions.user:id,name',
                ])
                ->orderByDesc('id')
                ->limit(self::PAGE_SIZE)
                ->get()
                ->reverse()
                ->values();
        }

        $replyingTo = $this->replyToMessageId
            ? $this->visibleMessagesQuery($chat)
                ->with(['sender:id,name', 'files'])
                ->find($this->replyToMessageId)
            : null;

        return view('livewire.calls.call-chat', [
            'messages' => $messages,
            'replyingTo' => $replyingTo,
            'hasOlder' => $this->oldestLoadedId
                ? (clone $query)->where('id', '<', $this->oldestLoadedId)->exists()
                : false,
        ]);
    }
}
