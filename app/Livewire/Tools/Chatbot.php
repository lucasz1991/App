<?php

namespace App\Livewire\Tools;

use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\RailtimeAssistantContext;
use App\Services\Ai\SpeechServiceClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Chatbot extends Component
{
    public string $message = '';

    /** @var array<int, array{key: string, role: string, content: string, created_at: string}> */
    public array $chatHistory = [];

    /** @var array<int, array{key: string, label: string, prompt: string}> */
    public array $quickActions = [];

    public bool $isLoading = false;

    public string $assistantName = 'RailTime Assist';

    public bool $assistantAvailable = false;

    public bool $speechAvailable = false;

    #[Locked]
    public string $pageRouteName = 'unknown';

    public function mount(): void
    {
        $this->authorizeUser();
        $this->assistantName = (string) config('assistant.name', 'RailTime Assist');
        $this->quickActions = $this->availableQuickActions();
        $this->pageRouteName = request()->route()?->getName() ?? 'unknown';
        $this->refreshAvailability();
        $this->loadHistory();

        if ($this->chatHistory === []) {
            $this->resetHistory();
        }
    }

    public function hydrate(): void
    {
        $this->authorizeUser();
        $this->quickActions = $this->availableQuickActions();
        $this->refreshAvailability();
        $this->loadHistory();
    }

    public function sendMessage(?string $prompt = null): void
    {
        $user = $this->authorizeUser();

        if ($this->isLoading) {
            return;
        }

        $this->loadHistory();
        $input = $this->cleanInput($prompt ?? $this->message);
        $maxCharacters = (int) config('assistant.max_input_characters', 4000);

        if ($input === '' || mb_strlen($input) > $maxCharacters) {
            $this->addError('message', 'Bitte gib eine Nachricht mit höchstens '.$maxCharacters.' Zeichen ein.');

            return;
        }

        if (! $this->consumeRateLimit($user)) {
            $this->addError('message', 'Zu viele Anfragen in kurzer Zeit. Bitte versuche es später noch einmal.');

            return;
        }

        $conversationLock = $this->conversationLock();
        if (! $conversationLock->get()) {
            $this->addError('message', 'Eine andere Anfrage dieses Chats läuft bereits. Bitte warte kurz.');

            return;
        }

        try {
            $this->sendLockedMessage($user, $input);
        } finally {
            $conversationLock->release();
        }
    }

    private function sendLockedMessage(User $user, string $input): void
    {
        /** @var OpenRouterChatClient $client */
        $client = app(OpenRouterChatClient::class);

        if (! $client->isConfigured()) {
            $this->addError('message', 'Der Assistent ist momentan nicht konfiguriert.');

            return;
        }

        $providerSlot = $this->acquireProviderSlot();
        if (! $providerSlot) {
            $this->addError('message', 'Der Assistent bearbeitet gerade andere Anfragen. Bitte warte kurz.');

            return;
        }

        try {
            $this->loadHistory();
            $this->message = '';
            $this->appendHistory('user', $input);
            $this->isLoading = true;

            $correlationId = (string) Str::uuid();

            try {
                $messages = $this->providerMessages($user);
                $answer = $client->stream($messages, function (string $delta): void {
                    $this->stream(to: 'assistant-response-stream', content: e($delta));
                });

                $entry = $this->appendHistory('assistant', trim($answer));
                $this->dispatch('railtime-assistant-reply', text: $entry['content'], key: $entry['key']);
            } catch (OpenRouterChatException $exception) {
                Log::warning('RailTime assistant request failed.', [
                    'correlation_id' => $correlationId,
                    'reason_code' => $exception->reasonCode,
                    'upstream_status' => $exception->upstreamStatus,
                ]);

                $this->appendAssistantError('Der Assistent ist gerade nicht verfügbar. Referenz: '.$correlationId);
            } catch (\Throwable $exception) {
                Log::error('RailTime assistant failed unexpectedly.', [
                    'correlation_id' => $correlationId,
                    'exception_class' => $exception::class,
                ]);

                $this->appendAssistantError('Der Assistent ist gerade nicht verfügbar. Referenz: '.$correlationId);
            } finally {
                $this->isLoading = false;
            }
        } finally {
            $providerSlot->release();
        }
    }

    public function quickAction(string $actionKey): void
    {
        $this->authorizeUser();
        $action = collect($this->availableQuickActions())->firstWhere('key', $actionKey);

        if (! is_array($action)) {
            abort(422);
        }

        $this->sendMessage($action['prompt']);
    }

    public function clearChat(): void
    {
        $this->authorizeUser();
        $lock = $this->conversationLock();

        if (! $lock->get()) {
            $this->addError('message', 'Die laufende Antwort muss vor dem Leeren abgeschlossen werden.');

            return;
        }

        try {
            $this->resetHistory();
            $this->message = '';
            $this->dispatch('railtime-assistant-cleared');
        } finally {
            $lock->release();
        }
    }

    public function render()
    {
        return view('livewire.tools.chatbot');
    }

    private function authorizeUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isActive(), 403);

        return $user;
    }

    private function refreshAvailability(): void
    {
        $this->assistantAvailable = app(OpenRouterChatClient::class)->isConfigured();
        $this->speechAvailable = app(SpeechServiceClient::class)->isConfigured();
    }

    private function loadHistory(): void
    {
        $history = session()->get($this->sessionKey(), []);
        $this->chatHistory = is_array($history)
            ? array_values(array_slice($history, -(int) config('assistant.history_limit', 80)))
            : [];
    }

    private function resetHistory(): void
    {
        $this->chatHistory = [[
            'key' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => 'Hallo! Ich helfe dir bei der Bedienung von RailTime. Ich kann erklären und navigieren, aber keine Daten oder Einstellungen selbst verändern.',
            'created_at' => now()->toIso8601String(),
        ]];
        $this->persistHistory();
    }

    /** @return array{key: string, role: string, content: string, created_at: string} */
    private function appendHistory(string $role, string $content): array
    {
        $entry = [
            'key' => (string) Str::uuid(),
            'role' => $role,
            'content' => $content,
            'created_at' => now()->toIso8601String(),
        ];

        $this->chatHistory[] = $entry;
        $this->chatHistory = array_values(array_slice(
            $this->chatHistory,
            -(int) config('assistant.history_limit', 80),
        ));
        $this->persistHistory();

        return $entry;
    }

    private function appendAssistantError(string $message): void
    {
        $entry = $this->appendHistory('assistant', $message);
        $this->dispatch('railtime-assistant-reply', text: $entry['content'], key: $entry['key']);
    }

    private function persistHistory(): void
    {
        session()->put($this->sessionKey(), $this->chatHistory);
    }

    private function sessionKey(): string
    {
        return 'railtime_assistant_history_'.auth()->id();
    }

    /** @return array<int, array{role: string, content: string}> */
    private function providerMessages(User $user): array
    {
        $candidates = collect($this->chatHistory)
            ->filter(fn (mixed $entry): bool => is_array($entry)
                && in_array($entry['role'] ?? null, ['user', 'assistant'], true)
                && is_string($entry['content'] ?? null)
                && trim($entry['content']) !== '')
            ->take(-(int) config('assistant.transcript_limit', 36))
            ->map(fn (array $entry): array => [
                'role' => $entry['role'],
                'content' => mb_substr(trim($entry['content']), 0, 12000),
            ])
            ->values()
            ->all();

        $history = [];
        $remainingCharacters = max(4000, (int) config('assistant.max_context_characters', 60000));

        foreach (array_reverse($candidates) as $entry) {
            if ($remainingCharacters <= 0) {
                break;
            }

            $entry['content'] = mb_substr($entry['content'], 0, $remainingCharacters);
            $remainingCharacters -= mb_strlen($entry['content']);
            array_unshift($history, $entry);
        }

        array_unshift($history, app(RailtimeAssistantContext::class)->systemMessage(
            $user,
            $this->pageRouteName,
        ));

        return $history;
    }

    private function consumeRateLimit(User $user): bool
    {
        $limits = [
            ['assistant-chat:user:minute:'.$user->getAuthIdentifier(), (int) config('assistant.chat_limits.user_per_minute', 6), 60],
            ['assistant-chat:user:hour:'.$user->getAuthIdentifier(), (int) config('assistant.chat_limits.user_per_hour', 30), 3600],
            ['assistant-chat:user:day:'.$user->getAuthIdentifier(), (int) config('assistant.chat_limits.user_per_day', 80), 86400],
            ['assistant-chat:global:minute', (int) config('assistant.chat_limits.global_per_minute', 12), 60],
            ['assistant-chat:global:hour', (int) config('assistant.chat_limits.global_per_hour', 100), 3600],
            ['assistant-chat:global:day', (int) config('assistant.chat_limits.global_per_day', 300), 86400],
        ];

        foreach ($limits as [$key, $maximum]) {
            if ($maximum < 1 || RateLimiter::tooManyAttempts($key, $maximum)) {
                return false;
            }
        }

        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return true;
    }

    private function conversationLock(): Lock
    {
        $sessionHash = hash('sha256', session()->getId());

        return Cache::lock(
            'assistant-chat:conversation:'.auth()->id().':'.$sessionHash,
            (int) config('assistant.chat_limits.lock_seconds', 660),
        );
    }

    private function acquireProviderSlot(): ?Lock
    {
        $slots = max(1, min(20, (int) config('assistant.chat_limits.provider_concurrency', 3)));
        $seconds = (int) config('assistant.chat_limits.lock_seconds', 660);

        for ($slot = 1; $slot <= $slots; $slot++) {
            $lock = Cache::lock('assistant-chat:provider-slot:'.$slot, $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private function cleanInput(string $input): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input));
    }

    /** @return array<int, array{key: string, label: string, prompt: string}> */
    private function availableQuickActions(): array
    {
        return [
            ['key' => 'orientation', 'label' => 'Was kann ich hier tun?', 'prompt' => 'Erkläre mir kurz, wofür die aktuelle RailTime-Seite gedacht ist und welche nächsten Schritte sinnvoll sind.'],
            ['key' => 'messages', 'label' => 'Nachrichten erklären', 'prompt' => 'Erkläre mir kurz, wie Nachrichten und Chats in RailTime funktionieren.'],
            ['key' => 'support', 'label' => 'Hilfe finden', 'prompt' => 'Wo finde ich in RailTime Hilfe oder den IT-Support?'],
        ];
    }
}
