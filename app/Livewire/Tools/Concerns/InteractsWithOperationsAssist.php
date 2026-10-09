<?php

namespace App\Livewire\Tools\Concerns;

use App\Livewire\Operations\AiIntakeInbox;
use App\Models\User;
use App\Services\Operations\AiAssistService;
use App\Services\Operations\AiIntakeService;
use App\Support\Ai\AssistantAccess;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Local operations cards use the authoritative chat, without entering AI or speech provider context. */
trait InteractsWithOperationsAssist
{
    public string $operationsTab = 'chat';

    #[Locked]
    public bool $operationsLoaded = false;

    #[Locked]
    public array $operationsContext = [];

    public string $operationsIntakeSearch = '';

    public string $operationsIntakeStatus = 'all';

    public string $operationsActivityFilter = 'all';

    private function initializeOperationsAssistContext(): void
    {
        $page = (string) (request()->route()?->parameter('page') ?? '');
        $this->operationsContext = app(AiAssistService::class)->context($page, (string) request()->query('view', ''), (string) request()->query('section', ''));
    }

    #[On('operations-period-changed')]
    public function setOperationsPeriod(string $from, string $until): void
    {
        $this->operationsActor();
        $context = app(AiAssistService::class)->context('cases', 'shifts', 'plan', $from, $until);
        $this->operationsContext = array_replace($this->operationsContext, array_intersect_key($context, array_flip(['from', 'until'])));
    }

    #[On('operations-assistant-context-changed')]
    public function updateOperationsContext(string $page, string $view = '', string $section = ''): void
    {
        $actor = $this->operationsActor();
        abort_unless(isset(OperationsPages::definitions()[$page]) && OperationsPages::views($actor, $page), 403);
        $this->operationsContext = app(AiAssistService::class)->context($page, $view, $section, $this->operationsContext['from'] ?? null, $this->operationsContext['until'] ?? null);
    }

    public function loadOperationsAssist(): void
    {
        $this->operationsActor();
        $this->operationsLoaded = true;
    }

    public function setOperationsTab(string $tab): void
    {
        $actor = $this->operationsActor();
        abort_unless(in_array($tab, ['chat', 'intake', 'actions', 'activity'], true), 422);
        if ($tab === 'intake') {
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            AiIntakeSchema::requireReady();
        }
        $this->operationsTab = $tab;
        $this->operationsLoaded = true;
    }

    #[On('operations-assistant-action')]
    public function runOperationsAction(string $action): void
    {
        $actor = $this->operationsActor();
        $this->withOperationsLock(function () use ($actor, $action): void {
            $this->performOperationsAction($actor, $action);
        });
    }

    /** Only unambiguous operations questions use the local service; generic chat remains unchanged. */
    private function routeOperationsMessage(string $input): bool
    {
        if (! $this->operationsAvailable()) {
            return false;
        }
        $text = mb_strtolower(trim($input));
        $subject = preg_match('/\b(?:schicht(?:en|plan)?|besetzung(?:en)?|dienst(?:e)?|auslastung|tageslage|ai[ -]eing[aä]ng\w*|ki[ -]eing[aä]ng\w*|anfragen|auftr[aä]ge|leistungen|kundennachrichten|postausgang|personalautomatik|prüffälle)\b/u', $text);
        $request = preg_match('/\b(?:zeige|prüfe|analysiere|fasse|zusammenfassen|vorschlagen|schlage|wer\s+kann|welche|wie\s+viele|offene\s+(?:schichten|dienste)|ohne\s+schichten|aktuelle\s+tageslage)\b/u', $text);
        $explicit = preg_match('/^(?:\/dispo(?:sition)?\b|(?:ai|ki)[ -]assist\b)/u', $text) || ($subject && $request);
        if (! $explicit) {
            return false;
        }
        $action = app(AiAssistService::class)->route($text);
        if (! $action) {
            return false;
        }
        $actor = $this->operationsActor();
        if (! in_array($action, app(AiAssistService::class)->permitted($actor), true)) {
            return false;
        }
        $this->withOperationsLock(function () use ($actor, $action, $input): void {
            if ($this->performOperationsAction($actor, $action, $input)) {
                $this->message = '';
            }
        });

        return true;
    }

