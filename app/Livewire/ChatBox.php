<?php

namespace App\Livewire;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageReceived;
use App\Events\ChatMessageSent;
use App\Events\ChatRead;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageView;
use App\Models\File;
use App\Models\User;
use App\Services\Calls\CallInfrastructureUnavailable;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\RoomLifecycleService;
use App\Support\Push\PushDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithoutUrlPagination;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ChatBox extends Component
{
    use WithFileUploads;
    use WithoutUrlPagination;
    use WithPagination;

    private const MEMBER_PICKER_PER_PAGE = 6;

    #[Url(as: 'chat')]
    public ?int $selectedChatId = null;

    public string $messageText = '';

    public string $search = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    /** Separater Uploadkanal fuer eine aufgenommene Sprachnachricht. */
    public $voiceUpload = null;

    /** Modal: neuer Chat / neue Gruppe */
    public bool $showNewChat = false;

    public string $newChatTab = 'direct'; // direct | group

    public string $groupName = '';

    public array $groupParticipants = [];

    public string $directContactSearch = '';

    public string $groupParticipantSearch = '';

    public bool $showDeleteMessageModal = false;

    #[Locked]
    public ?int $pendingDeleteMessageId = null;

    public bool $showDeleteChatModal = false;

    #[Locked]
    public ?int $pendingDeleteChatId = null;

    public bool $showGroupSettings = false;

    public string $groupEditName = '';

    /** Gruppenbild-Upload in den Gruppeneinstellungen. */
    public $groupPhotoUpload = null;

    /** @var array<int, int|string> */
    public array $groupMemberIds = [];

    public string $groupMemberSearch = '';

    /**
     * Letzte fremde Nachricht des offenen Chats. Der Ausgangswert wird beim
     * Oeffnen gesetzt, damit alte Nachrichten keinen Ton ausloesen. So kann
     * pollTick ohne Reverb neue Eingaenge erkennen, bevor er sie als gelesen
     * markiert.
     */
    public int $latestIncomingMessageId = 0;

    protected $listeners = ['refreshComponent' => '$refresh'];

    /** Beim Einstieg den zuletzt geoeffneten, weiterhin erlaubten Chat anzeigen. */
    public function mount(): void
    {
        if ($this->selectedChatId) {
            $this->openChat($this->selectedChatId);

            return;
        }

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
        $participant = $chat->participantFor(auth()->id());

        abort_unless($participant && ! $participant->pivot?->hidden_at, 403);

        return $chat;
    }

    protected function visibleMessagesFor(Chat $chat)
    {
        $visibleSince = $chat->visibleSinceFor(auth()->id());

        return $chat->messages()
            ->when($visibleSince, fn ($query) => $query->where('created_at', '>=', $visibleSince));
    }

    public function openChat(int $chatId): void
    {
        $chat = $this->myChat($chatId);

        $this->selectedChatId = $chat->id;
        $this->messageText = '';
        $this->latestIncomingMessageId = $this->latestIncomingMessageIdFor($chat);

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

    public function sendVoice(bool $viewOnce = false, int $durationSeconds = 0, array $waveform = []): void
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

        // Pegelwerte der Aufnahme (Client-Eingabe): strikt auf ganze Zahlen
        // 0-100 und eine feste Hoechstzahl begrenzen — mehr braucht keine
        // ehrliche Wellenform, und mehr darf ein Client hier nicht ablegen.
        $waveform = array_values(array_slice(array_map(
            static fn ($value) => max(0, min(100, (int) $value)),
            array_filter($waveform, 'is_numeric'),
        ), 0, 64));

        $chat = $this->myChat($this->selectedChatId);
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => auth()->id(),
            'body' => '',
            'message_type' => 'voice',
            'view_once' => $viewOnce,
            'voice_duration_seconds' => $durationSeconds > 0 ? min(300, $durationSeconds) : null,
            'voice_waveform' => $waveform !== [] ? $waveform : null,
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
        $message = $this->visibleMessagesFor($chat)->with('files')->findOrFail($messageId);

        abort_unless((int) $message->user_id === (int) auth()->id(), 403);

        $message->files->each->delete();
        $message->views()->delete();
        $message->delete();
        $chat->touch();

        $this->broadcastChatEvent(new ChatMessageDeleted($chat->id, $messageId));
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
    }

    public function requestDeleteMessage(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $message = $this->visibleMessagesFor($chat)->findOrFail($messageId);

        abort_unless((int) $message->user_id === (int) auth()->id(), 403);

        $this->pendingDeleteMessageId = $message->id;
        $this->showDeleteMessageModal = true;
    }

    public function confirmDeleteMessage(): void
    {
        if (! $this->pendingDeleteMessageId) {
            $this->showDeleteMessageModal = false;

            return;
        }

        $messageId = $this->pendingDeleteMessageId;
        $this->showDeleteMessageModal = false;
        $this->pendingDeleteMessageId = null;

        $this->deleteMessage($messageId);
    }

    public function requestVoicePlayback(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $message = $this->visibleMessagesFor($chat)->with(['files', 'views'])->findOrFail($messageId);
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

    #[On('chat:refresh')]
    public function refreshChat(int $chatId): void
    {
        if ($this->selectedChatId !== $chatId) {
            $this->dispatch('inbox:refresh');

            return;
        }

        $chat = auth()->user()->chats()->with('participants')->find($chatId);

        if (! $chat) {
            $this->resetSelectedChat();

            return;
        }

        $this->latestIncomingMessageId = $this->latestIncomingMessageIdFor($chat);
        $this->markChatRead($chat);
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:scroll-bottom');
    }

    /**
     * Anruf im ausgewaehlten Chat starten und alle Teilnehmer anklingeln.
     *
     * @param bool $video false = reiner Sprachanruf. Der Unterschied liegt
     *   allein darin, ob die Kamera beim Beitritt aktiviert wird; Raum,
     *   Klingeln und Moderation sind identisch. Jeder kann die Kamera im
     *   Gespraech jederzeit zuschalten.
     *
     * Die Dienste werden bewusst ueber app() geholt statt per Methoden-
     * Injection: sonst muesste der bool-Parameter hinter den Klassen stehen
     * und liesse sich aus dem View nicht mehr sauber uebergeben.
     */
    public function startCall(bool $video = true)
    {
        $lifecycle = app(RoomLifecycleService::class);
        $invitations = app(CallInvitationService::class);

        abort_unless(auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.start'), 403);

        $chat = $this->myChat((int) $this->selectedChatId);

        // Pruefen und Anlegen unter einer Sperre auf der Chat-Zeile: ohne sie
        // erzeugen zwei gleichzeitige Klicks zwei konkurrierende Raeume, und
        // die beiden Haelften des Chats klingeln in getrennten Anrufen.
        try {
            $result = DB::transaction(function () use ($chat, $lifecycle, $video) {
                Chat::whereKey($chat->id)->lockForUpdate()->first();

                $existing = $chat->activeRoom()->first();

                if ($existing) {
                    return ['room' => $existing, 'created' => false];
                }

                return ['room' => $lifecycle->createForChat($chat, auth()->user(), $video), 'created' => true];
            });
        } catch (CallInfrastructureUnavailable) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        // Laeuft bereits ein Anruf, direkt beitreten statt doppelt zu starten.
        if (! $result['created']) {
            return redirect()->route('calls.window', $result['room']);
        }

        $invitations->invite(
            $result['room'],
            auth()->user(),
            $chat->participants()->where('users.id', '!=', auth()->id())->where('users.status', true)->get(),
        );

        return redirect()->route('calls.window', $result['room']);
    }

    public function startDirect(int $userId): void
    {
        $other = User::query()->where('status', true)->findOrFail($userId);

        abort_if($other->id === auth()->id(), 422);

        $chat = Chat::directBetween(auth()->user(), $other);

        $this->reset([
            'showNewChat',
            'showGroupSettings',
            'groupName',
            'groupParticipants',
            'directContactSearch',
            'groupParticipantSearch',
            'groupMemberSearch',
            'search',
        ]);
        $this->resetMemberPickerPages();
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

        $joinedAt = now();
        $chat = Chat::create([
            'type' => 'group',
            'name' => trim($this->groupName),
            'created_by' => auth()->id(),
        ]);

        // Identische Pivot-Spalten je Zeile (sonst schlaegt der Bulk-Insert fehl)
        $chat->participants()->attach(
            $ids->mapWithKeys(fn ($id) => [$id => [
                'last_read_at' => null,
                'last_opened_at' => null,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ]])
                ->put(auth()->id(), [
                    'last_read_at' => $joinedAt,
                    'last_opened_at' => $joinedAt,
                    'joined_at' => $joinedAt,
                    'hidden_at' => null,
                    'cleared_at' => null,
                ])
                ->all()
        );

        $this->reset([
            'showNewChat',
            'groupName',
            'groupParticipants',
            'directContactSearch',
            'groupParticipantSearch',
            'search',
        ]);
        $this->resetMemberPickerPages();
        $this->dispatch('swal:toast', type: 'success', text: __('app.group_created'));
        $this->openChat($chat->id);
    }

    public function updatedShowNewChat(bool $isOpen): void
    {
        if (! $isOpen) {
            return;
        }

        $this->reset(['directContactSearch', 'groupParticipantSearch']);
        $this->resetMemberPickerPages();
    }

    public function updatedNewChatTab(string $tab): void
    {
        if (! in_array($tab, ['direct', 'group'], true)) {
            $this->newChatTab = 'direct';
        }

        $this->resetMemberPickerPages();
    }

    public function updatingDirectContactSearch(): void
    {
        $this->resetPage('directContactsPage');
    }

    public function updatingGroupParticipantSearch(): void
    {
        $this->resetPage('groupParticipantsPage');
    }

    public function updatingGroupMemberSearch(): void
    {
        $this->resetPage('groupMembersPage');
    }

    public function openGroupSettings(): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        abort_unless($chat->canManageGroup(auth()->id()), 403);

        $this->resetValidation(['groupEditName', 'groupMemberIds', 'groupPhotoUpload']);
        $this->groupEditName = (string) $chat->name;

        if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
            $this->groupPhotoUpload->delete();
        }
        $this->groupPhotoUpload = null;
        $this->groupMemberIds = $chat->participants
            ->where('id', '!=', auth()->id())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $this->groupMemberSearch = '';
        $this->resetPage('groupMembersPage');
        $this->showGroupSettings = true;
    }

    public function saveGroupSettings(): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $this->validate([
            'groupEditName' => ['required', 'string', 'max:80'],
            'groupMemberIds' => ['required', 'array', 'min:1'],
            'groupMemberIds.*' => ['integer', 'distinct'],
            'groupPhotoUpload' => ['nullable', 'image', 'max:5120'],
        ]);

        $chat = $this->myChat($this->selectedChatId);
        abort_unless($chat->canManageGroup(auth()->id()), 403);

        $requestedIds = collect($this->groupMemberIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id !== (int) auth()->id())
            ->unique()
            ->values();
        $currentIds = $chat->participants->pluck('id')->map(fn ($id): int => (int) $id);
        $validMemberIds = User::query()
            ->whereIn('id', $requestedIds)
            ->where(function ($query) use ($currentIds): void {
                $query->where('status', true)
                    ->orWhereIn('id', $currentIds);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($validMemberIds->isEmpty()) {
            $this->addError('groupMemberIds', __('app.select_participants'));

            return;
        }

        DB::transaction(function () use ($chat, $validMemberIds): void {
            $lockedChat = Chat::query()->lockForUpdate()->findOrFail($chat->id);
            $lockedChat->load('participants');
            abort_unless($lockedChat->canManageGroup(auth()->id()), 403);

            $existingParticipants = $lockedChat->participants->keyBy(
                fn (User $participant): int => (int) $participant->id
            );
            $desiredIds = $validMemberIds
                ->push((int) auth()->id())
                ->unique()
                ->values();
            $pivotRows = [];

            foreach ($desiredIds as $userId) {
                $existing = $existingParticipants->get((int) $userId);
                $pivotRows[(int) $userId] = [
                    'last_read_at' => $existing?->pivot?->last_read_at,
                    'last_opened_at' => $existing?->pivot?->last_opened_at,
                    'joined_at' => $existing?->pivot?->joined_at
                        ?: $existing?->pivot?->created_at
                        ?: now(),
                    'hidden_at' => null,
                    'cleared_at' => $existing?->pivot?->cleared_at,
                ];
            }

            $lockedChat->participants()->sync($pivotRows);

            $changes = ['name' => trim($this->groupEditName)];

            if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
                $newPath = $this->groupPhotoUpload->store('chat-photos', 'public');

                // Altes Bild erst NACH erfolgreichem Ablegen des neuen
                // entfernen — schlaegt der Upload fehl, bleibt das alte stehen.
                if ($newPath) {
                    if ($lockedChat->photo_path) {
                        Storage::disk('public')->delete($lockedChat->photo_path);
                    }

                    $changes['photo_path'] = $newPath;
                }
            }

            $lockedChat->forceFill($changes)->save();
        });

        if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
            $this->groupPhotoUpload->delete();
        }
        $this->groupPhotoUpload = null;

        $this->groupMemberIds = $validMemberIds->all();
        $this->groupMemberSearch = '';
        $this->resetPage('groupMembersPage');
        $this->showGroupSettings = false;
        $this->dispatch('swal:toast', type: 'success', text: __('app.group_updated'));
        $this->dispatch('inbox:refresh');
    }

    protected function resetMemberPickerPages(): void
    {
        $this->resetPage('directContactsPage');
        $this->resetPage('groupParticipantsPage');
        $this->resetPage('groupMembersPage');
    }

    protected function contactPickerQuery(string $search = '')
    {
        $needle = trim($search);

        return User::query()
            ->with(['profile', 'currentTeam'])
            ->where('status', true)
            ->where('id', '!=', auth()->id())
            ->when($needle !== '', function ($query) use ($needle): void {
                $query->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', '%' . $needle . '%')
                        ->orWhere('email', 'like', '%' . $needle . '%');
                });
            })
            ->orderBy('name')
            ->orderBy('id');
    }

    public function requestDeleteChat(?int $chatId = null): void
    {
        $targetChatId = $chatId ?? $this->selectedChatId;

        if (! $targetChatId) {
            return;
        }

        $chat = $this->myChat($targetChatId);
        $this->pendingDeleteChatId = $chat->id;
        $this->showDeleteChatModal = true;
    }

    public function confirmDeleteChat(): void
    {
        if (! $this->pendingDeleteChatId) {
            $this->showDeleteChatModal = false;

            return;
        }

        $chat = $this->myChat($this->pendingDeleteChatId);
        $me = auth()->user();
        $toastKey = 'chat_deleted';

        if ($chat->isGroup() && $chat->canManageGroup($me)) {
            $messageIds = $chat->messages()->pluck('id');
            $fileIds = File::query()
                ->where('fileable_type', ChatMessage::class)
                ->whereIn('fileable_id', $messageIds)
                ->pluck('id');

            DB::transaction(function () use ($chat, $messageIds): void {
                ChatMessageView::query()->whereIn('chat_message_id', $messageIds)->delete();
                ChatMessage::query()->whereIn('id', $messageIds)->delete();
                $chat->participants()->detach();
                $chat->delete();
            });

            File::query()->whereIn('id', $fileIds)->get()->each->delete();
            $toastKey = 'group_deleted';
        } elseif ($chat->isGroup()) {
            $chat->participants()->detach($me->id);
            $chat->touch();
            $toastKey = 'group_left';
        } else {
            $timestamp = now();
            $chat->participants()->updateExistingPivot($me->id, [
                'last_read_at' => $timestamp,
                'last_opened_at' => null,
                'hidden_at' => $timestamp,
                'cleared_at' => $timestamp,
            ]);
        }

        $deletedChatWasSelected = (int) $this->selectedChatId === (int) $chat->id;

        $this->showDeleteChatModal = false;
        $this->pendingDeleteChatId = null;

        if ($deletedChatWasSelected) {
            $this->resetSelectedChat();
        }

        $this->dispatch('swal:toast', type: 'success', text: __($toastKey === 'chat_deleted' ? 'app.chat_deleted' : 'app.'.$toastKey));
        $this->dispatch('inbox:refresh');
    }

    /**
     * Den sichtbar offenen Chat im Polling-Fallback aktualisieren und danach
     * als gelesen markieren. Der Verlauf selbst ist hier bereits die
     * Benachrichtigung; Ton und Suite-Alert bleiben deshalb bewusst aus.
     */
    public function pollTick(): void
    {
        if ($this->selectedChatId) {
            $chat = auth()->user()->chats()->with('participants')->find($this->selectedChatId);

            if (! $chat) {
                $this->resetSelectedChat();

                return;
            }

            $latestIncomingMessageId = $this->latestIncomingMessageIdFor($chat);

            $this->latestIncomingMessageId = $latestIncomingMessageId;
            $this->markChatRead($chat);
        }
    }

    protected function latestIncomingMessageIdFor(Chat $chat): int
    {
        return (int) ($this->visibleMessagesFor($chat)
            ->where('user_id', '!=', auth()->id())
            ->max('id') ?? 0);
    }

    protected function markChatRead(Chat $chat): void
    {
        $participant = $chat->participants->firstWhere('id', auth()->id());
        $lastReadAt = $participant?->pivot?->last_read_at;
        $latestOtherMessageAt = $this->visibleMessagesFor($chat)
            ->where('user_id', '!=', auth()->id())
            ->max('created_at');

        if (! $latestOtherMessageAt || ($lastReadAt && Carbon::parse($lastReadAt)->gte(Carbon::parse($latestOtherMessageAt)))) {
            return;
        }

        $chat->participants()->updateExistingPivot(auth()->id(), ['last_read_at' => now()]);
        $this->broadcastChatEvent(new ChatRead($chat->id, auth()->id()));
    }

    protected function resetSelectedChat(): void
    {
        foreach ($this->uploads as $upload) {
            if ($upload instanceof TemporaryUploadedFile) {
                $upload->delete();
            }
        }

        if ($this->voiceUpload instanceof TemporaryUploadedFile) {
            $this->voiceUpload->delete();
        }

        if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
            $this->groupPhotoUpload->delete();
        }
        $this->groupPhotoUpload = null;

        $this->selectedChatId = null;
        $this->latestIncomingMessageId = 0;
        $this->messageText = '';
        $this->uploads = [];
        $this->voiceUpload = null;
        $this->showGroupSettings = false;
        $this->showDeleteMessageModal = false;
        $this->pendingDeleteMessageId = null;
        $this->dispatch('chat:pane-list');
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
            ->with('participants')
            ->orderByDesc('chats.updated_at')
            ->get();

        if ($chats->isNotEmpty()) {
            $latestMessageIds = ChatMessage::query()
                ->join('chat_user', function ($join) use ($me): void {
                    $join->on('chat_user.chat_id', '=', 'chat_messages.chat_id')
                        ->where('chat_user.user_id', '=', $me->id);
                })
                ->whereNull('chat_user.hidden_at')
                ->whereIn('chat_messages.chat_id', $chats->pluck('id'))
                ->where(function ($query): void {
                    $query->whereNull('chat_user.joined_at')
                        ->orWhereColumn('chat_messages.created_at', '>=', 'chat_user.joined_at');
                })
                ->where(function ($query): void {
                    $query->whereNull('chat_user.cleared_at')
                        ->orWhereColumn('chat_messages.created_at', '>=', 'chat_user.cleared_at');
                })
                ->groupBy('chat_messages.chat_id')
                ->selectRaw('MAX(chat_messages.id) as latest_message_id')
                ->pluck('latest_message_id');
            $latestMessages = ChatMessage::query()
                ->with(['sender', 'files'])
                ->whereKey($latestMessageIds)
                ->get()
                ->keyBy('chat_id');

            $chats->each(
                fn (Chat $chat) => $chat->setRelation('latestMessage', $latestMessages->get($chat->id))
            );
        }

        if (filled($this->search)) {
            $needle = mb_strtolower(trim($this->search));
            $chats = $chats->filter(
                fn (Chat $chat) => str_contains(mb_strtolower($chat->displayNameFor($me)), $needle)
            )->values();
        }

        $selectedChat = null;
        $pendingDeleteChat = $this->pendingDeleteChatId
            ? $me->chats()->with('participants')->find($this->pendingDeleteChatId)
            : null;
        $messages = collect();

        if ($this->selectedChatId) {
            $selectedChat = $chats->firstWhere('id', $this->selectedChatId)
                ?? $me->chats()->with('participants')->find($this->selectedChatId);

            if ($selectedChat) {
                $messages = $this->visibleMessagesFor($selectedChat)
                    ->with(['sender:id,name,profile_photo_path', 'files', 'views:id,chat_message_id,user_id,viewed_at'])
                    ->orderBy('id')
                    ->limit(200)
                    ->get();
            } else {
                $this->selectedChatId = null;
            }
        }

        $contacts = null;
        $groupParticipants = null;
        $groupCandidates = null;

        if ($this->showNewChat && $this->newChatTab === 'direct') {
            $contacts = $this->contactPickerQuery($this->directContactSearch)
                ->paginate(
                    self::MEMBER_PICKER_PER_PAGE,
                    ['*'],
                    'directContactsPage',
                );
        }

        if ($this->showNewChat && $this->newChatTab === 'group') {
            $groupParticipants = $this->contactPickerQuery($this->groupParticipantSearch)
                ->paginate(
                    self::MEMBER_PICKER_PER_PAGE,
                    ['*'],
                    'groupParticipantsPage',
                );
        }

        if (
            $this->showGroupSettings
            && $selectedChat?->isGroup()
            && $selectedChat->canManageGroup($me)
        ) {
            $currentMemberIds = $selectedChat->participants
                ->where('id', '!=', $me->id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values();
            $needle = trim($this->groupMemberSearch);

            $groupCandidates = User::query()
                ->with(['profile', 'currentTeam'])
                ->where('id', '!=', $me->id)
                ->where(function ($query) use ($currentMemberIds): void {
                    $query->where('status', true)
                        ->orWhereIn('id', $currentMemberIds);
                })
                ->when($needle !== '', function ($query) use ($needle): void {
                    $query->where(function ($query) use ($needle): void {
                        $query->where('name', 'like', '%' . $needle . '%')
                            ->orWhere('email', 'like', '%' . $needle . '%');
                    });
                })
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(
                    self::MEMBER_PICKER_PER_PAGE,
                    ['*'],
                    'groupMembersPage',
                );
        }

        return view('livewire.chat-box', [
            'chats' => $chats,
            'selectedChat' => $selectedChat,
            'pendingDeleteChat' => $pendingDeleteChat,
            'messages' => $messages,
            'contacts' => $contacts,
            'groupParticipantsPaginator' => $groupParticipants,
            'groupCandidates' => $groupCandidates,
        ])->layout('layouts.master', ['contentMode' => 'viewport']);
    }
}
