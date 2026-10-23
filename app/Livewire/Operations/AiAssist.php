<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AssistantSpeechException;
use App\Services\Ai\AssistantSpeechRouter;
use App\Services\Ai\Attachments\AssistantAttachmentException;
use App\Services\Ai\Attachments\AssistantAttachmentProcessor;
use App\Services\Operations\AiAssistService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * AI-Assist: übergreifender Disposition-Assistent (Chat, Eingänge, Aktionen, Aktivitäten) im
 * Erscheinungsbild des RailTime-Chatbots. Der Verlauf liegt serverseitig in der Sitzung, damit
 * Karten-Aktionen (Übernehmen, Zurücknehmen) nur auf selbst erzeugte Vorschläge wirken.
 */
class AiAssist extends Component
{
    use WithFileUploads;

    private const SESSION = 'operations.ai_assist.messages';

    private const MAX_MESSAGES = 24;

    public string $prompt = '';

    public array $uploads = [];

    public $audioUpload = null;

    #[Locked]
    public string $page = 'other';

    #[Locked]
    public string $pageLabel = 'Disposition';

    #[Locked]
    public string $from = '';

    #[Locked]
    public string $until = '';

    /** Inhalte erst nach dem ersten Öffnen laden: der Starter bleibt auf jeder Seite leicht. */
    #[Locked]
    public bool $loaded = false;

    public string $tab = 'chat';

    #[Locked]
    public bool $intakeMounted = false;

    #[Locked]
    public bool $intakeRequested = false;

    #[Locked]
    public ?int $intakeId = null;

    #[Locked]
    public ?int $intakeCustomerId = null;

    #[Locked]
    public bool $intakeCapture = false;

    public string $activityFilter = 'all';

    public array $config = [];

    public function mount(string $page = '', string $view = '', string $section = ''): void
    {
        // Shared Selects bind their value during Alpine initialization.
        // Load authorized settings before rendering their nested models.
        if ($this->actor()->isSuperAdmin()) {
            $this->openSettings();
        }
        $context = app(AiAssistService::class)->context($page, $view, $section);
        [$this->page, $this->pageLabel, $this->from, $this->until] = [$context['page'], $context['label'], $context['from'], $context['until']];
        if ($intakeContext = OperationsPages::intakePanelContext(request())) {
            $this->openIntake($intakeContext['intakeId'], $intakeContext['create'], $intakeContext['customerId']);
        }
    }