    private function performOperationsAction(User $actor, string $action, ?string $input = null): bool
    {
        if (! $this->consumeRateLimit($actor)) {
            $this->addError('message', 'Zu viele Anfragen in kurzer Zeit. Bitte versuche es später noch einmal.');

            return false;
        }
        $service = app(AiAssistService::class);
        $answer = $service->answer($action, $actor, $this->operationsContext ?: $service->context(''));
        $abilities = $service->requiredAbilities($action, $actor);
        $ability = $abilities[0];
        $this->operationsLoaded = true;
        $this->operationsTab = 'chat';
        $this->appendHistory('user', $input ?? $service->title($action), localOnly: true, localAbility: $ability);
        $entry = $this->appendHistory('assistant', (string) ($answer['lead'] ?? ''), localOnly: true, localAbility: $ability);
        $this->chatHistory[array_key_last($this->chatHistory)]['local_abilities'] = $abilities;
        if (is_array($answer['card'] ?? null)) {
            $records = $this->operationsCardRecords($actor);
            $records[$entry['key']] = ['actor_id' => $actor->id, 'ability' => $ability, 'abilities' => $abilities, 'created_at' => now()->getTimestamp(), 'card' => ['state' => 'open'] + $answer['card']];
            $this->chatHistory[array_key_last($this->chatHistory)]['operations_card'] = $entry['key'];
            $this->persistHistory();
            $this->storeOperationsCardRecords($actor, $records);
        }
        $this->persistHistory();
        $this->dispatch('operations-assist-reply', key: $entry['key'], localOnly: true);

        return true;
    }

    public function actOperationsCard(string $messageUuid, string $act): void
    {
        $actor = $this->operationsActor();
        abort_unless(Str::isUuid($messageUuid) && (in_array($act, ['apply', 'undo', 'dismiss', 'approve', 'reject', 'retry', 'reanalyze', 'prepare', 'tab:intake', 'tab:actions', 'tab:activity'], true) || preg_match('/^run:[a-z-]+$/', $act)), 422);
        $this->withOperationsLock(function () use ($actor, $messageUuid, $act): void {
            $entry = collect($this->chatHistory)->first(fn ($entry) => ($entry['key'] ?? '') === $messageUuid && ($entry['operations_card'] ?? '') === $messageUuid && ! empty($entry['local_only']));
            $records = $this->operationsCardRecords($actor);
            $record = $records[$messageUuid] ?? null;
            abort_unless($entry && $record && $record['actor_id'] === $actor->id, 404);
            if (isset($record['ability'])) {
                OperationsAccess::authorize($actor, $record['ability']);
            }
            foreach ($record['abilities'] ?? [] as $ability) {
                OperationsAccess::authorize($actor, $ability);
            }
            if (in_array($act, ['apply', 'approve', 'retry', 'reanalyze', 'prepare'], true)) {
                abort_unless($record['created_at'] >= now()->subMinutes(10)->getTimestamp(), 409, 'Vorschlag ist abgelaufen. Bitte erneut auswerten.');
            }
            $card = $record['card'];
            $allowed = collect($card['buttons'] ?? [])->pluck('act')->filter()->all();
            if (($card['state'] ?? '') === 'done' && ! empty($card['undo'])) {
                $allowed[] = 'undo';
            }
            $allowed[] = 'dismiss';
            abort_unless(in_array($act, $allowed, true), 403);
            if (str_starts_with($act, 'tab:')) {
                $this->setOperationsTab(substr($act, 4));

                return;
            }
            if (str_starts_with($act, 'run:')) {
                $this->performOperationsAction($actor, substr($act, 4));

                return;
            }
            abort_if(in_array($card['state'] ?? '', ['done', 'undone', 'dismissed'], true) && $act !== 'undo', 409);
            $service = app(AiAssistService::class);
            if ($act === 'apply') {
                abort_if(empty($card['payload']), 422);
                $result = $service->apply($card['payload'], $actor);
                $card['state'] = $result['done'] === [] ? ($card['state'] ?? 'pending') : 'done';
                $card['undo'] = array_column($result['done'], 'assignment');
                $card['failed'] = $result['failed'];
                $card['done_at'] = now()->setTimezone(config('operations.display_timezone', 'Europe/Berlin'))->format('H:i');
                if ($result['done'] !== []) {
                    $this->dispatch('operations-plan-changed');
                }
            } elseif (in_array($act, ['approve', 'reject', 'retry', 'reanalyze', 'prepare'], true)) {
                abort_if(empty($card['command']), 422);
                $card = array_replace($card, $service->executeCommand($card['command'], $act, $actor));
                unset($card['command']);
                $card['done_at'] = now()->setTimezone(config('operations.display_timezone', 'Europe/Berlin'))->format('H:i');
            } elseif ($act === 'undo') {
                abort_unless(($card['state'] ?? '') === 'done' && ! empty($card['undo']), 409);
                $service->undo($card['undo'], $actor);
                $card['state'] = 'undone';
                $card['undo'] = [];
                $this->dispatch('operations-plan-changed');
            } else {
                $card['state'] = 'dismissed';
            }
            $records[$messageUuid]['card'] = $card;
            $this->storeOperationsCardRecords($actor, $records);
        });
    }

