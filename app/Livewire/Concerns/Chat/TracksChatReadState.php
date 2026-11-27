<?php

namespace App\Livewire\Concerns\Chat;

use App\Events\ChatRead;
use App\Models\Chat;
use Illuminate\Support\Carbon;

/**
 * Lesestatus des offenen Chats: merkt die letzte fremde Nachricht, markiert
 * den Chat nur bei sichtbarem Gespraech als gelesen und traegt den
 * Polling-Fallback (wire:poll → pollTick), wenn keine Echtzeitverbindung steht.
 */
trait TracksChatReadState
{
    /**
     * Letzte fremde Nachricht des offenen Chats. Der Ausgangswert wird beim
     * Oeffnen gesetzt, damit alte Nachrichten keinen Ton ausloesen. So kann
     * pollTick ohne Reverb neue Eingaenge erkennen, bevor er sie als gelesen
     * markiert.
     */
    public int $latestIncomingMessageId = 0;

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
        if (! $this->conversationVisible) {
            return;
        }
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
}
