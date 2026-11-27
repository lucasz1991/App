<?php

namespace App\Livewire\Concerns\Chat;

use App\Models\Chat;
use Illuminate\Support\Facades\Log;

/**
 * Zugriffsgrundlage der Chat-Komponente: prueft die Teilnahme am Chat,
 * begrenzt den Verlauf auf den fuer den Nutzer sichtbaren Zeitraum und
 * sendet Echtzeitereignisse, ohne bei Ausfall der Verbindung zu scheitern.
 *
 * Alle anderen Chat-Concerns bauen auf diesen drei Methoden auf.
 */
trait InteractsWithChatAccess
{
    /** Chat des angemeldeten Nutzers laden oder 403. */
    protected function myChat(int $chatId): Chat
    {
        $chat = Chat::with('participants')
            ->where('type', '!=', 'call')
            ->findOrFail($chatId);
        $participant = $chat->participantFor(auth()->id());

        abort_unless($participant && ! $participant->pivot?->hidden_at, 403);

        return $chat;
    }

    protected function visibleMessagesFor(Chat $chat, bool $withDeleted = false)
    {
        $visibleSince = $chat->visibleSinceFor(auth()->id());

        return $chat->messages()
            ->when($withDeleted, fn ($query) => $query->withTrashed())
            ->when($visibleSince, fn ($query) => $query->where('created_at', '>=', $visibleSince));
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
}
