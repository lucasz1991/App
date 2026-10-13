<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\User;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\DispositionMailboxService;
use App\Services\Operations\DispositionMailboxWorkflowService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DispositionMailbox extends Component
{
    public const TABS = ['inbox' => 'Posteingang', 'sent' => 'Gesendet', 'outbox' => 'Postausgang'];

    public bool $showMailbox = false;

    #[Locked]
    public string $tab = 'inbox';

    public string $search = '';

    #[Locked]
    public int $page = 1;

    #[Locked]
    public array $connectionState = [];

    #[Locked]
    public array $mailboxPage = [];

    #[Locked]
    public array $selectedMessage = [];

    #[Locked]
    public ?int $selectedIntakeId = null;

    #[Locked]
    public int $intakeRevision = 0;

    #[Locked]
    public array $guidedReview = [];

    #[Locked]
    public string $guidedStep = 'recognition';

    public string $customerSearch = '';

    public string $customerId = '';

    public string $contactId = '';

    public function mount(): void
    {
        $this->actor();
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        OperationsAccess::requireReady();

        return $actor;
    }

    private function mailbox(): DispositionMailboxService
    {
        return app(DispositionMailboxService::class);
    }

    private function workflow(): DispositionMailboxWorkflowService
    {
        return app(DispositionMailboxWorkflowService::class);
    }

    private function resetGuidedReview(): void
    {
        $this->reset('guidedReview', 'guidedStep', 'customerSearch', 'customerId', 'contactId');
    }

    #[On('open-disposition-mailbox')]
    public function open(): void
    {
        $actor = $this->actor();
        abort_unless($this->mailbox()->available($actor), 404);
        $this->resetValidation();
        $this->resetGuidedReview();
        $this->reset('selectedMessage', 'selectedIntakeId', 'mailboxPage', 'connectionState');
        $this->showMailbox = true;
        $this->perform(function () use ($actor): void {
            $this->connectionState = $this->mailbox()->connection($actor);
            if (($this->connectionState['connected'] ?? false) || $this->tab === 'outbox') {
                $this->loadPage($actor);
            } else {
                $this->mailboxPage = [];
            }
        });
    }

    public function close(): void
    {
        $this->actor();
        $this->resetGuidedReview();
        $this->showMailbox = false;
    }

    public function setTab(string $tab): void
    {
        $actor = $this->actor();
        abort_unless(array_key_exists($tab, self::TABS), 422);
        if (! $this->contextCurrent()) {
            return;
        }
        $this->tab = $tab;
        $this->resetGuidedReview();
        $this->reset('page', 'selectedMessage', 'mailboxPage');
        $this->perform(fn () => $this->loadPage($actor));
    }

    public function updatedSearch(): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        $this->reset('page', 'selectedMessage');
        $this->resetGuidedReview();
        if ($this->showMailbox) {
            $this->perform(fn () => $this->loadPage($actor));
        }
    }

    public function refresh(): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        $this->reset('selectedMessage');
        $this->resetGuidedReview();
        $this->perform(function () use ($actor): void {
            $this->connectionState = $this->mailbox()->connection($actor);
            if (($this->connectionState['connected'] ?? false) || $this->tab === 'outbox') {
                $this->loadPage($actor);
            } else {
                $this->mailboxPage = [];
            }
        });
    }

    public function previousPage(): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        if ($this->page <= 1) {
            return;
        }
        $this->page--;
        $this->resetGuidedReview();
        $this->reset('selectedMessage');
        $this->perform(fn () => $this->loadPage($actor));
    }

    public function nextPage(): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        $lastPage = max(1, (int) ceil(($this->mailboxPage['total'] ?? 0) / max(1, $this->mailboxPage['per_page'] ?? 25)));
        if ($this->page >= $lastPage) {
            return;
        }
        $this->page++;
        $this->resetGuidedReview();
        $this->reset('selectedMessage');
        $this->perform(fn () => $this->loadPage($actor));
    }

    private function loadPage(User $actor): void
    {
        if (! $this->contextCurrent()) {
            return;
        }
        abort_unless($this->mailbox()->available($actor), 404);
        $this->validate(['search' => ['string', 'max:180']]);
        $this->mailboxPage = [];
        if ($this->tab === 'outbox') {
            $deliveries = $this->mailbox()->deliveries($actor, $this->page, trim($this->search));
            if ($deliveries->currentPage() > $deliveries->lastPage()) {
                $this->page = max(1, $deliveries->lastPage());
                $deliveries = $this->mailbox()->deliveries($actor, $this->page, trim($this->search));
            }
            $this->mailboxPage = [
                'folder' => null, 'total' => $deliveries->total(), 'page' => $deliveries->currentPage(),
                'per_page' => $deliveries->perPage(), 'available' => true,
                'messages' => $deliveries->getCollection()->map(fn (AiIntakeDelivery $delivery) => [
                    'id' => $delivery->id, 'intake_id' => $delivery->intake_id,
                    'subject' => $delivery->subject ?: 'Kundennachricht', 'to' => $delivery->recipient_email,
                    'date' => ($delivery->sent_at ?? $delivery->created_at)?->toIso8601String(),
                    'status' => $delivery->status,
                    'status_label' => ($delivery->metadata['manual_mailbox_test'] ?? false) ? 'Testentwurf · ohne Versand' : (AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen'),
                    'test_only' => (bool) ($delivery->metadata['manual_mailbox_test'] ?? false),
                    'type_label' => AiIntakeDelivery::TYPE_LABELS[$delivery->message_type ?? 'clarification'] ?? 'Kundennachricht',
                ])->all(),
            ];
        } else {
            $this->mailboxPage = $this->tab === 'sent'
                ? $this->mailbox()->sent($actor, $this->page, trim($this->search))
                : $this->mailbox()->inbox($actor, $this->page, trim($this->search));
        }
        $this->page = max(1, (int) ($this->mailboxPage['page'] ?? $this->page));
    }

    public function selectMessage(int $uid): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        abort_unless(in_array($this->tab, ['inbox', 'sent'], true), 422);
        abort_unless(collect($this->mailboxPage['messages'] ?? [])->contains(fn ($message) => (int) ($message['uid'] ?? 0) === $uid), 404);
        $this->resetGuidedReview();
        $this->perform(function () use ($actor, $uid): void {
            $this->selectedMessage = $this->mailbox()->detail($actor, $this->mailboxPage['folder'], $uid, (int) $this->mailboxPage['uid_validity']);
            $this->selectedMessage['uid'] = $uid;
            $this->selectedMessage['folder'] = $this->mailboxPage['folder'];
            $this->selectedMessage['uid_validity'] = (int) $this->mailboxPage['uid_validity'];
        });
    }

    public function selectDelivery(int $id): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        abort_unless($this->tab === 'outbox', 422);
        $this->resetGuidedReview();
        $this->perform(fn () => $this->selectedMessage = $this->mailbox()->deliveryDetail($actor, $id));
    }

    public function clearSelection(): void
    {
        $this->actor();
        $this->resetGuidedReview();
        $this->reset('selectedMessage');
    }

    public function classifyMessage(string $classification): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        abort_unless($this->tab === 'inbox' && isset($this->selectedMessage['uid']), 422);
        abort_unless(in_array($classification, ['inquiry', 'not_inquiry', 'unreviewed'], true), 422);
        $this->resetGuidedReview();
        $this->perform(function () use ($actor, $classification): void {
            $result = $this->mailbox()->classify($actor, $this->selectedMessage['folder'], (int) $this->selectedMessage['uid'], (int) $this->selectedMessage['uid_validity'], $classification);
            $this->selectedMessage = array_replace($this->selectedMessage, $result);
            $this->dispatch('disposition-mailbox-updated');
            $this->loadPage($actor);
        });
    }

    public function startRecognition(): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        abort_unless($this->tab === 'inbox' && isset($this->selectedMessage['uid']), 422);
        $this->resetGuidedReview();
        $this->performGuided(function () use ($actor): void {
            $review = $this->workflow()->recognize(
                $actor, $this->selectedMessage['folder'], (int) $this->selectedMessage['uid'],
                (int) $this->selectedMessage['uid_validity'], (int) $this->connectionState['settings_revision'],
            );
            $this->syncGuidedReview($review);
            $this->customerId = $review['customer_id'] ? (string) $review['customer_id'] : '';
            $this->contactId = $review['contact_id'] ? (string) $review['contact_id'] : '';
            $this->guidedStep = 'recognition';
            $this->dispatch('disposition-mailbox-updated');
        });
    }

    private function guidedActor(): ?User
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return null;
        }
        abort_unless($this->tab === 'inbox' && $this->guidedReview !== []
            && (int) ($this->guidedReview['uid'] ?? 0) === (int) ($this->selectedMessage['uid'] ?? -1)
            && (int) ($this->guidedReview['uid_validity'] ?? 0) === (int) ($this->selectedMessage['uid_validity'] ?? -1)
            && ($this->guidedReview['folder'] ?? null) === ($this->selectedMessage['folder'] ?? '')
            && ($this->guidedReview['mailbox_id'] ?? null) === ($this->connectionState['mailbox_id'] ?? ''), 422);

        return $actor;
    }

    private function syncGuidedReview(array $review): void
    {
        $this->guidedReview = $review;
        $summary = array_intersect_key($review, array_flip(['intake_id', 'classification', 'classification_label', 'intake_status']));
        $this->selectedMessage = array_replace($this->selectedMessage, $summary);
        foreach ($this->mailboxPage['messages'] ?? [] as $index => $message) {
            if ((int) ($message['uid'] ?? 0) === (int) $this->selectedMessage['uid']) {
                $this->mailboxPage['messages'][$index] = array_replace($message, $summary);
            }
        }
    }

    public function confirmGuidedClassification(bool $inquiry): void
    {
        if (! $actor = $this->guidedActor()) {
            return;
        }
        abort_unless($this->guidedStep === 'recognition', 422);
        $this->performGuided(function () use ($actor, $inquiry): void {
            $this->syncGuidedReview($this->workflow()->confirmClassification($actor, $this->guidedReview, $inquiry));
            $this->guidedStep = $inquiry ? ($this->guidedReview['customer_resolved'] ? 'actions' : 'customer') : 'done';
            $this->dispatch('disposition-mailbox-updated');
        });
    }

    public function updatedCustomerSearch(): void
    {
        if (! $this->guidedActor()) {
            return;
        }
        $this->validateOnly('customerSearch', ['customerSearch' => ['string', 'max:120']]);
    }

    public function updatedCustomerId(): void
    {
        if (! $this->guidedActor()) {
            return;
        }
        $this->validateOnly('customerId', ['customerId' => ['nullable', 'integer', 'min:1']]);
        $this->contactId = '';
    }

    public function confirmGuidedCustomer(): void
    {
        if (! $actor = $this->guidedActor()) {
            return;
        }
        abort_unless($this->guidedStep === 'customer', 422);
        $this->validate(['customerId' => ['required', 'integer', 'min:1'], 'contactId' => ['nullable', 'integer', 'min:1']]);
        $this->performGuided(function () use ($actor): void {
            $this->syncGuidedReview($this->workflow()->assignCustomer($actor, $this->guidedReview, (int) $this->customerId, $this->contactId === '' ? null : (int) $this->contactId));
            $this->guidedStep = 'actions';
            $this->dispatch('disposition-mailbox-updated');
        });
    }

    public function createGuidedInquiries(): void
    {
        if (! $actor = $this->guidedActor()) {
            return;
        }
        abort_unless($this->guidedStep === 'actions', 422);
        $this->performGuided(function () use ($actor): void {
            $this->syncGuidedReview($this->workflow()->createInquiries($actor, $this->guidedReview));
            $this->dispatch('ai-intake-submitted');
            $this->dispatch('disposition-mailbox-updated');
        });
    }

    public function prepareGuidedClarification(): void
    {
        if (! $actor = $this->guidedActor()) {
            return;
        }
        abort_unless($this->guidedStep === 'actions', 422);
        $this->performGuided(function () use ($actor): void {
            $this->syncGuidedReview($this->workflow()->prepareClarification($actor, $this->guidedReview));
            $this->dispatch('disposition-mailbox-updated');
        });
    }

    public function guidedBack(): void
    {
        if (! $actor = $this->guidedActor()) {
            return;
        }
        $this->performGuided(function () use ($actor): void {
            $this->syncGuidedReview($this->workflow()->refresh($actor, $this->guidedReview));
            $this->guidedStep = match ($this->guidedStep) {
                'actions' => 'customer', 'customer', 'done' => 'recognition', default => 'recognition',
            };
        });
    }

    public function closeGuidedReview(): void
    {
        $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        $this->resetGuidedReview();
        $this->resetValidation();
    }

    public function deferGuidedToNative(): void
    {
        if (! $actor = $this->guidedActor()) {
            return;
        }
        $this->performGuided(function () use ($actor): void {
            $this->syncGuidedReview($this->workflow()->refresh($actor, $this->guidedReview));
            $this->showIntake($this->mailbox()->linkedIntake($actor, (int) $this->guidedReview['intake_id']));
        });
    }

    public function importSelectedInquiry(): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        abort_unless($this->tab === 'inbox' && isset($this->selectedMessage['uid']), 422);
        $this->perform(function () use ($actor): void {
            $intake = $this->mailbox()->importInquiry($actor, $this->selectedMessage['folder'], (int) $this->selectedMessage['uid'], (int) $this->selectedMessage['uid_validity']);
            $this->selectedMessage['intake_id'] = $intake->id;
            $this->selectedMessage['classification'] = 'inquiry';
            $this->selectedMessage['classification_label'] = 'Anfrage';
            $this->dispatch('ai-intake-submitted');
            $this->showIntake($intake);
        });
    }

    public function openLinkedIntake(int $id): void
    {
        $actor = $this->actor();
        if (! $this->contextCurrent()) {
            return;
        }
        $allowedIds = collect($this->mailboxPage['messages'] ?? [])->pluck('intake_id')->push($this->selectedMessage['intake_id'] ?? null)->filter()->map(fn ($value) => (int) $value);
        abort_unless($allowedIds->contains($id), 404);
        abort_unless($this->mailbox()->available($actor), 404);
        $this->showIntake($this->mailbox()->linkedIntake($actor, $id));
    }

    private function showIntake(AiIntake $intake): void
    {
        $this->resetGuidedReview();
        $this->showMailbox = false;
        $this->selectedIntakeId = $intake->id;
        $this->intakeRevision++;
    }

    private function contextCurrent(): bool
    {
        $settings = AiDispositionSettings::all(true);
        if (($this->connectionState['mailbox_id'] ?? null) === AiDispositionSettings::mailboxId($settings)
            && (int) ($this->connectionState['settings_revision'] ?? -1) === (int) $settings['revision']) {
            return true;
        }

        $this->reset('selectedMessage', 'mailboxPage', 'selectedIntakeId');
        $this->resetGuidedReview();
        $this->connectionState['connected'] = false;
        $this->connectionState['error'] = 'Das Postfach wurde geändert. Bitte neu öffnen.';
        $this->addError('mailbox', 'Das Postfach wurde geändert. Bitte neu öffnen.');

        return false;
    }

    private function perform(callable $action): void
    {
        $this->actor();
        $this->resetValidation();
        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, implode(' ', $messages));
            }
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }
            $this->addError('mailbox', 'Das Postfach konnte nicht geladen oder aktualisiert werden. Bitte den aktuellen Stand erneut laden.');
        } catch (Throwable) {
            $this->addError('mailbox', 'Das Postfach ist gerade nicht erreichbar. Bitte erneut versuchen.');
        }
    }

    private function performGuided(callable $action): void
    {
        $actor = $this->actor();
        $this->resetValidation();
        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, implode(' ', $messages));
            }
            $this->addError('guidedReview', implode(' ', array_slice($exception->validator->errors()->all(), 0, 5)));
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }
            $this->addError('guidedReview', ($exception->getStatusCode() === 409
                ? 'Diese Mailprüfung wurde inzwischen geändert. Die Mail erneut erkennen oder den aktuellen Eingang im Anfrage-Dialog prüfen.'
                : 'Dieser Schritt konnte nicht abgeschlossen werden. Die erkannten Angaben, den Kunden und den Kontakt prüfen.').($exception->getMessage() !== '' ? ' '.mb_substr($exception->getMessage(), 0, 300) : ''));
        } catch (OpenRouterChatException $exception) {
            $knownReasons = ['not_configured', 'disposition_not_configured', 'rate_limit', 'disposition_rate_limit', 'upstream_http_error', 'transport_error', 'incomplete_structured_response', 'invalid_json', 'empty_response', 'upstream_payload_error', 'invalid_tool_response', 'endpoint_not_allowed', 'response_too_large', 'invalid_stream_event', 'upstream_stream_error', 'stream_read_error'];
            $reason = in_array($exception->reasonCode, $knownReasons, true) ? $exception->reasonCode : 'unknown';
            $status = $exception->upstreamStatus !== null && $exception->upstreamStatus >= 100 && $exception->upstreamStatus <= 599 ? $exception->upstreamStatus : null;
            Log::warning('Disposition mailbox guided processing provider failure.', $this->guidedDiagnosticContext($actor, $exception) + ['reason_code' => $reason, 'upstream_status' => $status]);
            $message = match (true) {
                in_array($reason, ['not_configured', 'disposition_not_configured', 'endpoint_not_allowed'], true) => 'Die AI-Verbindung für die Mailerkennung ist nicht vollständig eingerichtet. Die AI-Einstellungen prüfen.',
                in_array($reason, ['rate_limit', 'disposition_rate_limit'], true) => 'Die zulässige Anzahl an AI-Anfragen wurde erreicht. Die Mailerkennung später erneut versuchen.',
                $status === 404 => 'Der Anbieter unterstützt das eingestellte AI-Modell oder die angeforderten Funktionen derzeit nicht. Die AI-Verbindung in den Einstellungen prüfen.',
                in_array($status, [401, 403], true) => 'Der AI-Anbieter hat den Zugriff abgelehnt. Die AI-Zugangsdaten und die Modellfreigabe in den Einstellungen prüfen.',
                $status === 402 => 'Das Guthaben beim AI-Anbieter reicht für die Mailerkennung nicht aus. Guthaben oder Abrechnung prüfen.',
                $status === 429 => 'Der AI-Anbieter ist derzeit ausgelastet. Die Mailerkennung später erneut versuchen.',
                ($status !== null && $status >= 500) || in_array($reason, ['transport_error', 'stream_read_error'], true) => 'Der AI-Anbieter ist gerade nicht erreichbar. Die Mailerkennung später erneut versuchen.',
                in_array($reason, ['incomplete_structured_response', 'invalid_json', 'empty_response', 'invalid_tool_response', 'response_too_large', 'invalid_stream_event'], true) => 'Der AI-Anbieter hat keine vollständig auswertbare Antwort geliefert. Die Mail erneut erkennen oder im Anfrage-Dialog manuell prüfen.',
                default => 'Der AI-Anbieter konnte die Mailerkennung gerade nicht abschließen. Bitte erneut versuchen.',
            };
            $this->addError('guidedReview', $message);
        } catch (Throwable $exception) {
            $context = $this->guidedDiagnosticContext($actor, $exception);
            $file = str_replace('\\', '/', $exception->getFile());
            $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
            $relative = str_starts_with($file, $base) ? substr($file, strlen($base)) : '';
            $context['exception_code'] = (int) $exception->getCode();
            $context['source_file'] = preg_match('#^(app|vendor|bootstrap|resources)/[A-Za-z0-9_./-]+\.php$#D', $relative) ? $relative : 'outside_application';
            $context['source_line'] = max(0, $exception->getLine());
            Log::warning('Disposition mailbox guided processing failed.', $context);
            $this->addError('guidedReview', 'Die Mail konnte gerade nicht verarbeitet werden. Bitte erneut versuchen.');
        }
    }

    private function guidedDiagnosticContext(User $actor, Throwable $exception): array
    {
        $exceptionClass = $exception::class;
        if (str_contains($exceptionClass, '@anonymous')) {
            $exceptionClass = get_parent_class($exception) ?: Throwable::class;
        }

        return array_filter([
            'exception_class' => $exceptionClass,
            'actor_id' => (int) $actor->id,
            'intake_id' => isset($this->guidedReview['intake_id']) || isset($this->selectedMessage['intake_id']) ? (int) ($this->guidedReview['intake_id'] ?? $this->selectedMessage['intake_id']) : null,
            'uid' => isset($this->selectedMessage['uid']) ? (int) $this->selectedMessage['uid'] : null,
        ], static fn ($value) => $value !== null);
    }

    public function render()
    {
        $actor = $this->actor();
        $customerOptions = $contactOptions = [];
        if ($this->guidedReview !== [] && $this->contextCurrent() && $this->guidedStep === 'customer') {
            $customerOptions = $this->workflow()->customerOptions($actor, mb_substr(trim($this->customerSearch), 0, 120), ctype_digit($this->customerId) ? (int) $this->customerId : null);
            if (ctype_digit($this->customerId) && in_array((int) $this->customerId, array_column($customerOptions, 'id'), true)) {
                $contactOptions = $this->workflow()->contactOptions($actor, (int) $this->customerId, ctype_digit($this->contactId) ? (int) $this->contactId : null);
            }
        }

        return view('livewire.operations.disposition-mailbox', ['intakeLabels' => AiIntakeInbox::LABELS, 'customerOptions' => $customerOptions, 'contactOptions' => $contactOptions]);
    }
}
