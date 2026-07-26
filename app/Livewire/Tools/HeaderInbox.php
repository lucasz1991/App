<?php

namespace App\Livewire\Tools;

use App\Models\ChatMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class HeaderInbox extends Component
{
    public int $unreadMessagesCount = 0;

    public int $unreadChatMessagesCount = 0;

    public $receivedMessages;            // Collection (kleine Liste)

    public function mount(): void
    {
        // Beim ersten Laden keinen Benachrichtigungston ausloesen — nur
        // wenn die Zaehler im laufenden Betrieb (Polling/Refresh) steigen.
        $this->loadInbox(notify: false);
    }

    #[On('inbox:refresh')]
    public function loadInbox(bool $notify = true): void
    {
        $previousUnreadMessages = $this->unreadMessagesCount;
        $previousUnreadChatMessages = $this->unreadChatMessagesCount;

        $user = Auth::user();

        if (! $user) {
            $this->unreadMessagesCount = 0;
            $this->unreadChatMessagesCount = 0;
            $this->receivedMessages = collect();

            return;
        }

        // kleine Liste fuer das Dropdown (letzte 3)
        $this->receivedMessages = $user->receivedMessages()
            ->with(['sender:id,name,role,profile_photo_path'])
            ->withCount('files')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $this->unreadMessagesCount = $user->receivedMessages()
            ->where('status', 1)
            ->count();

        $this->unreadChatMessagesCount = ChatMessage::query()
            ->join('chat_user', function ($join) use ($user) {
                $join->on('chat_user.chat_id', '=', 'chat_messages.chat_id')
                    ->where('chat_user.user_id', '=', $user->id);
            })
            ->where('chat_messages.user_id', '!=', $user->id)
            ->where(function ($query) {
                $query->whereNull('chat_user.last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 'chat_user.last_read_at');
            })
            ->count();

        // Polling-Fallback ohne Reverb: Steigt ein Ungelesen-Zaehler, den
        // Nachrichtenton anstossen (app.js spielt ihn nur, wenn kein Echo
        // verbunden ist — sonst klingelt bereits der Echtzeit-Toast). Die
        // Quelle waehlt im Client die passende Chat-/Posteingangs-Klangsignatur.
        $inboxIncreased = $this->unreadMessagesCount > $previousUnreadMessages;
        $chatIncreased = $this->unreadChatMessagesCount > $previousUnreadChatMessages;

        if ($notify && ($inboxIncreased || $chatIncreased)) {
            $this->dispatch(
                'rt:inbox-increased',
                source: $inboxIncreased && $chatIncreased ? 'both' : ($chatIncreased ? 'chat' : 'inbox'),
            );
        }
    }

    /**
     * Letzte Chats fuer das gemeinsame Posteingangs-Dropdown.
     *
     * Bewusst NICHT als oeffentliche Livewire-Eigenschaft: die Chat-Modelle
     * muessten sonst bei jedem Request serialisiert und rehydriert werden und
     * verlieren dabei ihre Eager-Loads (participants/latestMessage), die
     * displayNameFor()/unreadCountFor() brauchen. Frisch im render() geladen
     * bleiben die Beziehungen garantiert vorhanden.
     */
    protected function recentChats(): Collection
    {
        $user = Auth::user();

        // Der hasTable-Check haelt die Topbar auch dann lauffaehig, wenn die
        // Chat-Tabellen (noch) fehlen — z. B. vor der Migration.
        if (! $user || ! \Illuminate\Support\Facades\Schema::hasTable('chats')) {
            return collect();
        }

        return $user->chats()
            ->with(['participants', 'latestMessage'])
            ->orderByDesc('chats.updated_at')
            ->limit(3)
            ->get();
    }

    /**
     * Ungelesene Chat-Nachrichten je Chat — in EINER Abfrage.
     *
     * Chat::unreadCountFor() wuerde pro Chat eine eigene COUNT-Abfrage
     * ausloesen (N+1 auf jeder Seite, weil die Topbar ueberall rendert). Die
     * Bedingung ist absichtlich identisch zu der in loadInbox(), damit der
     * Gesamtzaehler und die Zeilen-Badges nie auseinanderlaufen koennen.
     *
     * @return array<int, int>
     */
    protected function unreadPerChat(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return ChatMessage::query()
            ->join('chat_user', function ($join) use ($user) {
                $join->on('chat_user.chat_id', '=', 'chat_messages.chat_id')
                    ->where('chat_user.user_id', '=', $user->id);
            })
            ->where('chat_messages.user_id', '!=', $user->id)
            ->where(function ($query) {
                $query->whereNull('chat_user.last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 'chat_user.last_read_at');
            })
            ->groupBy('chat_messages.chat_id')
            ->selectRaw('chat_messages.chat_id as chat_id, count(*) as aggregate')
            ->pluck('aggregate', 'chat_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    public function render()
    {
        return view('livewire.tools.header-inbox', [
            'recentChats' => $this->recentChats(),
            'unreadPerChat' => $this->unreadPerChat(),
            'totalUnreadCount' => $this->unreadMessagesCount + $this->unreadChatMessagesCount,
        ]);
    }
}
