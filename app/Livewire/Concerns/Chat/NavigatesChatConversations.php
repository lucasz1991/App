<?php

namespace App\Livewire\Concerns\Chat;

use App\Models\Chat;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Auswahl und Fenster des offenen Gespraechs: Chat oeffnen, Sichtbarkeit
 * und Listenfilter aus dem Kommunikationsmodul uebernehmen, aeltere
 * Nachrichten nachladen (Cursor statt Offset) und die Auswahl aufraeumen.
 *
 * Die Ereignisnamen (communication:*, chat:*) sind Teil der Schnittstelle
 * zum Kommunikationsmodul und zu den Chat-Skripten.
 */
trait NavigatesChatConversations
{
    public ?int $selectedChatId = null;

    public bool $conversationVisible = false;

    public string $listFilter = 'all';

    public string $search = '';

    /** ID der aeltesten aktuell geladenen Nachricht (Cursor, kein Offset). */
    #[Locked]
    public ?int $oldestLoadedMessageId = null;

    #[On('communication:chat')]
    public function openChat(int $chatId): void
    {
        $chat = $this->myChat($chatId);
        $this->conversationVisible = true;

        if ($this->selectedChatId === $chat->id) {
            $this->markChatRead($chat);
            $this->dispatch('chat:pane-open');

            return;
        }

        $this->selectedChatId = $chat->id;
        $this->messageText = '';
        $this->replyingToMessageId = null;
        $this->resetMessageWindow($chat);
        $this->latestIncomingMessageId = $this->latestIncomingMessageIdFor($chat);

        $chat->participants()->updateExistingPivot(auth()->id(), ['last_opened_at' => now()]);
        $this->markChatRead($chat);
        $this->dispatch('chat:pane-open');
    }

    #[On('communication:visibility')]
    public function setConversationVisibility(bool $visible): void
    {
        $this->conversationVisible = $visible;
        if ($visible && $this->selectedChatId) {
            $this->markChatRead($this->myChat($this->selectedChatId));
            $this->dispatch('inbox:refresh');
        }
    }

    #[On('communication:new-chat')]
    public function newCommunicationChat(): void
    {
        $this->showNewChat = true;
    }

    #[On('communication:filter')]
    public function setCommunicationFilter(string $filter = 'all'): void
    {
        $this->listFilter = in_array($filter, ['all', 'unread', 'groups'], true)
            ? $filter
            : 'all';
    }

    #[On('chat:timeline-refresh')]
    public function refreshTimeline(): void
    {
        // Render the existing window again without resetting draft/cursor state.
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

    public function loadOlderMessages(): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);

        if (! $this->oldestLoadedMessageId) {
            $this->resetMessageWindow($chat);
        }

        if (! $this->oldestLoadedMessageId) {
            return;
        }

        $olderIds = $this->visibleMessagesFor($chat, true)
            ->where('id', '<', $this->oldestLoadedMessageId)
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('id');

        if ($olderIds->isEmpty()) {
            return;
        }

        $this->oldestLoadedMessageId = (int) $olderIds->min();
        $this->dispatch('chat:older-loaded');
    }

    protected function resetMessageWindow(Chat $chat): void
    {
        $ids = $this->visibleMessagesFor($chat, true)
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('id');

        $this->oldestLoadedMessageId = $ids->isEmpty() ? null : (int) $ids->min();
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
        $this->replyingToMessageId = null;
        $this->oldestLoadedMessageId = null;
        $this->uploads = [];
        $this->voiceUpload = null;
        $this->showGroupSettings = false;
        $this->showDeleteMessageModal = false;
        $this->pendingDeleteMessageId = null;
        $this->dispatch('chat:pane-list');
    }
}
