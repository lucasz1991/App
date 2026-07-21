<?php

namespace App\Livewire;

use App\Events\ChatMessageSent;
use App\Events\ChatMessageReceived;
use App\Events\ChatRead;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /** Modal: neuer Chat / neue Gruppe */
    public bool $showNewChat = false;
    public string $newChatTab = 'direct'; // direct | group
    public string $groupName = '';
    public array $groupParticipants = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

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

        $this->markChatRead($chat);
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
        ]);

        foreach ($this->uploads as $uploadedFile) {
            $path = $uploadedFile->store('uploads/chat/' . $chat->id, 'private');
            $detectedMime = Storage::disk('private')->mimeType($path);
            $mime = (! $detectedMime || $detectedMime === 'application/octet-stream')
                ? $uploadedFile->getClientMimeType()
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

        $chat->touch();
        $chat->participants()->updateExistingPivot(auth()->id(), ['last_read_at' => now()]);

        $this->messageText = '';
        $this->uploads = [];
        $this->broadcastChatEvent(new ChatMessageSent($message));
        $this->broadcastChatEvent(new ChatMessageReceived($message));
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
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
                    ->with(['sender:id,name,profile_photo_path', 'files'])
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
        ])->layout('layouts.master');
    }
}
