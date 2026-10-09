<?php

namespace App\Livewire\Operations;

use App\Models\User;
use App\Services\Operations\AiAssistService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AI-Assist: übergreifender Disposition-Assistent (Chat, Eingänge, Aktionen, Aktivitäten) im
 * Erscheinungsbild des RailTime-Chatbots. Der Verlauf liegt serverseitig in der Sitzung, damit
 * Karten-Aktionen (Übernehmen, Zurücknehmen) nur auf selbst erzeugte Vorschläge wirken.
 */
class AiAssist extends Component
{
    private const SESSION = 'operations.ai_assist.messages';

    private const MAX_MESSAGES = 24;

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

    public string $intakeSearch = '';

    public string $intakeStatus = 'all';

    public string $activityFilter = 'all';

    public array $config = [];

    public function mount(string $page = '', string $view = '', string $section = ''): void
    {
        $this->actor();
        $context = app(AiAssistService::class)->context($page, $view, $section);
        [$this->page, $this->pageLabel, $this->from, $this->until] = [$context['page'], $context['label'], $context['from'], $context['until']];
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
        $this->tab = 'chat';
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
        $text = trim(mb_substr($text, 0, 500));
        if ($text === '') {
            return;
        }
        $this->consumeAssistRate($actor);
        $service = app(AiAssistService::class);
        $this->loaded = true;
        $this->tab = 'chat';
        $this->push(['role' => 'user', 'text' => $text]);
        $action = $service->route($text);
        $known = $action && in_array($action, $service->permitted($actor), true);
        $this->push(['role' => 'assistant', 'action' => $known ? $action : null] + ($known ? $service->answer($action, $actor, $this->context()) : $service->help()));
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
            $this->tab = 'chat';
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
            'intakes' => $loaded && $this->tab === 'intake' ? $service->intakes($actor, $this->intakeSearch, $this->intakeStatus) : collect(),
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