    #[On('open-ai-intake-panel')]
    public function openIntake(?int $intakeId = null, bool $create = false, ?int $customerId = null): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.inquiries.manage');
        AiIntakeSchema::requireReady();
        abort_unless(($intakeId === null || $intakeId > 0) && ($customerId === null || $customerId > 0)
            && ! ($intakeId !== null && $create), 422);
        if ($customerId !== null) {
            Customer::findOrFail($customerId);
        }
        if ($intakeId !== null) {
            $intake = AiIntake::findOrFail($intakeId);
            abort_if($customerId !== null && $intake->customer_id !== $customerId, 404);
        }
        $existing = $this->intakeMounted && $this->intakeCustomerId === $customerId;
        $this->intakeId = $intakeId;
        $this->intakeCustomerId = $customerId;
        $this->intakeCapture = $create && $intakeId === null;
        $this->loaded = $this->intakeMounted = $this->intakeRequested = true;
        $this->tab = 'intake';
        if ($existing) {
            $this->dispatch('open-ai-intake', intakeId: $intakeId, create: $this->intakeCapture, parentComponentId: $this->getId());
        }
        $this->dispatch('ai-assist-intake-opened', componentId: $this->getId());
    }

    #[On('operations-period-changed')]
    public function setPeriod(string $from, string $until): void
    {
        $this->actor();
        $context = app(AiAssistService::class)->context('cases', 'shifts', 'plan', $from, $until);
        [$this->from, $this->until] = [$context['from'], $context['until']];
    }

    public function load(): void
    {
        $this->actor();
        $this->loaded = true;
    }

    #[On('operations-assistant-context-changed')]
    public function updateContext(string $page, string $view = '', string $section = ''): void
    {
        $actor = $this->actor();
        abort_unless(isset(OperationsPages::definitions()[$page]) && OperationsPages::views($actor, $page), 403);
        $context = app(AiAssistService::class)->context($page, $view, $section, $this->from, $this->until);
        [$this->page, $this->pageLabel, $this->from, $this->until] = [$context['page'], $context['label'], $context['from'], $context['until']];
    }

    public function setTab(string $tab): void
    {
        $this->actor();
        abort_unless(in_array($tab, ['chat', 'intake', 'actions', 'activity'], true), 422);
        if ($this->tab === 'chat' && $tab !== 'chat') {
            $this->dispatch('rt-ai-assist-composer-hidden');
        }
        if ($tab === 'intake') {
            OperationsAccess::authorize($this->actor(), 'operations.inquiries.manage');
            AiIntakeSchema::requireReady();
            $this->intakeMounted = true;
        } elseif ($this->tab === 'intake') {
            $this->dispatch('ai-intake-panel-hidden');
        }
        $this->tab = $tab;
        $this->loaded = true;
    }

    #[On('operations-assistant-action')]
    public function run(string $action): void
    {
        $actor = $this->actor();
        $lock = Cache::lock('operations:assist:'.session()->getId().':'.$actor->id, 60);
        abort_unless($lock->get(), 409, 'Der Assistent bearbeitet gerade eine Aktion. Bitte kurz warten.');
        try {
            $this->runAction($action, $actor);
            $this->prompt = '';
            $this->dispatch('ai-intake-composer-submitted', componentId: $this->getId());
        } finally {
            $lock->release();
        }
    }

    private function runAction(string $action, User $actor): void
    {
        $service = app(AiAssistService::class);
        abort_unless(in_array($action, $service->permitted($actor), true), 403);
        $this->consumeAssistRate($actor);
        $this->loaded = true;
        $this->setTab('chat');
        // Erst auswerten (prüft Recht und Aktion), dann Frage und Antwort gemeinsam ablegen.
        $answer = $service->answer($action, $actor, $this->context());
        $this->push(['role' => 'user', 'text' => $service->title($action)]);
        $this->push(['role' => 'assistant', 'action' => $action] + $answer);
    }

    public function ask(string $text): void
    {
        $actor = $this->actor();
        $lock = Cache::lock('operations:assist:'.session()->getId().':'.$actor->id, 60);
        abort_unless($lock->get(), 409, 'Der Assistent bearbeitet gerade eine Aktion. Bitte kurz warten.');
        try {
            $this->askText($text, $actor);
        } finally {
            $lock->release();
        }
    }

    private function askText(string $text, User $actor): void
    {
        $this->answerText(trim(mb_substr($text, 0, 500)), $actor);
    }

    /** Accept a chat question with the same local attachments and speech input as AI-Annahme. */
    public function submitChat(): void
    {
        $actor = $this->actor();
        $lock = Cache::lock('operations:assist:'.session()->getId().':'.$actor->id, 60);
        abort_unless($lock->get(), 409, 'Der Assistent bearbeitet gerade eine Aktion. Bitte kurz warten.');
        try {
            $this->resetValidation(['prompt', 'uploads', 'uploads.*', 'audioUpload']);
            $hasAttachment = $this->uploads !== [] || $this->audioUpload !== null;
            $this->validate([
                'prompt' => [$hasAttachment ? 'nullable' : 'required', 'string', 'max:500'],
                'uploads' => ['array', 'max:3'],
                'uploads.*' => ['file', 'max:10240'],
                'audioUpload' => ['nullable', 'file', 'max:8192'],
            ]);
            if ($this->uploads !== []) {
                app(AssistantAttachmentProcessor::class)->validate($this->uploads);
            }

            $text = trim($this->prompt);
            if ($this->audioUpload) {
                $transcription = app(AssistantSpeechRouter::class)->transcribe($this->audioUpload, 'de');
                $transcript = trim((string) ($transcription['text'] ?? ''));
                if ($transcript === '' || ! mb_check_encoding($transcript, 'UTF-8')) {
                    throw ValidationException::withMessages(['audioUpload' => 'Die Sprachaufnahme enthielt keinen verwendbaren Text. Bitte erneut aufnehmen.']);
                }
                $text = trim($text.($text === '' ? '' : "\n").$transcript);
            }
            if (mb_strlen($text) > 500) {
                throw ValidationException::withMessages(['prompt' => 'Die Nachricht darf höchstens 500 Zeichen enthalten. Bitte kürzen.']);
            }
            if ($text === '' && $this->uploads === []) {
                throw ValidationException::withMessages(['prompt' => 'Bitte eine Frage eingeben oder eine Datei anhängen.']);
            }

            $attachmentMetadata = array_map(
                static fn ($file): array => [
                    'name' => $file->getClientOriginalName(),
                    'size' => (int) $file->getSize(),
                ],
                $this->uploads,
            );
            if ($this->audioUpload instanceof TemporaryUploadedFile) {
                $attachmentMetadata[] = [
                    'name' => $this->audioUpload->getClientOriginalName(),
                    'size' => (int) $this->audioUpload->getSize(),
                    'audio' => true,
                ];
            }
            $this->answerText($text, $actor, $attachmentMetadata);
            $this->prompt = '';
            $this->discardChatUploads();
            $this->dispatch('ai-intake-composer-submitted', componentId: $this->getId());
        } catch (AssistantAttachmentException $error) {
            $this->addError('uploads', $error->userMessage);
        } catch (AssistantSpeechException $error) {
            $this->addError('audioUpload', 'Die Sprachaufnahme konnte nicht verarbeitet werden. Bitte erneut versuchen oder Text eingeben.');
        } catch (Throwable $error) {
            if ($error instanceof ValidationException) {
                throw $error;
            }
            Log::warning('AI Assist chat attachment processing failed.', [
                'exception_class' => str_contains($error::class, '@anonymous') ? (get_parent_class($error) ?: Throwable::class) : $error::class,
                'actor_id' => $actor->id,
            ]);
            $this->addError('assistant', 'Die Nachricht konnte gerade nicht verarbeitet werden. Bitte erneut versuchen.');
        } finally {
            $lock->release();
        }
    }

    public function updatedUploads(): void
    {
        $this->actor();
        $this->resetValidation(['uploads', 'uploads.*']);
        $this->validate(['uploads' => ['array', 'max:3'], 'uploads.*' => ['file', 'max:10240']]);
        try {
            app(AssistantAttachmentProcessor::class)->validate($this->uploads);
        } catch (AssistantAttachmentException $error) {
            $this->addError('uploads', $error->userMessage);
        }
    }

    public function updatedAudioUpload(): void
    {
        $this->actor();
        $this->resetValidation('audioUpload');
        $this->validate(['audioUpload' => ['nullable', 'file', 'max:8192']]);
    }

    public function removeUpload(int $index): void
    {
        $this->actor();
        if (! isset($this->uploads[$index])) {
            return;
        }
        $file = $this->uploads[$index];
        if (! $file instanceof TemporaryUploadedFile || ! $file->delete()) {
            $this->addError('uploads', 'Die temporäre Datei konnte nicht entfernt werden. Bitte erneut versuchen.');

            return;
        }
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
        $this->resetValidation(['uploads', 'uploads.*']);
    }

    public function removeAudio(): void
    {
        $this->actor();
        if ($this->audioUpload instanceof TemporaryUploadedFile && ! $this->audioUpload->delete()) {
            $this->addError('audioUpload', 'Die temporäre Aufnahme konnte nicht entfernt werden. Bitte erneut entfernen.');

            return;
        }
        $this->audioUpload = null;
        $this->resetValidation('audioUpload');
        $this->dispatch('ai-intake-composer-audio-removed', componentId: $this->getId());
    }

    private function answerText(string $text, User $actor, array $attachments = []): void
    {
        $emptyQuestion = $text === '';
        if ($text === '') {
            if ($attachments === []) {
                return;
            }
            $text = 'Datei angehängt';
        }
        $this->consumeAssistRate($actor);
        $service = app(AiAssistService::class);
        $this->loaded = true;
        $this->setTab('chat');
        $requestedAction = null;
        if (preg_match('/^\/([a-z][a-z0-9-]*)$/i', $text, $match)) {
            $candidate = strtolower($match[1]);
            if (in_array($candidate, $service->permitted($actor), true)) {
                $requestedAction = $candidate;
            }
        }
        $this->push([
            'role' => 'user',
            'text' => $requestedAction ? $service->title($requestedAction) : $text,
            'attachments' => $attachments,
        ]);
        if ($attachments !== [] && $emptyQuestion) {
            $this->push(['role' => 'assistant', 'action' => null, 'lead' => 'Dieser AI-Assist beantwortet Fragen anhand der aktuellen Dispositionsdaten. Er wertet Dateiinhalte nicht aus. Stelle bitte eine konkrete Frage oder nutze AI-Annahme für eine inhaltliche Datei-Prüfung.']);

            return;
        }
        $action = $requestedAction ?? $service->route($text);
        $known = $action && in_array($action, $service->permitted($actor), true);
        $this->push(['role' => 'assistant', 'action' => $known ? $action : null] + ($known ? $service->answer($action, $actor, $this->context()) : $service->help()));
    }

    private function discardChatUploads(): void
    {
        $remaining = [];
        foreach ($this->uploads as $file) {
            if (! $file instanceof TemporaryUploadedFile || ! $file->delete()) {
                $remaining[] = $file;
                $this->addError('uploads', 'Eine temporäre Datei konnte nicht entfernt werden. Bitte erneut entfernen.');
            }
        }
        $this->uploads = $remaining;
        if ($this->audioUpload instanceof TemporaryUploadedFile && ! $this->audioUpload->delete()) {
            $this->addError('audioUpload', 'Die temporäre Aufnahme konnte nicht entfernt werden. Bitte erneut entfernen.');
        } else {
            $this->audioUpload = null;
        }
    }

    /** Karten-Aktion auf eine eigene Nachricht: apply | undo | dismiss | tab:… */
    public function act(int $messageId, string $act): void
    {
        $actor = $this->actor();
        $lock = Cache::lock('operations:assist:'.session()->getId().':'.$actor->id, 60);
        abort_unless($lock->get(), 409, 'Der Assistent bearbeitet gerade eine Aktion. Bitte kurz warten.');
        try {
            $this->actCard($messageId, $act, $actor);
        } finally {
            $lock->release();
        }
    }

    private function actCard(int $messageId, string $act, User $actor): void
    {
        $messages = $this->conversation();
        $index = collect($messages)->search(fn (array $message) => ($message['id'] ?? null) === $messageId && ($message['role'] ?? '') === 'assistant');
        abort_if($index === false || empty($messages[$index]['card']), 404);
        $card = $messages[$index]['card'];
        abort_if($act === 'undo' && in_array($card['state'] ?? '', ['dismissed', 'undone'], true), 409);
        $allowed = collect($card['buttons'] ?? [])->pluck('act')->filter()->all();
        $allowed[] = 'dismiss';
        if (($card['state'] ?? '') === 'done' && ! empty($card['undo'])) {
            $allowed[] = 'undo';
        }
        abort_unless(in_array($act, $allowed, true), 422);
        if (str_starts_with($act, 'tab:')) {
            $this->setTab(substr($act, 4));

            return;
        }
        if (str_starts_with($act, 'run:')) {
            $this->runAction(substr($act, 4), $actor);

            return;
        }
        abort_unless(in_array($act, ['apply', 'undo', 'dismiss', 'approve', 'reject', 'retry', 'reanalyze', 'prepare'], true), 422);
        if (in_array($act, ['apply', 'approve', 'retry', 'reanalyze', 'prepare'], true)) {
            abort_unless(($messages[$index]['created_at'] ?? now()->getTimestamp()) >= now()->subMinutes(10)->getTimestamp(), 409, 'Vorschlag ist abgelaufen. Bitte erneut auswerten.');
        }
        abort_if(in_array($card['state'] ?? null, ['done', 'dismissed', 'undone'], true) && $act !== 'undo', 409);
        $service = app(AiAssistService::class);
        if ($act === 'apply') {
            abort_if(empty($card['payload']), 422);
            $result = $service->apply($card['payload'], $actor);
            $card['state'] = $result['done'] === [] ? ($card['state'] ?? null) : 'done';
            $card['done_at'] = now()->setTimezone((string) config('operations.display_timezone', 'Europe/Berlin'))->format('H:i');
            $card['undo'] = array_column($result['done'], 'assignment');
            $card['failed'] = $result['failed'];
            if ($result['done'] !== []) {
                $this->dispatch('operations-plan-changed');
                $this->dispatch('swal:toast', type: 'success', text: count($result['done']) === 1 ? 'Als Angefragt eingeteilt.' : count($result['done']).' Besetzungen als Angefragt eingeteilt.');
            } else {
                $this->dispatch('swal:toast', type: 'warning', text: 'Kein Vorschlag konnte übernommen werden.');
            }
        } elseif (in_array($act, ['approve', 'reject', 'retry', 'reanalyze', 'prepare'], true)) {
            abort_if(empty($card['command']), 422);
            try {
                $card = array_replace($card, $service->executeCommand($card['command'], $act, $actor));
                unset($card['command']);
                $card['done_at'] = now()->setTimezone(config('operations.display_timezone', 'Europe/Berlin'))->format('H:i');
            } catch (ValidationException $exception) {
                $card['failed'] = [collect($exception->errors())->flatten()->first() ?: 'Aktuelle Grundlage prüfen.'];
            } catch (HttpException $exception) {
                if (in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }
                $card['failed'] = [$exception->getMessage() ?: 'Aktuelle Grundlage prüfen.'];
            }
        } elseif ($act === 'undo') {
            abort_unless(($card['state'] ?? null) === 'done' && ! empty($card['undo']), 409);
            $count = $service->undo($card['undo'], $actor);
            $card['state'] = 'undone';
            $card['undo'] = [];
            $this->dispatch('operations-plan-changed');
            $this->dispatch('swal:toast', type: 'success', text: $count === 1 ? 'Einteilung zurückgenommen.' : $count.' Einteilungen zurückgenommen.');
        } else {
            $card['state'] = 'dismissed';
        }
        $messages[$index]['card'] = $card;
        session([self::SESSION => $messages]);
    }

    public function resetConversation(): void
    {
        $actor = $this->actor();
        $lock = Cache::lock('operations:assist:'.session()->getId().':'.$actor->id, 60);
        abort_unless($lock->get(), 409, 'Der Assistent bearbeitet gerade eine Aktion. Bitte kurz warten.');
        try {
            session()->forget(self::SESSION);
            $this->setTab('chat');
        } finally {
            $lock->release();
        }
    }

    public function openSettings(): void
    {
        $actor = $this->actor();
        abort_unless($actor->isSuperAdmin(), 403);
        $values = AiDispositionSettings::forForm();
        $this->config = ['automation_mode' => $values['automation_mode'], 'max_rounds' => (int) $values['max_rounds'],
            'reply_timeout_hours' => (int) $values['reply_timeout_hours'], 'max_ai_calls_per_hour' => (int) $values['max_ai_calls_per_hour'],
            'expected_revision' => (int) $values['expected_revision']];
    }

    public function saveSettings(): void
    {
        $actor = $this->actor();
        abort_unless($actor->isSuperAdmin(), 403);
        $this->validate([
            'config.automation_mode' => ['required', Rule::in(['assisted', 'automatic'])],
            'config.max_rounds' => ['required', 'integer', 'between:1,2'],
            'config.reply_timeout_hours' => ['required', 'integer', Rule::in([24, 48, 72])],
            'config.max_ai_calls_per_hour' => ['required', 'integer', Rule::in([50, 100, 250])],
            'config.expected_revision' => ['required', 'integer'],
        ]);
        try {
            AiDispositionSettings::save($this->config, $actor);
        } catch (HttpException $exception) {
            $this->addError('config', $exception->getMessage() ?: 'Einstellungen konnten nicht gespeichert werden.');

            return;
        } catch (ValidationException $exception) {
            $this->addError('config', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->openSettings();
        $this->dispatch('swal:toast', type: 'success', text: 'Einstellung gespeichert.');
    }

    private function push(array $message): void
    {
        $messages = $this->conversation();
        $message['id'] = (int) collect($messages)->max('id') + 1;
        $message['time'] = CarbonImmutable::now((string) config('operations.display_timezone', 'Europe/Berlin'))->format('H:i');
        $actor = $this->actor();
        $message['actor_id'] = $actor->id;
        $message['created_at'] = now()->getTimestamp();
        if (! empty($message['action'])) {
            $message['abilities'] = app(AiAssistService::class)->requiredAbilities($message['action'], $actor);
        }
        $messages[] = $message;
        session([self::SESSION => array_slice($messages, -self::MAX_MESSAGES)]);
    }

    /** @return list<array> */
    private function conversation(): array
    {
        $messages = session(self::SESSION, []);
        $actor = auth()->user()?->fresh();

        return is_array($messages) && $actor?->isActive() ? array_values(array_filter($messages, static fn ($message) => is_array($message)
            && (! isset($message['actor_id']) || $message['actor_id'] === $actor->id)
            && ! collect($message['abilities'] ?? [])->contains(fn ($ability) => ! in_array($ability, ['operations.manage', 'operations.inquiries.manage'], true) || ! $actor->can($ability)))) : [];
    }

    private function context(): array
    {
        return ['page' => $this->page, 'label' => $this->pageLabel, 'from' => $this->from, 'until' => $this->until];
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && $actor->isActive(), 403);
        abort_unless($actor->can('operations.manage') || $actor->can('operations.inquiries.manage'), 403);
        OperationsAccess::requireReady();

        return $actor;
    }

    private function consumeAssistRate(User $actor): void
    {
        $key = 'operations:assist:minute:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, max(1, (int) config('assistant.chat_limits.user_per_minute', 6))), 429, 'Zu viele Auswertungen. Bitte kurz warten.');
        RateLimiter::hit($key, 60);
    }

    public function render()
    {
        $actor = $this->actor();
        $service = app(AiAssistService::class);
        $settings = AiDispositionSettings::all();
        $loaded = $this->loaded;

        return view('livewire.operations.ai-assist', [
            'actions' => $service->actions($actor, $this->context()),
            'hints' => $service->hints($actor),
            'reviewCount' => $service->reviewCount($actor),
            'messages' => $this->conversation(),
            'overview' => $loaded ? $service->overview($actor) : null,
            'automation' => $loaded ? $service->automationOverview($actor) : null,
            'intakesReady' => $actor->can('operations.inquiries.manage') && AiIntakeSchema::ready(),
            'activity' => $loaded && $this->tab === 'activity' ? $service->activity($actor, $this->activityFilter) : collect(),
            'status' => AiDispositionSettings::status(),
            'values' => array_intersect_key($settings, array_flip(['max_rounds', 'reply_timeout_hours', 'max_ai_calls_per_hour'])),
            'enabled' => (bool) $settings['enabled'],
            'mode' => $settings['automation_mode'],
            'supervisor' => AiDispositionSettings::supervisor($settings)?->name,
            'canConfigure' => $actor->isSuperAdmin(),
            'labels' => AiIntakeInbox::LABELS,
            'service' => $service,
        ]);
    }
}
