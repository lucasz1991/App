<?php

namespace App\Livewire;

use App\Events\ChatMessageSent;
use App\Events\ChatMessageReceived;
use App\Events\ChatMessageDeleted;
use App\Events\ChatRead;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class ChatBox extends Component
{
    use WithFileUploads;

    public ?int $selectedChatId = null;

    public string $messageText = '';

    public string $search = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /** Separater Uploadkanal fuer eine aufgenommene Sprachnachricht. */
    public $voiceUpload = null;

    /** Modal: neuer Chat / neue Gruppe */
    public bool $showNewChat = false;
    public string $newChatTab = 'direct'; // direct | group
    public string $groupName = '';
    public array $groupParticipants = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    /** Beim Einstieg den zuletzt geoeffneten, weiterhin erlaubten Chat anzeigen. */
    public function mount(): void
    {
        $lastChatId = auth()->user()->chats()
            ->orderByDesc('chat_user.last_opened_at')
            ->orderByDesc('chats.updated_at')
            ->value('chats.id');

        if ($lastChatId) {
            $this->openChat((int) $lastChatId);
        }
    }

    /** Chat des angemeldeten Nutzers laden oder 403. */
    protected function myChat(int $chatId): Chat
    {
        $chat = Chat::with('participants')->findOrFail($chatId);

        abort_unless($chat->participants->contains('id', auth()->id()), 403);

        return $chat;
    }

    public function openChat(int $chatId): void
    {
        $chat = $this->myChat($chatId);

        $this->selectedChatId = $chat->id;
        $this->messageText = '';

        $chat->participants()->updateExistingPivot(auth()->id(), ['last_opened_at' => now()]);
        $this->markChatRead($chat);
        $this->dispatch('chat:pane-open');
    }

    public function send(): void
    {
        $this->validate([
            'messageText' => ['nullable', 'string', 'max:5000'],
            'uploads' => ['array', 'max:5'],
            'uploads.*' => ['file', 'max:20480'],
        ]);

        if (trim($this->messageText) === '' && $this->uploads === []) {
            $this->addError('messageText', __('app.chat_message_or_attachment_required'));

            return;
        }

        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => auth()->id(),
            'body' => trim($this->messageText),
            'message_type' => $this->uploads === [] ? 'text' : 'attachment',
            'view_once' => false,
        ]);

        foreach ($this->uploads as $uploadedFile) {
            $this->storeMessageUpload($message, $uploadedFile);
        }

        $this->messageText = '';
        $this->uploads = [];
        $this->finishSending($chat, $message);
    }

    public function sendVoice(bool $viewOnce = false, int $durationSeconds = 0): void
    {
        $this->validate([
            'voiceUpload' => ['required', 'file', 'max:20480'],
        ]);

        if (! $this->selectedChatId) {
            return;
        }

        $clientMime = strtolower((string) $this->voiceUpload->getClientMimeType());
        $extension = strtolower((string) $this->voiceUpload->getClientOriginalExtension());
        abort_unless(
            str_starts_with($clientMime, 'audio/')
                || in_array($extension, ['webm', 'ogg', 'm4a', 'mp3', 'wav', 'aac'], true),
            422,
            __('app.voice_message_invalid')
        );

        $chat = $this->myChat($this->selectedChatId);
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => auth()->id(),
            'body' => '',
            'message_type' => 'voice',
            'view_once' => $viewOnce,
            'voice_duration_seconds' => $durationSeconds > 0 ? min(300, $durationSeconds) : null,
        ]);

        try {
            $this->storeMessageUpload($message, $this->voiceUpload, 'voice');
        } catch (\Throwable $exception) {
            $message->delete();

            throw $exception;
        }

        $this->voiceUpload = null;
        $this->finishSending($chat, $message);
        $this->dispatch('chat:voice-sent');
    }

    public function deleteMessage(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $message = $chat->messages()->with('files')->findOrFail($messageId);

        abort_unless((int) $message->user_id === (int) auth()->id(), 403);

        $message->files->each->delete();
        $message->delete();
        $chat->touch();

        $this->broadcastChatEvent(new ChatMessageDeleted($chat->id, $messageId));
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
    }

    public function requestVoicePlayback(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $message = $chat->messages()->with(['files', 'views'])->findOrFail($messageId);
        abort_unless($message->isVoice(), 422);

        $file = $message->voiceFile();
        abort_unless($file, 404);

        if (! $message->view_once) {
            $this->dispatch(
                'chat:voice-ready',
                messageId: $message->id,
                url: route('chat.attachments', ['file' => $file]),
                viewOnce: false,
            );

            return;
        }

        if ((int) $message->user_id === (int) auth()->id() || $message->hasBeenViewedBy(auth()->user())) {
            $this->dispatch('chat:voice-consumed', messageId: $message->id);

            return;
        }

        $view = $message->views()->firstOrCreate(
            ['user_id' => auth()->id()],
            ['viewed_at' => now()],
        );

        if (! $view->wasRecentlyCreated) {
            $this->dispatch('chat:voice-consumed', messageId: $message->id);

            return;
        }

        $token = Str::random(64);
        Cache::put(ChatMessage::voicePlaybackCacheKey($message->id, auth()->id()), $token, now()->addMinutes(10));

        $this->dispatch(
            'chat:voice-ready',
            messageId: $message->id,
            url: route('chat.attachments', ['file' => $file, 'voice_token' => $token]),
            viewOnce: true,
        );
    }

    public function finishVoicePlayback(int $messageId): void
    {
        Cache::forget(ChatMessage::voicePlaybackCacheKey($messageId, auth()->id()));
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
        $path = $uploadedFile->store('uploads/chat/' . $message->chat_id, 'private');
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
        $chat->touch();
        $chat->participants()->updateExistingPivot(auth()->id(), ['last_read_at' => now()]);

        $this->broadcastChatEvent(new ChatMessageSent($message));
        $this->broadcastChatEvent(new ChatMessageReceived($message));
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
    }

    #[On('chat:refresh')]
    public function refreshChat(int $chatId): void
    {
        if ($this->selectedChatId !== $chatId) {
            $this->dispatch('inbox:refresh');

            return;
        }

        $chat = $this->myChat($chatId);
        $this->markChatRead($chat);
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
    }

    public function startDirect(int $userId): void
    {
        $other = User::query()->where('status', true)->findOrFail($userId);

        abort_if($other->id === auth()->id(), 422);

        $chat = Chat::directBetween(auth()->user(), $other);

        $this->reset(['showNewChat', 'groupName', 'groupParticipants', 'search']);
        $this->openChat($chat->id);
    }

    public function createGroup(): void
    {
        $this->validate([
            'groupName' => ['required', 'string', 'max:80'],
            'groupParticipants' => ['required', 'array', 'min:1'],
        ]);

        $ids = User::query()
            ->where('status', true)
            ->whereIn('id', array_map('intval', $this->groupParticipants))
            ->where('id', '!=', auth()->id())
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->addError('groupParticipants', __('app.select_participants'));

            return;
        }

        $chat = Chat::create([
            'type' => 'group',
            'name' => trim($this->groupName),
            'created_by' => auth()->id(),
        ]);

        // Identische Pivot-Spalten je Zeile (sonst schlaegt der Bulk-Insert fehl)
        $chat->participants()->attach(
            $ids->mapWithKeys(fn ($id) => [$id => ['last_read_at' => null]])
                ->put(auth()->id(), ['last_read_at' => now()])
                ->all()
        );

        $this->reset(['showNewChat', 'groupName', 'groupParticipants', 'search']);
        $this->dispatch('swal:toast', type: 'success', text: __('app.group_created'));
        $this->openChat($chat->id);
    }

    /** Beim Polling: offenen Chat als gelesen markieren. */
    public function pollTick(): void
    {
        if ($this->selectedChatId) {
            $this->markChatRead($this->myChat($this->selectedChatId));
        }
    }

    protected function markChatRead(Chat $chat): void
    {
        $participant = $chat->participants->firstWhere('id', auth()->id());
        $lastReadAt = $participant?->pivot?->last_read_at;
        $latestOtherMessageAt = $chat->messages()
            ->where('user_id', '!=', auth()->id())
            ->max('created_at');

        if (! $latestOtherMessageAt || ($lastReadAt && Carbon::parse($lastReadAt)->gte(Carbon::parse($latestOtherMessageAt)))) {
            return;
        }

        $chat->participants()->updateExistingPivot(auth()->id(), ['last_read_at' => now()]);
        $this->broadcastChatEvent(new ChatRead($chat->id, auth()->id()));
    }

    protected function broadcastChatEvent(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $exception) {
            Log::notice('Chat-Echtzeitereignis konnte nicht gesendet werden.', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function render()
    {
        $me = auth()->user();

        $chats = $me->chats()
            ->with(['participants', 'latestMessage.sender', 'latestMessage.files'])
            ->orderByDesc('chats.updated_at')
            ->get();

        if (filled($this->search)) {
            $needle = mb_strtolower(trim($this->search));
            $chats = $chats->filter(
                fn (Chat $chat) => str_contains(mb_strtolower($chat->displayNameFor($me)), $needle)
            )->values();
        }

        $selectedChat = null;
        $messages = collect();

        if ($this->selectedChatId) {
            $selectedChat = $chats->firstWhere('id', $this->selectedChatId)
                ?? $me->chats()->with('participants')->find($this->selectedChatId);

            if ($selectedChat) {
                $messages = $selectedChat->messages()
                    ->with(['sender:id,name,profile_photo_path', 'files', 'views:id,chat_message_id,user_id,viewed_at'])
                    ->orderBy('id')
                    ->limit(200)
                    ->get();
            } else {
                $this->selectedChatId = null;
            }
        }

        // Kontakte fuer neuen Chat: alle aktiven Benutzer ausser mir
        $contacts = User::query()
            ->where('status', true)
            ->where('id', '!=', $me->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_photo_path']);

        return view('livewire.chat-box', [
            'chats' => $chats,
            'selectedChat' => $selectedChat,
            'messages' => $messages,
            'contacts' => $contacts,
        ])->layout('layouts.master', ['contentMode' => 'viewport']);
    }
}