    /** Explicit capture preserves originals before the chat's temporary-file cleanup. */
    private function validateOperationsIntakeAttachments(): void
    {
        OperationsAccess::authorize($this->operationsActor(), 'operations.inquiries.manage');
        AiIntakeSchema::requireReady();
        $this->validate(['attachments' => ['array', 'max:3'], 'attachments.*' => ['file', 'max:10240']]);
    }

    /** Explicit capture preserves originals before the chat's temporary-file cleanup. */
    public function submitOperationsIntake(): void
    {
        $actor = $this->operationsActor();
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        AiIntakeSchema::requireReady();
        $this->withOperationsLock(function () use ($actor): void {
            if (! $this->consumeRateLimit($actor)) {
                $this->addError('message', 'Zu viele Anfragen in kurzer Zeit. Bitte versuche es später noch einmal.');

                return;
            }
            $intake = app(AiIntakeService::class)->submit($actor, $this->message, $this->attachments, ['timezone' => 'Europe/Berlin']);
            $this->appendHistory('user', trim($this->message) ?: 'Anlagen ausdrücklich als Anfrage erfassen.', localOnly: true, localAbility: 'operations.inquiries.manage');
            $entry = $this->appendHistory('assistant', 'Anfrage #'.$intake->id.' wurde im AI-Eingang gespeichert. '.(AiDispositionSettings::enabled() ? 'Die Auswertung wurde zur Verarbeitung im Hintergrund vorgemerkt.' : 'Die automatische Verarbeitung ist ausgeschaltet; der Eingang bleibt zur Prüfung erhalten.'), localOnly: true, localAbility: 'operations.inquiries.manage');
            $card = ['state' => 'open', 'icon' => 'fa-inbox', 'title' => 'Anfrage erfasst', 'rows' => [], 'buttons' => [['label' => 'Eingang prüfen', 'href' => app(AiAssistService::class)->intakeUrl($intake), 'primary' => true]]];
            $records = $this->operationsCardRecords($actor);
            $records[$entry['key']] = ['actor_id' => $actor->id, 'ability' => 'operations.inquiries.manage', 'created_at' => now()->getTimestamp(), 'card' => $card];
            $this->chatHistory[array_key_last($this->chatHistory)]['operations_card'] = $entry['key'];
            $this->persistHistory();
            $this->storeOperationsCardRecords($actor, $records);
            $this->message = '';
            $this->operationsLoaded = true;
            $this->operationsTab = 'chat';
            if ($this->cleanupAttachments() > 0) {
                $this->addError('attachments', 'Die Anfrage ist gespeichert; eine temporäre Anlage konnte noch nicht entfernt werden.');
            }
            $this->dispatch('operations-assist-reply', key: $entry['key'], localOnly: true);
        });
    }

