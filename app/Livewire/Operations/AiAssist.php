<?php

namespace App\Livewire\Operations;

use App\Models\User;
use App\Services\Operations\AiAssistService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
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

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, ['chat', 'intake', 'actions', 'activity'], true), 422);
        $this->tab = $tab;
        $this->loaded = true;
    }

    public function run(string $action): void
    {
        $actor = $this->actor();
        $service = app(AiAssistService::class);
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
        $text = trim(mb_substr($text, 0, 500));
        if ($text === '') {
            return;
        }
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
        $messages = $this->conversation();
        $index = collect($messages)->search(fn (array $message) => ($message['id'] ?? null) === $messageId && ($message['role'] ?? '') === 'assistant');
        abort_if($index === false || empty($messages[$index]['card']), 404);
        $card = $messages[$index]['card'];
        if (str_starts_with($act, 'tab:')) {
            $this->setTab(substr($act, 4));

            return;
        }
        abort_unless(in_array($act, ['apply', 'undo', 'dismiss'], true), 422);
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
        $this->actor();
        session()->forget(self::SESSION);
        $this->tab = 'chat';
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
        $messages[] = $message;
        session([self::SESSION => array_slice($messages, -self::MAX_MESSAGES)]);
    }

    /** @return list<array> */
    private function conversation(): array
    {
        $messages = session(self::SESSION, []);

        return is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
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
