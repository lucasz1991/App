<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\QualificationType;
use App\Models\User;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\AiIntakeContextHints;
use App\Services\Operations\AiIntakeCustomerSuggestions;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiIntakeInbox extends Component
{
    use WithFileUploads, WithPagination;

    public const LABELS = ['received' => 'Erfasst', 'analyzing' => 'Wird analysiert', 'review' => 'Prüfung nötig', 'waiting_customer' => 'Antwort ausstehend', 'ready' => 'Vorbereitet', 'paused' => 'Pausiert', 'not_inquiry' => 'Keine Anfrage', 'failed' => 'Verarbeitung prüfen', 'completed' => 'Übernommen'];

    public const FIELD_LABELS = ['customer_id' => 'Kundenzuordnung', 'contact_email' => 'Kontakt-E-Mail', 'title' => 'Bezeichnung', 'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'timezone' => 'Zeitzone', 'location_name' => 'Einsatzort', 'role_name' => 'Tätigkeit', 'required_staff' => 'Personalbedarf', 'segments' => 'Schichtabschnitte', 'qualification_ids' => 'Qualifikationen', 'native_review' => 'Bestehende Anfrage prüfen'];

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public int $selectedRevision = 0;

    #[Locked]
    public int $selectedSettingsRevision = 0;

    #[Locked]
    public ?int $editingProposalId = null;

    #[Locked]
    public int $proposalRevision = 0;

    public bool $detailOpen = false;

    public bool $captureOpen = false;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $text = '';

    public string $contactEmail = '';

    public array $uploads = [];

    public $audioUpload = null;

    public string $assignCustomerId = '';

    public string $assignContactId = '';

    public array $proposalForm = [];

    public array $mappingInquiryIds = [];

    #[Locked]
    public bool $customerDraftOpen = false;

    #[Locked]
    public bool $modalOnly = false;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public ?string $parentComponentId = null;

    public string $detailTab = 'review';

    public function mount(?int $customerId = null, ?int $initialIntakeId = null, bool $modalOnly = false, bool $embedded = false, bool $initialCapture = false, ?string $parentComponentId = null): void
    {
        $this->actor();
        if ($customerId) {
            Customer::findOrFail($customerId);
        }
        $this->customerId = $customerId;
        $this->modalOnly = $modalOnly;
        $this->embedded = $embedded;
        $this->parentComponentId = $parentComponentId;
        if ($initialIntakeId) {
            $this->select($initialIntakeId);
        } elseif ($modalOnly || $initialCapture) {
            $this->openCapture();
        }
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        OperationsAccess::requireReady();

        return $actor;
    }

    private function scope(): Builder
    {
        $this->actor();
        AiIntakeSchema::requireReady();

        return AiIntake::query()->when($this->customerId, fn (Builder $query) => $query->where('customer_id', $this->customerId));
    }

    private function selected(): AiIntake
    {
        abort_unless($this->selectedId, 404);

        return $this->scope()->findOrFail($this->selectedId);
    }

    public function select(int $id): void
    {
        $record = $this->scope()->findOrFail($id);
        $this->selectedId = $id;
        $this->selectedRevision = $record->revision;
        $this->selectedSettingsRevision = (int) AiDispositionSettings::all(true)['revision'];
        $this->assignCustomerId = (string) ($record->customer_id ?? '');
        $this->assignContactId = (string) ($record->customer_contact_id ?? '');
        $this->editingProposalId = null;
        $this->proposalForm = [];
        $this->mappingInquiryIds = [];
        $this->detailOpen = true;
        $this->detailTab = 'review';
        if ($this->embedded) {
            $this->captureOpen = false;
        }
        $this->resetValidation();
        $this->dispatch('ai-intake-selected', intakeId: $id, customerId: $this->customerId, componentId: $this->getId(),
            intakeRevision: $record->revision, sourceRevision: $record->source_revision);
        if ($this->embedded) {
            $this->dispatch('select-ai-intake-assistant', intakeId: $id, componentId: $this->getId());
        }
    }

    #[On('open-ai-intake')]
    public function open(?int $intakeId = null, bool $create = false, ?string $parentComponentId = null): void
    {
        if (! $this->embedded || ($parentComponentId !== null && $parentComponentId !== $this->parentComponentId)) {
            return;
        }
        $this->actor();
        if ($intakeId) {
            $this->select($intakeId);
        } elseif ($create) {
            $this->openCapture();
        } else {
            $this->closeDetail();
        }
    }

    public function closeDetail(): void
    {
        $this->actor();
        $this->detailOpen = false;
        $this->selectedId = $this->editingProposalId = null;
        $this->selectedRevision = $this->selectedSettingsRevision = $this->proposalRevision = 0;
        $this->proposalForm = $this->mappingInquiryIds = [];
        $this->assignCustomerId = $this->assignContactId = '';
        $this->detailTab = 'review';
        $this->resetValidation();
        if ($this->embedded) {
            $this->dispatch('ai-intake-assistant-overview', customerId: $this->customerId, componentId: $this->getId());
        }
    }

    public function closeCapture(): void
    {
        $this->actor();
        $this->captureOpen = false;
        $this->dispatch('ai-intake-capture-closed', componentId: $this->getId());
    }

    public function setDetailTab(string $tab): void
    {
        $this->selected();
        abort_unless(in_array($tab, ['review', 'sources', 'communication', 'assistant'], true), 422);
        $this->detailTab = $tab;
        if ($tab === 'assistant' && $this->embedded) {
            $this->dispatch('ai-intake-discussion-open', componentId: $this->getId());
        }
    }

    public function discuss(int $id): void
    {
        $this->select($id);
        $this->detailTab = 'assistant';
        if ($this->embedded) {
            $this->dispatch('ai-intake-discussion-open', componentId: $this->getId());
        }
    }

    #[On('ai-intake-question-answered')]
    public function showAnsweredQuestion(?string $hostComponentId = null, ?int $intakeId = null): void
    {
        if (! $this->embedded || ! $this->detailOpen || $hostComponentId !== $this->getId() || $intakeId !== $this->selectedId) {
            return;
        }
        $this->setDetailTab('assistant');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('aiIntakePage');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage('aiIntakePage');
    }

    public function updatedAssignCustomerId(): void
    {
        $this->assignContactId = '';
    }

    public function openCapture(): void
    {
        $this->actor();
        AiIntakeSchema::requireReady();
        $this->captureOpen = true;
        if ($this->embedded) {
            $this->detailOpen = false;
            $this->dispatch('ai-intake-assistant-overview', customerId: $this->customerId, componentId: $this->getId());
        }
    }

    public function removeUpload(int $index): void
    {
        $this->actor();
        abort_unless($index >= 0 && array_key_exists($index, $this->uploads), 422);
        if (! $this->uploads[$index] instanceof TemporaryUploadedFile || ! $this->uploads[$index]->delete()) {
            $this->addError('uploads', 'Die temporäre Datei konnte nicht entfernt werden. Bitte erneut versuchen.');

            return;
        }
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function removeAudio(): void
    {
        $this->actor();
        if ($this->audioUpload && (! $this->audioUpload instanceof TemporaryUploadedFile || ! $this->audioUpload->delete())) {
            $this->addError('audioUpload', 'Die temporäre Aufnahme konnte nicht entfernt werden. Bitte erneut versuchen.');

            return;
        }
        $this->audioUpload = null;
        $this->resetValidation('audioUpload');
        $this->dispatch('ai-intake-composer-audio-removed', componentId: $this->getId());
    }

    public function discardUploads(): void
    {
        $this->actor();
        foreach (array_keys($this->uploads) as $index) {
            $file = $this->uploads[$index];
            if ($file instanceof TemporaryUploadedFile && $file->delete()) {
                unset($this->uploads[$index]);
            } else {
                $this->addError('uploads', 'Eine temporäre Datei konnte nicht entfernt werden. Bitte erneut versuchen.');
            }
        }
        $this->uploads = array_values($this->uploads);
        $this->removeAudio();
    }

    public function submit(): void
    {
        $actor = $this->actor();
        AiIntakeSchema::requireReady();
        $this->validate(['text' => ['nullable', 'string', 'max:20000'], 'contactEmail' => ['nullable', 'email', 'max:254'], 'uploads' => ['array', 'max:3'], 'uploads.*' => ['file', 'max:10240'], 'audioUpload' => ['nullable', 'file', 'max:8192']]);
        $files = array_values(array_filter([...$this->uploads, $this->audioUpload]));
        if (trim($this->text) === '' && $files === []) {
            $this->addError('text', 'Text eingeben oder eine Datei beziehungsweise Sprachaufnahme hinzufügen.');

            return;
        }
        $this->perform(function () use ($actor, $files): void {
            $record = app(AiIntakeService::class)->submit($actor, $this->text, $files, ['contact_email' => $this->contactEmail, 'customer_id' => $this->customerId, 'timezone' => 'Europe/Berlin']);
            foreach ($files as $file) {
                if (method_exists($file, 'delete')) {
                    $file->delete();
                }
            }
            $this->reset('text', 'contactEmail', 'uploads', 'audioUpload', 'captureOpen');
            $this->dispatch('ai-intake-submitted');
            $this->select($record->id);
        });
    }

    public function assignCustomer(): void
    {
        $this->actor();
        $this->validate(['assignCustomerId' => ['required', 'integer', 'min:1'], 'assignContactId' => ['nullable', 'integer', 'min:1']]);
        abort_if($this->customerId && (int) $this->assignCustomerId !== $this->customerId, 403);
        $this->perform(fn () => app(AiIntakeService::class)->assignCustomer($this->selected(), $this->actor(), (int) $this->assignCustomerId, $this->assignContactId === '' ? null : (int) $this->assignContactId, $this->selectedRevision));
    }

    public function reanalyze(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            if ($intake->source_type === 'email') {
                app(AiIntakeService::class)->analyzeManually($intake, $this->actor(), $this->selectedRevision, $this->selectedSettingsRevision);
            } else {
                app(AiIntakeService::class)->reanalyze($intake, $this->actor(), $this->selectedRevision);
            }
        });
    }

    public function pause(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->pause($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function createInquiries(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->createInquiries($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function editProposal(int $id): void
    {
        $proposal = $this->selected()->proposals()->findOrFail($id);
        abort_unless($proposal->source_revision === $this->selected()->source_revision && ! in_array($proposal->status, ['applied', 'stale'], true), 409, 'Die Zuordnung und den aktuellen Stand zuerst prüfen.');
        $this->editingProposalId = $proposal->id;
        $this->proposalRevision = $proposal->revision;
        $this->proposalForm = array_intersect_key($proposal->payload ?? [], array_flip(['demand', 'segments']));
        $this->proposalForm['requirements_reviewed'] = false;
        $this->resetValidation();
    }

    public function addSegment(): void
    {
        $this->selected();
        abort_unless($this->editingProposalId && count($this->proposalForm['segments'] ?? []) < 50, 422);
        $this->proposalForm['segments'][] = ['starts_at' => '', 'ends_at' => '', 'timezone' => $this->proposalForm['demand']['timezone'] ?? 'Europe/Berlin', 'planned_break_minutes' => 0, 'required_staff' => 1];
    }

    public function removeSegment(int $index): void
    {
        $this->selected();
        unset($this->proposalForm['segments'][$index]);
        $this->proposalForm['segments'] = array_values($this->proposalForm['segments'] ?? []);
    }

    public function saveProposal(): void
    {
        $this->perform(function (): void {
            $proposal = $this->selected()->proposals()->findOrFail($this->editingProposalId);
            $payload = $proposal->payload;
            $payload['demand'] = $this->proposalForm['demand'] ?? [];
            $payload['segments'] = $this->proposalForm['segments'] ?? [];
            $payload['requirements_reviewed'] = (bool) ($this->proposalForm['requirements_reviewed'] ?? false);
            app(AiIntakeService::class)->saveProposal($proposal, $this->actor(), $payload, $this->proposalRevision);
            $this->editingProposalId = null;
        });
    }

    public function approveProposal(int $id, int $revision): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->perform(fn () => app(AiIntakeService::class)->approveProposal($this->selected()->proposals()->findOrFail($id), $this->actor(), $revision));
    }

    public function retryPreparation(int $id, int $revision): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->perform(fn () => app(AiIntakeService::class)->retryApprovedProposal($this->selected()->proposals()->findOrFail($id), $this->actor(), $revision));
    }

    public function mapProposal(int $id, int $revision): void
    {
        $this->validate(['mappingInquiryIds.'.$id => ['required', 'integer', 'min:1']]);
        $this->perform(fn () => app(AiIntakeService::class)->mapProposalInquiry($this->selected()->proposals()->findOrFail($id), $this->actor(), (int) $this->mappingInquiryIds[$id], $revision));
    }

    public function prepareCustomer(): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        abort_unless(! $this->customerId && ! $this->selected()->customer_id && $this->selected()->revision === $this->selectedRevision, 409);
        $this->detailOpen = false;
        $this->customerDraftOpen = true;
    }

    #[On('customer-record-saved')]
    public function customerCreated(int $customerId, ?int $workspaceRevision = null): void
    {
        if (! $this->customerDraftOpen || $workspaceRevision !== $this->selectedRevision) {
            return;
        }
        $record = $this->selected();
        abort_unless($record->customer_id === $customerId, 409);
        $this->customerDraftOpen = false;
        $this->select($record->id);
    }

    #[On('customer-form-closed')]
    public function customerDraftClosed(?int $workspaceRevision = null): void
    {
        if (! $this->customerDraftOpen || $workspaceRevision !== $this->selectedRevision) {
            return;
        }
        $this->customerDraftOpen = false;
        $this->select($this->selected()->id);
    }

    private function perform(callable $action): void
    {
        $this->actor();
        $this->resetValidation();
        try {
            $action();
            if ($this->selectedId) {
                $this->select($this->selectedId);
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, implode(' ', $messages));
            }
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }
            $this->addError('intake', $exception->getMessage() ?: 'Der Vorgang konnte nicht übernommen werden. Aktuellen Stand prüfen.');
        } catch (OpenRouterChatException $exception) {
            $this->addError('intake', match (true) {
                in_array($exception->reasonCode, ['not_configured', 'disposition_not_configured', 'endpoint_not_allowed'], true) => 'Die AI-Verbindung ist nicht vollständig eingerichtet. Die AI-Einstellungen prüfen.',
                in_array($exception->reasonCode, ['rate_limit', 'disposition_rate_limit'], true) || $exception->upstreamStatus === 429 => 'Die AI-Prüfung ist derzeit ausgelastet. Bitte später erneut versuchen.',
                $exception->upstreamStatus === 404 => 'Der Anbieter unterstützt das eingestellte AI-Modell oder die angeforderten Funktionen derzeit nicht. Die AI-Verbindung in den Einstellungen prüfen.',
                in_array($exception->upstreamStatus, [401, 403], true) => 'Der AI-Anbieter hat den Zugriff abgelehnt. Die AI-Zugangsdaten und Modellfreigabe prüfen.',
                $exception->upstreamStatus === 402 => 'Das Guthaben beim AI-Anbieter reicht für diese Prüfung nicht aus. Guthaben oder Abrechnung prüfen.',
                default => 'Die AI-Prüfung konnte gerade nicht abgeschlossen werden. Bitte erneut versuchen.',
            });
        }
    }

    public function render()
    {
        $actor = $this->actor();
        $ready = AiIntakeSchema::ready();
        abort_unless(in_array($this->statusFilter, ['all', ...AiIntake::STATUSES], true), 422);
        abort_unless(in_array($this->detailTab, ['review', 'sources', 'communication', 'assistant'], true), 422);
        $query = $ready ? $this->scope()->with('customer')->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', '%'.mb_substr(trim($this->search), 0, 100).'%')) : null;
        $selected = $ready && $this->selectedId ? $this->selected()->load(['customer', 'contact', 'messages', 'attachments', 'runs', 'deliveries', 'proposals.inquiry']) : null;

        return view('livewire.operations.ai-intake-inbox', [
            'ready' => $ready, 'enabled' => AiDispositionSettings::enabled(), 'labels' => self::LABELS, 'fieldLabels' => self::FIELD_LABELS,
            'intakes' => $query?->latest('id')->paginate(12, ['*'], 'aiIntakePage'), 'selected' => $selected,
            'customers' => Customer::active()->when($this->customerId, fn ($query) => $query->whereKey($this->customerId))->orderBy('company_name')->get(),
            'contacts' => $this->assignCustomerId !== '' ? CustomerContact::where('customer_id', (int) $this->assignCustomerId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'canApprove' => $actor->can('operations.manage'),
            'qualifications' => QualificationType::where('is_active', true)->orderBy('name')->get(),
            'mappingInquiries' => $selected?->proposals->pluck('inquiry')->filter()->unique('id')->keyBy('id') ?? collect(),
            'customerSuggestions' => $selected ? app(AiIntakeCustomerSuggestions::class)->suggest($selected, $actor) : [],
            'contextHints' => $selected ? app(AiIntakeContextHints::class)->forReview($selected, $actor) : [],
            'limits' => array_intersect_key(AiDispositionSettings::all(), array_flip(['max_attachment_count', 'max_total_kilobytes', 'max_audio_kilobytes'])),
            'settingsRevision' => (int) AiDispositionSettings::all(true)['revision'],
        ]);
    }
}