    private function withOperationsLock(callable $action): void
    {
        $lock = $this->conversationLock();
        if (! $lock->get()) {
            $this->addError('message', 'Eine andere Anfrage dieses Chats läuft bereits. Bitte warte kurz.');

            return;
        }
        try {
            $this->loadHistory();
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $field = $field === 'text' ? 'message' : (preg_replace('/^uploads(?=\.|$)/', 'attachments', $field));
                $this->addError($field, implode(' ', $messages));
            }
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }
            $this->addError('message', $exception->getMessage() ?: 'Aktuellen Vorgang prüfen und erneut versuchen.');
        } finally {
            $lock->release();
        }
    }

    private function operationsAvailable(): bool
    {
        $actor = auth()->user()?->fresh();

        return $actor instanceof User && $actor->isActive() && ($actor->can('operations.manage') || $actor->can('operations.inquiries.manage')) && OperationsAccess::ready();
    }

    private function operationsActor(): User
    {
        $actor = auth()->user()?->fresh();
        app(AssistantAccess::class)->authorize($actor);
        abort_unless($actor && ($actor->can('operations.manage') || $actor->can('operations.inquiries.manage')), 403);
        OperationsAccess::requireReady();

        return $actor;
    }

    private function operationsAssistData(): array
    {
        $available = $this->operationsAvailable();
        $data = ['available' => $available, 'loaded' => $this->operationsLoaded, 'context' => $this->operationsContext, 'actions' => ['page' => [], 'global' => []], 'intakes' => collect(), 'activity' => collect(), 'reviewCount' => 0, 'overview' => [], 'status' => [], 'enabled' => false, 'mode' => 'assisted', 'labels' => AiIntakeInbox::LABELS, 'fieldLabels' => AiIntakeInbox::FIELD_LABELS, 'limits' => []];
        if (! $available || ! $this->operationsLoaded) {
            return $data;
        }
        abort_unless(in_array($this->operationsTab, ['chat', 'intake', 'actions', 'activity'], true), 422);
        $actor = $this->operationsActor();
        $service = app(AiAssistService::class);
        $settings = AiDispositionSettings::all();
        $data['actions'] = $service->actions($actor, $this->operationsContext);
        $data['reviewCount'] = $service->reviewCount($actor);
        $data['overview'] = $service->overview($actor);
        $data['automation'] = $service->automationOverview($actor);
        $data['status'] = AiDispositionSettings::status();
        $data['enabled'] = (bool) $settings['enabled'];
        $data['mode'] = $settings['automation_mode'];
        $data['limits'] = array_intersect_key($settings, array_flip(['max_attachment_count', 'max_total_kilobytes', 'max_audio_kilobytes']));
        if ($this->operationsTab === 'intake') {
            $data['intakes'] = $service->intakes($actor, $this->operationsIntakeSearch, $this->operationsIntakeStatus);
        }
        if (in_array($this->operationsTab, ['chat', 'activity'], true)) {
            $data['activity'] = $service->activity($actor, $this->operationsActivityFilter);
        }

        return $data;
    }

    private function operationsDisplayCards(): array
    {
        if (! $this->operationsAvailable()) {
            return [];
        }
        $actor = $this->operationsActor();
        $cards = [];
        foreach ($this->operationsCardRecords($actor) as $key => $record) {
            if ($record['actor_id'] !== $actor->id || (isset($record['ability']) && ! $actor->can($record['ability'])) || collect($record['abilities'] ?? [])->contains(fn ($ability) => ! $actor->can($ability))) {
                continue;
            }
            $card = $record['card'];
            $card['canUndo'] = ($card['state'] ?? '') === 'done' && ! empty($card['undo']);
            unset($card['payload'], $card['undo'], $card['command']);
            $card['expired'] = $record['created_at'] < now()->subMinutes(10)->getTimestamp();
            $cards[$key] = $card;
        }

        return $cards;
    }

    private function authorizedOperationsHistory(array $history): array
    {
        if (! collect($history)->contains(fn ($entry) => is_array($entry) && (! empty($entry['local_only']) || isset($entry['operations_card'])))) {
            return $history;
        }
        $actor = auth()->user()?->fresh();
        $available = $this->operationsAvailable();

        return array_values(array_filter($history, static function ($entry) use ($actor, $available): bool {
            if (! is_array($entry)) {
                return false;
            }
            if (empty($entry['local_only']) && ! isset($entry['operations_card'])) {
                return true;
            }

            return $available && ! collect($entry['local_abilities'] ?? [])->contains(fn ($ability) => ! in_array($ability, ['operations.manage', 'operations.inquiries.manage'], true) || ! $actor->can($ability)) && (! isset($entry['local_ability']) || (in_array($entry['local_ability'], ['operations.manage', 'operations.inquiries.manage'], true) && $actor->can($entry['local_ability'])));
        }));
    }

    private function operationsCardRecords(User $actor): array
    {
        try {
            $encrypted = session()->get($this->operationsCardStoreKey($actor));
            $records = is_string($encrypted) ? json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR) : [];

            return is_array($records) ? $records : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function storeOperationsCardRecords(User $actor, array $records): void
    {
        $keys = collect($this->chatHistory)->pluck('key')->all();
        $records = array_intersect_key($records, array_flip($keys));
        session()->put($this->operationsCardStoreKey($actor), Crypt::encryptString(json_encode($records, JSON_THROW_ON_ERROR)));
    }

    private function forgetOperationsCards(User $actor): void
    {
        session()->forget($this->operationsCardStoreKey($actor));
    }

    private function operationsCardStoreKey(User $actor): string
    {
        return 'railtime_assistant_operations_cards_'.$actor->id;
    }
}
