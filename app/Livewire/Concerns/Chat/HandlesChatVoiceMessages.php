<?php

namespace App\Livewire\Concerns\Chat;

use App\Models\ChatMessage;
use App\Services\Chat\ChatMessageInteractionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sprachnachrichten: Aufnahme ueber einen eigenen Uploadkanal senden und die
 * Wiedergabe freigeben. Einmal-Nachrichten erhalten genau ein vom Server
 * ausgestelltes Wiedergabe-Token.
 */
trait HandlesChatVoiceMessages
{
    /** Separater Uploadkanal fuer eine aufgenommene Sprachnachricht. */
    public $voiceUpload = null;

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
        $replyTarget = app(ChatMessageInteractionService::class)
            ->replyTarget($chat, auth()->user(), $this->replyingToMessageId);
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => auth()->id(),
            'reply_to_message_id' => $replyTarget?->id,
            'body' => '',
            'message_type' => 'voice',
            'view_once' => $viewOnce,
            'voice_duration_seconds' => $durationSeconds > 0 ? min(300, $durationSeconds) : null,
            'voice_waveform' => $waveform !== [] ? $waveform : null,
        ]);

        try {
            $this->storeMessageUpload($message, $this->voiceUpload, 'voice');
        } catch (\Throwable $exception) {
            $message->forceDelete();

            throw $exception;
        }

        $this->voiceUpload = null;
        $this->replyingToMessageId = null;
        $this->finishSending($chat, $message);
        $this->dispatch('chat:voice-sent');
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
}
