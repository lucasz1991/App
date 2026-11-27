<?php

namespace App\Livewire;

use App\Livewire\Concerns\Chat\DeletesChatsAndMessages;
use App\Livewire\Concerns\Chat\HandlesChatVoiceMessages;
use App\Livewire\Concerns\Chat\InteractsWithChatAccess;
use App\Livewire\Concerns\Chat\ManagesChatGroups;
use App\Livewire\Concerns\Chat\ManagesChatRepliesAndReactions;
use App\Livewire\Concerns\Chat\NavigatesChatConversations;
use App\Livewire\Concerns\Chat\SendsChatMessages;
use App\Livewire\Concerns\Chat\StartsChatCalls;
use App\Livewire\Concerns\Chat\TracksChatReadState;
use App\Livewire\Concerns\InteractsWithPersonQuickActions;
use App\Livewire\Concerns\ResetsPageOnFilterChange;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\ChatMessageInteractionService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;

/**
 * Chat des Kommunikationsmoduls: Gespraechsliste, offenes Gespraech und die
 * zugehoerigen Dialoge in EINER Livewire-Komponente (keine Kindkomponenten,
 * damit Auswahl, Entwurf und Uploads einen gemeinsamen Zustand behalten).
 *
 * Die Klasse selbst haelt nur noch das Zusammenstellen der Ansicht; die
 * Aktionen liegen nach Bereichen getrennt unter App\Livewire\Concerns\Chat:
 *
 *   InteractsWithChatAccess         Teilnahme pruefen, sichtbarer Verlauf, Echtzeitereignisse
 *   NavigatesChatConversations      Chat oeffnen, Filter, aeltere Nachrichten, Auswahl aufraeumen
 *   TracksChatReadState             Lesestatus und Polling-Fallback
 *   SendsChatMessages               Text und Anhaenge senden
 *   HandlesChatVoiceMessages        Sprachnachrichten senden und abspielen
 *   ManagesChatRepliesAndReactions  Antworten und Reaktionen
 *   DeletesChatsAndMessages         Nachrichten und Chats loeschen, Gruppe verlassen
 *   ManagesChatGroups               neuer Chat, neue Gruppe, Gruppenverwaltung, Personenlisten
 *   StartsChatCalls                 Anruf aus dem Chat starten
 *
 * Namen oeffentlicher Properties und Methoden sind Teil der Schnittstelle zu
 * offenen Browser-Sitzungen und zur Ansicht livewire.chat-box (samt Partials
 * unter livewire/chat-box/) und duerfen nicht umbenannt werden.
 */
class ChatBox extends Component
{
    use InteractsWithPersonQuickActions;
    use WithFileUploads;
    use WithoutUrlPagination;
    use ResetsPageOnFilterChange;
    use WithPagination;
    use DeletesChatsAndMessages;
    use HandlesChatVoiceMessages;
    use InteractsWithChatAccess;
    use ManagesChatGroups;
    use ManagesChatRepliesAndReactions;
    use NavigatesChatConversations;
    use SendsChatMessages;
    use StartsChatCalls;
    use TracksChatReadState;

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected function pageResetProperties(): array
    {
        return ['directContactSearch' => 'directContactsPage', 'groupParticipantSearch' => 'groupParticipantsPage', 'groupMemberSearch' => 'groupMembersPage'];
    }

    public function render()
    {
        $me = auth()->user();

        $chats = $me->chats()
            ->with([
                'participants' => fn ($query) => $query
                    ->withMax('activities as last_activity_at', 'created_at'),
            ])
            ->where('chats.type', '!=', 'call')
            ->orderByDesc('chats.updated_at')
            ->get();

        if ($chats->isNotEmpty()) {
            $latestMessageIds = ChatMessage::query()
                ->visibleToParticipant($me)
                ->whereIn('chat_messages.chat_id', $chats->pluck('id'))
                ->groupBy('chat_messages.chat_id')
                ->selectRaw('MAX(chat_messages.id) as latest_message_id')
                ->pluck('latest_message_id');
            $latestMessages = ChatMessage::query()
                ->with(['sender', 'files', 'liveLocation'])
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
            ? $me->chats()->with('participants')->where('chats.type', '!=', 'call')->find($this->pendingDeleteChatId)
            : null;
        $messages = collect();
        $hasOlderMessages = false;
        $replyingToMessage = null;

        if ($this->selectedChatId) {
            $selectedChat = $chats->firstWhere('id', $this->selectedChatId)
                ?? $me->chats()
                    ->with([
                        'participants' => fn ($query) => $query
                            ->withMax('activities as last_activity_at', 'created_at'),
                    ])
                    ->where('chats.type', '!=', 'call')
                    ->find($this->selectedChatId);

            if ($selectedChat) {
                $visibleSince = $selectedChat->visibleSinceFor($me);
                $messages = $this->visibleMessagesFor($selectedChat, true)
                    ->when($this->oldestLoadedMessageId, fn ($query) => $query->where('id', '>=', $this->oldestLoadedMessageId))
                    ->with([
                        'sender:id,name,profile_photo_path',
                        'files',
                        'liveLocation',
                        'views:id,chat_message_id,user_id,viewed_at',
                        'callRoom.participants',
                        'callRoom.invitations',
                        'callRoom.events',
                        'callRoom.callChat.participants',
                        'reactions' => fn ($query) => $query
                            ->select(['id', 'chat_message_id', 'user_id', 'emoji'])
                            ->with('user:id,name'),
                        'replyTo' => fn ($query) => $query
                            ->withTrashed()
                            ->when($visibleSince, fn ($query) => $query->where('created_at', '>=', $visibleSince))
                            ->with(['sender:id,name', 'files']),
                    ])
                    ->orderBy('id')
                    ->get();

                $messages->filter(fn (ChatMessage $message) => $message->isCall())
                    ->each(fn (ChatMessage $message) => $message->setRelation('chat', $selectedChat));

                $firstMessageId = $messages->first()?->id;
                $hasOlderMessages = $firstMessageId
                    ? $this->visibleMessagesFor($selectedChat, true)->where('id', '<', $firstMessageId)->exists()
                    : false;

                if ($this->replyingToMessageId) {
                    $replyingToMessage = $this->visibleMessagesFor($selectedChat, true)
                        ->with(['sender:id,name', 'files'])
                        ->find($this->replyingToMessageId);

                    if (! $replyingToMessage) {
                        $this->replyingToMessageId = null;
                    }
                }
            } else {
                $this->selectedChatId = null;
            }
        }

        return view('livewire.chat-box', [
            'chats' => $chats,
            'selectedChat' => $selectedChat,
            'pendingDeleteChat' => $pendingDeleteChat,
            'messages' => $messages,
            'hasOlderMessages' => $hasOlderMessages,
            'replyingToMessage' => $replyingToMessage,
            'quickReactions' => ChatMessageInteractionService::QUICK_REACTIONS,
            'allowedReactions' => ChatMessageInteractionService::ALLOWED_REACTIONS,
            ...$this->memberPickerViewData($selectedChat, $me),
        ]);
    }
}
