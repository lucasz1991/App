<?php

namespace App\Livewire\Tools;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class HeaderInbox extends Component
{
    public int $unreadMessagesCount = 0;
    public int $unreadChatMessagesCount = 0;
    public $receivedMessages;            // Collection (kleine Liste)

    public function mount(): void
    {
        $this->loadInbox();
    }

    #[\Livewire\Attributes\On('inbox:refresh')]
    public function loadInbox(): void
    {
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
    }

    public function render()
    {
        return view('livewire.tools.header-inbox');
    }
}
