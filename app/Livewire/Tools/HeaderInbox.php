<?php

namespace App\Livewire\Tools;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class HeaderInbox extends Component
{
    public int $unreadMessagesCount = 0;

    // Keep the chat polling fallback until the communication host owns it.
    public int $unreadChatMessagesCount = 0;

    public int $latestUnreadMessageId = 0;

    public int $latestUnreadChatMessageId = 0;

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
        $previousLatestUnreadMessageId = $this->latestUnreadMessageId;
        $previousLatestUnreadChatMessageId = $this->latestUnreadChatMessageId;

        $user = Auth::user();

        if (! $user) {
            $this->unreadMessagesCount = 0;
            $this->unreadChatMessagesCount = 0;
            $this->latestUnreadMessageId = 0;
            $this->latestUnreadChatMessageId = 0;
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

        $unreadMessages = $user->receivedMessages()->where('status', 1);
        $latestUnreadMessage = (clone $unreadMessages)
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->first();
        $this->unreadMessagesCount = (clone $unreadMessages)->count();
        $this->latestUnreadMessageId = (int) ($latestUnreadMessage?->id ?? 0);

        $unreadChatMessages = ChatMessage::query()
            ->visibleToParticipant($user)
            ->excludingCallChats()
            ->unreadByParticipant($user);
        $latestUnreadChatMessage = (clone $unreadChatMessages)
            ->select('chat_messages.*')
            ->with('sender:id,name')
            ->orderByDesc('chat_messages.id')
            ->first();
        $this->unreadChatMessagesCount = (clone $unreadChatMessages)->count();
        $this->latestUnreadChatMessageId = (int) ($latestUnreadChatMessage?->id ?? 0);

        // Polling-Fallback ohne Reverb: Steigt ein Ungelesen-Zaehler, bekommt
        // app.js den stabilen Ereignisschluessel plus eine knappe Vorschau.
        // So entstehen derselbe Suite-Alert und Klang wie ueber Echo, waehrend
        // der Seen-Cache Web-Push-Doppelungen verhindert.
        $inboxIncreased = $this->unreadMessagesCount > $previousUnreadMessages;
        $chatIncreased = $this->unreadChatMessagesCount > $previousUnreadChatMessages;

        if ($notify && ($inboxIncreased || $chatIncreased)) {
            $notifications = [];

            if (
                $inboxIncreased
                && $latestUnreadMessage
                && $this->latestUnreadMessageId > $previousLatestUnreadMessageId
            ) {
                $notifications[] = [
                    'notification_id' => 'message:'.$latestUnreadMessage->id,
                    'category' => 'messages',
                    'title' => __('app.new_message'),
                    'body' => collect([
                        $latestUnreadMessage->sender?->name
                            ? __('app.from').': '.$latestUnreadMessage->sender->name
                            : null,
                        $latestUnreadMessage->subject,
                    ])->filter()->join(' — '),
                ];
            }

            if (
                $chatIncreased
                && $latestUnreadChatMessage
                && $this->latestUnreadChatMessageId > $previousLatestUnreadChatMessageId
            ) {
                $notifications[] = [
                    'notification_id' => 'chat-message:'.$latestUnreadChatMessage->id,
                    'category' => 'chat',
                    'chatId' => (int) $latestUnreadChatMessage->chat_id,
                    'title' => __('app.new_chat_message'),
                    'body' => $latestUnreadChatMessage->sender?->name
                        ? __('app.from').': '.$latestUnreadChatMessage->sender->name
                        : '',
                ];
            }

            $this->dispatch(
                'rt:inbox-increased',
                source: $inboxIncreased && $chatIncreased ? 'both' : ($chatIncreased ? 'chat' : 'inbox'),
                notifications: $notifications,
            );
        }
    }

    public function render()
    {
        return view('livewire.tools.header-inbox', [
            'totalUnreadCount' => $this->unreadMessagesCount,
        ]);
    }
}
