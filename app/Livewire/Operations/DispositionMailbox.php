<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\User;
use App\Services\Operations\DispositionMailboxService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
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

    #[On('open-disposition-mailbox')]
    public function open(): void
    {
        $actor = $this->actor();
        abort_unless($this->mailbox()->available($actor), 404);
        $this->resetValidation();
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
                    'status_label' => AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen',
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
        $this->perform(fn () => $this->selectedMessage = $this->mailbox()->deliveryDetail($actor, $id));
    }

    public function clearSelection(): void
    {
        $this->actor();
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
        $this->perform(function () use ($actor, $classification): void {
            $result = $this->mailbox()->classify($actor, $this->selectedMessage['folder'], (int) $this->selectedMessage['uid'], (int) $this->selectedMessage['uid_validity'], $classification);
            $this->selectedMessage = array_replace($this->selectedMessage, $result);
            $this->loadPage($actor);
            $this->dispatch('disposition-mailbox-updated');
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

    public function render()
    {
        $this->actor();

        return view('livewire.operations.disposition-mailbox', ['intakeLabels' => AiIntakeInbox::LABELS]);
    }
}
