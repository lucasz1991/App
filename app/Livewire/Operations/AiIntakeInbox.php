<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\ResolvesOperationsActor;
use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\User;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\AiIntakeContextHints;
use App\Services\Operations\AiIntakeCustomerSuggestions;
use App\Services\Operations\AiIntakeManualService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\OperationsTransaction;
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
    use ResolvesOperationsActor, WithFileUploads, WithPagination;

    public const LABELS = ['received' => 'Erfasst', 'analyzing' => 'Wird analysiert', 'review' => 'Prüfung nötig', 'waiting_customer' => 'Antwort ausstehend', 'ready' => 'Vorbereitet', 'paused' => 'Pausiert', 'not_inquiry' => 'Keine Anfrage', 'failed' => 'Verarbeitung prüfen', 'completed' => 'Übernommen'];

    public const FIELD_LABELS = ['customer_id' => 'Kundenzuordnung', 'contact_email' => 'Kontakt-E-Mail', 'title' => 'Bezeichnung', 'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'timezone' => 'Zeitzone', 'location_name' => 'Einsatzort', 'role_name' => 'Tätigkeit', 'required_staff' => 'Personalbedarf', 'segments' => 'Schichtabschnitte', 'qualification_ids' => 'Qualifikationen', 'native_review' => 'Angaben persönlich prüfen'];

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public int $selectedRevision = 0;

    #[Locked]
    public int $selectedSettingsRevision = 0;

    #[Locked]
    public int $selectedSourceRevision = 0;

    #[Locked]
    public array $confirmationProposalRevisions = [];

    #[Locked]
    public ?int $editingProposalId = null;

    #[Locked]
    public ?int $drawerProposalId = null;

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

    #[Locked]
    public bool $manualEditorOpen = false;

    #[Locked]
    public bool $customerEditorOpen = false;

    public array $mappingInquiryIds = [];

    #[Locked]
    public ?int $planningProposalId = null;

    #[Locked]
    public int $planningProposalRevision = 0;

    #[Locked]
    public array $planningShiftRevisions = [];

    public array $planningSegments = [];

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
        return $this->freshActor('operations.inquiries.manage');
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
        $previousProposalId = $this->selectedId === $id ? $this->drawerProposalId : null;
        $this->selectedId = $id;
        $currentProposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale')->sortBy('position_index');
        $this->drawerProposalId = $currentProposals->firstWhere('id', $previousProposalId)?->id ?? $currentProposals->first()?->id;
        $this->selectedRevision = $record->revision;
        $this->selectedSettingsRevision = (int) AiDispositionSettings::all(true)['revision'];
        $this->selectedSourceRevision = $record->source_revision;
        $this->confirmationProposalRevisions = $record->proposals()->where('source_revision', $record->source_revision)->pluck('revision', 'id')->all();
        $this->assignCustomerId = (string) ($record->customer_id ?? '');
        $this->assignContactId = (string) ($record->customer_contact_id ?? '');
        $this->editingProposalId = null;
        $this->proposalForm = [];
        $this->manualEditorOpen = false;
        $this->customerEditorOpen = false;
        $this->mappingInquiryIds = [];
        $this->reset('planningProposalId', 'planningProposalRevision', 'planningSegments', 'planningShiftRevisions');
        foreach ($record->proposals as $proposal) {
            $this->planningShiftRevisions[$proposal->id] = Shift::whereIn('id', $proposal->applied_shift_ids ?? [])->pluck('revision', 'id')->all();
        }
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
        $this->drawerProposalId = null;
        $this->selectedRevision = $this->selectedSettingsRevision = $this->proposalRevision = 0;
        $this->selectedSourceRevision = 0;
        $this->confirmationProposalRevisions = [];
        $this->proposalForm = $this->mappingInquiryIds = [];
        $this->manualEditorOpen = $this->customerEditorOpen = false;
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
        abort_unless(in_array($tab, $this->embedded ? ['review', 'sources', 'communication', 'assistant'] : ['review', 'sources', 'communication', 'details'], true), 422);
        $this->detailTab = $tab;
        if ($tab === 'assistant' && $this->embedded) {
            $this->dispatch('ai-intake-discussion-open', componentId: $this->getId());
        }
    }

    /** Change the footer's position context without discarding another editor. */
    public function selectDrawerPosition(int $id): void
    {
        $this->perform(function () use ($id): void {
            $record = $this->selected();
            $this->assertDrawerReady($record);
            $proposal = $record->proposals()->findOrFail($id);
            abort_unless($proposal->source_revision === $record->source_revision && $proposal->status !== 'stale'
                && ($this->confirmationProposalRevisions[$id] ?? null) === $proposal->revision, 409, 'Die Leistung wurde geändert. Bitte neu laden.');
            $this->drawerProposalId = $id;
            $this->detailTab = 'review';
        }, false);
    }

    private function assertDrawerReady(AiIntake $record): void
    {
        abort_if($this->embedded, 409);
        abort_if($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null
            || $this->customerEditorOpen || $this->hasUnsavedAssignment($record), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
        abort_unless($record->revision === $this->selectedRevision && $record->source_revision === $this->selectedSourceRevision
            && $this->selectedSettingsRevision === (int) AiDispositionSettings::all(true)['revision'], 409, 'Der Eingang wurde inzwischen geändert. Bitte neu laden.');
    }

    /** Explicitly accept only the displayed position; other positions keep their own path. */
    public function confirmDrawerPosition(string $businessPath): void
    {
        abort_unless(in_array($businessPath, ['inquiry', 'direct_order'], true), 422);
        $actor = $this->actor();
        if ($businessPath === 'direct_order') {
            OperationsAccess::authorize($actor, 'operations.manage');
        }
        $this->perform(function () use ($businessPath, $actor): void {
            OperationsTransaction::run(function () use ($businessPath, $actor): void {
                $record = $this->selected();
                $this->assertDrawerReady($record);
                $proposals = $record->proposals()->where('source_revision', $record->source_revision)->get();
                abort_unless($proposals->pluck('revision', 'id')->all() == $this->confirmationProposalRevisions, 409, 'Die Leistungen wurden geändert. Bitte neu laden.');
                $proposal = $proposals->firstWhere('id', $this->drawerProposalId);
                abort_unless($proposal && ! in_array($proposal->status, ['applied', 'stale', 'failed'], true), 409, 'Die aktuelle Leistung zuerst prüfen.');
                abort_if($this->proposalNeedsCorrection($proposal->payload ?? []), 422, 'Die Angaben dieser Leistung zuerst ergänzen.');
                abort_if($proposal->inquiry && ! in_array($proposal->inquiry->status, ['new', 'verified'], true), 409, 'Den bestehenden Angebots- oder Auftragsvorgang öffnen.');
                $claimed = app(AiIntakeManualService::class)->claimManual($record, $actor, $this->selectedRevision);
                $proposal = $proposal->fresh();
                if (($proposal->payload['business_path'] ?? 'inquiry') !== $businessPath) {
                    $payload = array_intersect_key($proposal->payload, array_flip(['demand', 'segments']));
                    $payload['business_path'] = $businessPath;
                    app(AiIntakeManualService::class)->savePosition($claimed, $actor, $claimed->revision,
                        $this->selectedSourceRevision, $payload, $proposal->id, $proposal->revision);
                    $claimed = $claimed->fresh();
                }
                $expectedRevisions = $claimed->proposals()->where('source_revision', $claimed->source_revision)->pluck('revision', 'id')->all();
                $confirmed = app(AiIntakeManualService::class)->confirm($claimed, $actor, $claimed->revision,
                    $this->selectedSourceRevision, $expectedRevisions, $proposal->id);
                if ($businessPath === 'direct_order') {
                    $inquiry = $confirmed->proposals()->findOrFail($proposal->id)->inquiry;
                    app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $actor);
                }
            });
        });
    }

    public function discuss(int $id): void
    {
        if (! $this->embedded) {
            $this->perform(function () use ($id): void {
                abort_if($this->manualEditorOpen || $this->editingProposalId || $this->planningProposalId !== null
                    || ($this->selectedId && $this->hasUnsavedAssignment($this->selected())),
                    409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
                $this->select($id);
                $this->openAssist();
            }, false);

            return;
        }
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

    public function activeFilterCount(): int
    {
        return (int) (trim($this->search) !== '') + (int) ($this->statusFilter !== 'all');
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->resetPage('aiIntakePage');
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

    public function editCustomerAssignment(): void
    {
        $this->perform(function (): void {
            $this->selected();
            abort_if($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null,
                409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            $this->detailTab = 'review';
            $this->customerEditorOpen = true;
        }, false);
    }

    public function cancelCustomerAssignment(): void
    {
        $record = $this->selected();
        $this->assignCustomerId = (string) ($record->customer_id ?? '');
        $this->assignContactId = (string) ($record->customer_contact_id ?? '');
        $this->customerEditorOpen = false;
        $this->resetValidation();
    }

    /** Reveal the next correction without resetting a draft or applying the source. */
    public function reviewNextPosition(): void
    {
        $this->perform(function (): void {
            $record = $this->selected()->load('proposals.inquiry');
            abort_unless($record->revision === $this->selectedRevision && $record->source_revision === $this->selectedSourceRevision,
                409, 'Der Eingang wurde inzwischen geändert. Bitte neu laden.');
            $this->detailTab = 'review';
            if ($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null) {
                return;
            }
            if (! $record->customer_id || $this->hasUnsavedAssignment($record)) {
                $this->customerEditorOpen = true;

                return;
            }
            $proposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale');
            foreach ($proposals as $proposal) {
                if (! in_array($proposal->status, ['applied', 'stale'], true)
                    && (! $proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true))
                    && ($proposal->status === 'failed' || $this->proposalNeedsCorrection($proposal->payload ?? []))) {
                    $this->editProposal($proposal->id);

                    return;
                }
            }
            if ($proposals->isEmpty()) {
                $this->addManualPosition();
            }
        }, false);
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
        $this->perform(function (): void {
            abort_if($this->editingProposalId || $this->manualEditorOpen, 409, 'Die offene Position zuerst speichern oder verwerfen.');
            if (! $this->embedded) {
                $this->claimManual();
            }
            app(AiIntakeService::class)->assignCustomer($this->selected(), $this->actor(), (int) $this->assignCustomerId, $this->assignContactId === '' ? null : (int) $this->assignContactId, $this->selectedRevision);
        });
    }

    public function reanalyze(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            if (! $this->embedded) {
                $this->assertDrawerReady($intake);
            }
            if ($intake->source_type === 'email') {
                app(AiIntakeService::class)->analyzeManually($intake, $this->actor(), $this->selectedRevision, $this->selectedSettingsRevision);
            } else {
                app(AiIntakeService::class)->reanalyze($intake, $this->actor(), $this->selectedRevision);
            }
        });
    }

    public function pause(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            if (! $this->embedded) {
                $this->assertDrawerReady($intake);
            }
            app(AiIntakeService::class)->pause($intake, $this->actor(), $this->selectedRevision);
        });
    }

    public function createInquiries(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->createInquiries($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function confirmReview(): void
    {
        $this->perform(function (): void {
            abort_if($this->editingProposalId !== null, 409, 'Die offene Korrektur zuerst speichern.');
            $intake = $this->selected();
            abort_if($this->hasUnsavedAssignment($intake), 409, 'Die geänderte Kunden- oder Kontaktzuordnung zuerst speichern.');
            app(AiIntakeService::class)->confirmReview($intake, $this->actor(), $this->selectedRevision,
                $this->selectedSourceRevision, $this->selectedSettingsRevision, $this->confirmationProposalRevisions);
        });
    }

    private function hasUnsavedAssignment(AiIntake $intake): bool
    {
        return $this->assignCustomerId !== (string) ($intake->customer_id ?? '')
            || $this->assignContactId !== (string) ($intake->customer_contact_id ?? '');
    }

    public function editProposal(int $id): void
    {
        $proposal = $this->selected()->proposals()->findOrFail($id);
        abort_unless($proposal->source_revision === $this->selected()->source_revision && ! in_array($proposal->status, ['applied', 'stale'], true), 409, 'Die Zuordnung und den aktuellen Stand zuerst prüfen.');
        if (! $this->embedded) {
            $this->perform(function () use ($id): void {
                abort_if($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null || $this->customerEditorOpen
                    || $this->hasUnsavedAssignment($this->selected()), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
                $this->claimManual();
                $this->select($this->selectedId);
                $this->fillProposalForm($id);
                $this->drawerProposalId = $id;
            }, false);

            return;
        }
        $this->fillProposalForm($id);
    }

    private function fillProposalForm(int $id): void
    {
        $proposal = $this->selected()->proposals()->findOrFail($id);
        $this->editingProposalId = $proposal->id;
        $this->proposalRevision = $proposal->revision;
        $this->proposalForm = array_intersect_key($proposal->payload ?? [], array_flip(['demand', 'segments', 'business_path']));
        $this->proposalForm['business_path'] ??= 'inquiry';
        $this->proposalForm['requirements_reviewed'] = false;
        $this->resetValidation();
    }

    private function claimManual(): void
    {
        $current = $this->selected();
        abort_unless($current->source_revision === $this->selectedSourceRevision, 409, 'Die Quelle wurde inzwischen geändert. Bitte neu laden.');
        $record = app(AiIntakeManualService::class)->claimManual($current, $this->actor(), $this->selectedRevision);
        if ($record->revision !== $this->selectedRevision) {
            $this->confirmationProposalRevisions = $record->proposals()->where('source_revision', $record->source_revision)->pluck('revision', 'id')->all();
        }
        $this->selectedRevision = $record->revision;
    }

    public function addManualPosition(): void
    {
        $this->perform(function (): void {
            abort_if($this->editingProposalId || $this->manualEditorOpen || $this->planningProposalId !== null || $this->customerEditorOpen || $this->hasUnsavedAssignment($this->selected()), 409, 'Die offenen Angaben zuerst speichern.');
            $this->claimManual();
            $this->select($this->selectedId);
            $this->manualEditorOpen = true;
            $this->proposalForm = ['demand' => ['title' => '', 'starts_at' => '', 'ends_at' => '', 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'location_name' => '', 'role_name' => '', 'required_staff' => '', 'qualification_ids' => []], 'segments' => [], 'business_path' => 'inquiry', 'requirements_reviewed' => false];
        }, false);
    }

    public function cancelPositionEdit(): void
    {
        $this->selected();
        $this->editingProposalId = null;
        $this->manualEditorOpen = false;
        $this->proposalForm = [];
        $this->resetValidation();
    }

    public function saveManualPosition(): void
    {
        $this->perform(function (): void {
            abort_unless($this->manualEditorOpen || $this->editingProposalId, 409);
            $this->claimManual();
            $proposal = app(AiIntakeManualService::class)->savePosition($this->selected(), $this->actor(), $this->selectedRevision, $this->selectedSourceRevision,
                $this->proposalForm, $this->editingProposalId, $this->editingProposalId ? $this->proposalRevision : null);
            $this->drawerProposalId = $proposal->id;
        });
    }

    public function confirmManual(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            abort_if($this->editingProposalId || $this->manualEditorOpen || $this->hasUnsavedAssignment($intake), 409, 'Die offenen Angaben zuerst speichern.');
            $this->claimManual();
            $record = app(AiIntakeManualService::class)->confirm($this->selected(), $this->actor(), $this->selectedRevision, $this->selectedSourceRevision, $this->confirmationProposalRevisions);
            // Confirmation is durable even if a later direct acceptance needs a correction.
            $this->select($record->id);
            foreach ($record->proposals()->where('source_revision', $record->source_revision)->where('status', '!=', 'stale')->with('inquiry')->get() as $proposal) {
                if (($proposal->payload['business_path'] ?? 'inquiry') === 'direct_order' && $proposal->inquiry) {
                    app(InquiryWorkflowService::class)->takeDirectOrder($proposal->inquiry, $proposal->inquiry->revision, $this->actor());
                    $this->select($record->id);
                }
            }
        });
    }

    public function takeDirectOrder(int $id, int $revision, int $inquiryRevision): void
    {
        $this->perform(function () use ($id, $revision, $inquiryRevision): void {
            $intake = $this->selected();
            if (! $this->embedded) {
                $this->assertDrawerReady($intake);
            }
            abort_unless($intake->revision === $this->selectedRevision && $intake->source_revision === $this->selectedSourceRevision, 409, 'Der Eingang wurde inzwischen geändert.');
            $proposal = $intake->proposals()->with('inquiry')->findOrFail($id);
            abort_unless($proposal->revision === $revision && $proposal->source_revision === $intake->source_revision && $proposal->inquiry, 409, 'Die Position wurde inzwischen geändert.');
            app(InquiryWorkflowService::class)->takeDirectOrder($proposal->inquiry, $inquiryRevision, $this->actor());
        });
    }

    public function openAssist(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            abort_if($this->manualEditorOpen || $this->editingProposalId || $this->planningProposalId !== null || $this->hasUnsavedAssignment($intake), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            abort_unless($intake->revision === $this->selectedRevision && $intake->source_revision === $this->selectedSourceRevision, 409, 'Der Eingang wurde inzwischen geändert.');
            $this->detailOpen = false;
            $this->dispatch('open-ai-intake-panel', intakeId: $intake->id, create: false, customerId: $this->customerId ?? $intake->customer_id);
        }, false);
    }

    public function openOutbox(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            abort_if($this->manualEditorOpen || $this->editingProposalId || $this->planningProposalId !== null
                || $this->hasUnsavedAssignment($intake), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            abort_unless($intake->revision === $this->selectedRevision && $intake->source_revision === $this->selectedSourceRevision,
                409, 'Der Eingang wurde inzwischen geändert.');
            $this->detailOpen = false;
            $this->dispatch('railtime-assistant-open');
            $this->dispatch('operations-assistant-action', action: 'outbox');
        }, false);
    }

    public function addSegment(): void
    {
        $this->selected();
        abort_unless(($this->editingProposalId || $this->manualEditorOpen) && count($this->proposalForm['segments'] ?? []) < 50, 422);
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
        if (! $this->embedded) {
            $this->saveManualPosition();

            return;
        }
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

    public function openPlanning(int $id, int $revision): void
    {
        $this->perform(function () use ($id, $revision): void {
            OperationsAccess::authorize($this->actor(), 'operations.manage');
            $proposal = $this->selected()->proposals()->with('inquiry')->findOrFail($id);
            abort_unless($proposal->revision === $revision && $proposal->source_revision === $this->selectedSourceRevision
                && ! in_array($proposal->status, ['applied', 'stale'], true)
                && $proposal->inquiry?->status === 'converted' && $proposal->inquiry->order_id, 409, 'Zuerst den aktuellen Auftrag in der Anfrage bestätigen.');
            abort_if($this->editingProposalId !== null || $this->manualEditorOpen || $this->planningProposalId !== null || $this->customerEditorOpen
                || $this->hasUnsavedAssignment($this->selected()), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            $this->planningProposalId = $id;
            if (! $this->embedded) {
                $this->drawerProposalId = $id;
            }
            $this->planningProposalRevision = $revision;
            $inquiry = $proposal->inquiry;
            $this->planningSegments = $proposal->payload['segments'] ?? [];
            if ($this->planningSegments === []) {
                $this->planningSegments[] = ['starts_at' => $inquiry->starts_at->setTimezone($inquiry->timezone)->format('Y-m-d\TH:i'),
                    'ends_at' => $inquiry->ends_at->setTimezone($inquiry->timezone)->format('Y-m-d\TH:i'),
                    'timezone' => $inquiry->timezone, 'required_staff' => $inquiry->required_staff, 'planned_break_minutes' => ''];
            }
        }, false);
    }

    public function continueInquiry(int $id, int $revision): void
    {
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            $proposal = $this->selected()->proposals()->findOrFail($id);
            $inquiryId = app(AiIntakeService::class)->continueProposalManually($proposal, $this->actor(), $revision);
            $this->redirect(OperationsPages::url('cases', ['view' => 'inbox', 'inquiry' => $inquiryId]), navigate: true);
        }, false);
    }

    public function closePlanning(): void
    {
        $this->actor();
        $this->reset('planningProposalId', 'planningProposalRevision', 'planningSegments');
        $this->resetValidation();
    }

    public function addPlanningSegment(): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $proposal = $this->selected()->proposals()->with('inquiry')->findOrFail($this->planningProposalId);
        abort_unless($proposal->inquiry && count($this->planningSegments) < 50, 422);
        $this->planningSegments[] = ['starts_at' => '', 'ends_at' => '', 'timezone' => $proposal->inquiry->timezone,
            'required_staff' => $proposal->inquiry->required_staff, 'planned_break_minutes' => ''];
    }

    public function removePlanningSegment(int $index): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->selected()->proposals()->findOrFail($this->planningProposalId);
        unset($this->planningSegments[$index]);
        $this->planningSegments = array_values($this->planningSegments);
    }

    public function preparePlanning(): void
    {
        $this->perform(function (): void {
            $proposal = $this->selected()->proposals()->findOrFail($this->planningProposalId);
            $ids = app(AiIntakeService::class)->prepareProposalShifts($proposal, $this->actor(), $this->planningProposalRevision, $this->planningSegments);
            $this->dispatch('operations-plan-changed');
            $this->dispatch('swal:toast', type: 'success', text: count($ids).' Schichtentwürfe vorbereitet. Einsatzort prüfen und zur Besetzung freigeben.');
        });
    }

    public function publishPlanning(int $id, int $revision): void
    {
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            $proposal = $this->selected()->proposals()->findOrFail($id);
            app(AiIntakeService::class)->publishProposalShifts($proposal, $this->actor(), $revision, $this->planningShiftRevisions[$id] ?? []);
            $this->dispatch('operations-plan-changed');
            $this->dispatch('swal:toast', type: 'success', text: 'Schichten sind zur Besetzung freigegeben. Mitarbeiter im Schichtplan zuordnen.');
        });
    }

    public function retryPreparation(int $id, int $revision): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            app(AiIntakeService::class)->retryApprovedProposal($this->selected()->proposals()->findOrFail($id), $this->actor(), $revision);
        });
    }

    public function mapProposal(int $id, int $revision): void
    {
        $this->validate(['mappingInquiryIds.'.$id => ['required', 'integer', 'min:1']]);
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            app(AiIntakeService::class)->mapProposalInquiry($this->selected()->proposals()->findOrFail($id), $this->actor(), (int) $this->mappingInquiryIds[$id], $revision);
        });
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

    private function perform(callable $action, bool $reload = true): void
    {
        $this->actor();
        $this->resetValidation();
        try {
            $action();
            if ($reload && $this->selectedId) {
                $this->select($this->selectedId);
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, implode(' ', $messages));
                if (str_starts_with($field, 'segments.')) {
                    $this->addError('planningSegments.'.substr($field, strlen('segments.')), implode(' ', $messages));
                    $this->addError('proposalForm.'.$field, implode(' ', $messages));
                } elseif (str_starts_with($field, 'demand.')) {
                    $this->addError('proposalForm.'.$field, implode(' ', $messages));
                }
            }
            $this->addError('intake', implode(' ', array_unique($exception->validator->errors()->all())));
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

    /** Presentation only. The workflow services still validate every write. */
    private function proposalNeedsCorrection(array $payload): bool
    {
        $demand = $payload['demand'] ?? [];
        foreach (['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff'] as $field) {
            if (! filled($demand[$field] ?? null)) {
                return true;
            }
        }

        return ! empty($payload['missing_fields']) || ! empty($payload['unmapped_requirements'])
            || (($payload['assumptions']['required_staff']['basis'] ?? '') === 'default'
                && empty($payload['review_confirmation']['actor_id']));
    }

    private function legacyDrawerState(?AiIntake $record, bool $canApprove): array
    {
        $state = ['action' => '', 'label' => '', 'status' => 'Noch nicht übernommen', 'reason' => '',
            'disabled' => false, 'editing' => false, 'customerDirty' => false, 'stale' => false,
            'workPending' => false, 'hasDirectOrders' => false, 'canAdd' => false];
        if (! $record) {
            return $state;
        }
        $state['stale'] = $record->revision !== $this->selectedRevision
            || $record->source_revision !== $this->selectedSourceRevision
            || $this->selectedSettingsRevision !== (int) AiDispositionSettings::all(true)['revision'];
        $state['editing'] = $this->manualEditorOpen || $this->editingProposalId !== null;
        $state['customerDirty'] = $this->hasUnsavedAssignment($record);
        $blocked = $record->paused_at || in_array($record->status, ['paused', 'not_inquiry', 'completed'], true);
        $proposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale');
        $state['hasDirectOrders'] = $proposals->contains(fn ($proposal) => ($proposal->payload['business_path'] ?? 'inquiry') === 'direct_order' && ! $proposal->inquiry?->order_id);
        $state['workPending'] = $record->processing_mode !== 'manual' || $state['hasDirectOrders']
            || $proposals->contains(fn ($proposal) => ! $proposal->inquiry || empty($proposal->payload['review_confirmation']['actor_id'])
                || ($proposal->payload['review_confirmation']['source_revision'] ?? null) !== $record->source_revision
                || $proposal->inquiry_revision !== $proposal->inquiry?->revision || $proposal->inquiry?->customer_id !== $record->customer_id);
        $state['canAdd'] = AiIntakeSchema::manualReady() && ! $blocked && ! $state['stale'] && ! $state['editing']
            && ! $state['customerDirty'] && $this->planningProposalId === null;
        if ($state['stale']) {
            return array_replace($state, ['action' => 'select('.$record->id.')', 'label' => 'Aktuellen Stand laden',
                'status' => 'Stand inzwischen geändert', 'reason' => 'Vor einer Änderung bitte den aktuellen Stand laden.']);
        }
        if ($this->detailTab !== 'review') {
            return array_replace($state, ['action' => "setDetailTab('review')", 'label' => 'Zur Bearbeitung',
                'status' => ($state['editing'] || $this->planningProposalId !== null) ? 'Entwurf in Bearbeitung'
                    : ($record->processing_mode === 'manual' ? 'Formularbearbeitung' : (self::LABELS[$record->status] ?? 'Prüfung nötig'))]);
        }
        if ($state['editing']) {
            return array_replace($state, ['action' => 'save-position', 'label' => 'Position speichern',
                'status' => 'Entwurf bearbeiten', 'reason' => 'Speichern erhält den Entwurf. Die Übernahme erfolgt erst danach.']);
        }
        if ($this->planningProposalId !== null) {
            return array_replace($state, ['action' => 'preparePlanning', 'label' => 'Schichtentwürfe erstellen',
                'status' => 'Schichten vorbereiten', 'reason' => 'Es werden unbesetzte, unveröffentlichte Entwürfe erstellt.']);
        }
        if ($this->customerEditorOpen || $state['customerDirty']) {
            return array_replace($state, ['action' => 'assignCustomer', 'label' => 'Zuordnung speichern',
                'status' => 'Kundenzuordnung bearbeiten']);
        }
        if ($blocked) {
            return array_replace($state, ['status' => self::LABELS[$record->status] ?? 'Prüfung nötig']);
        }
        if (! AiIntakeSchema::manualReady()) {
            return array_replace($state, ['action' => 'openAssist', 'label' => 'Mit AI-Assist öffnen',
                'reason' => 'Die Formularbearbeitung benötigt noch die Datenbankerweiterung.']);
        }
        if (! $record->customer_id) {
            return array_replace($state, ['action' => 'editCustomerAssignment', 'label' => 'Kunde zuordnen']);
        }
        if ($proposals->isEmpty()) {
            return array_replace($state, ['action' => 'addManualPosition', 'label' => 'Position per Formular erfassen']);
        }
        if ($proposals->contains(fn ($proposal) => ! in_array($proposal->status, ['applied', 'stale'], true)
            && (! $proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true))
            && ($proposal->status === 'failed' || $this->proposalNeedsCorrection($proposal->payload ?? [])))) {
            return array_replace($state, ['action' => 'reviewNextPosition', 'label' => 'Angaben ergänzen']);
        }
        if ($state['workPending']) {
            return array_replace($state, ['action' => 'confirmManual',
                'label' => $state['hasDirectOrders'] ? 'Bestätigen und Direktaufträge annehmen' : 'Positionen bestätigen und übernehmen',
                'reason' => 'Kunde, Positionen und Original persönlich prüfen.', 'disabled' => $state['hasDirectOrders'] && ! $canApprove]);
        }

        return array_replace($state, ['status' => 'Positionen übernommen', 'reason' => '']);
    }

    /** Native footer actions always belong to the explicitly displayed position. */
    private function drawerState(?AiIntake $record, bool $canApprove): array
    {
        $state = $this->legacyDrawerState($record, $canApprove) + [
            'activeProposalId' => null, 'primary' => null, 'secondary' => null, 'more' => [], 'cancelAction' => null,
        ];
        if (! $record) {
            return $state;
        }
        $proposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale')->sortBy('position_index');
        $proposal = $proposals->firstWhere('id', $this->drawerProposalId) ?? $proposals->first();
        $state['activeProposalId'] = $proposal?->id;
        $editing = $state['editing'] || $this->planningProposalId !== null || $this->customerEditorOpen || $state['customerDirty'];
        $blocked = (bool) $record->paused_at || in_array($record->status, ['paused', 'not_inquiry', 'completed'], true);
        $disabled = $editing || $state['stale'];
        $action = fn (string $key, string $label, ?string $command = null, ?string $href = null, bool $disable = false, ?string $confirm = null): array => [
            'key' => $key, 'label' => $label, 'action' => $command, 'href' => $href, 'disabled' => $disable, 'confirm' => $confirm,
        ];
        if ($record->status !== 'not_inquiry') {
            $state['more'][] = $action('assist', 'Mit AI-Assist öffnen', 'openAssist', disable: $disabled);
        }
        if ($record->deliveries->contains(fn ($delivery) => $delivery->status === 'draft' && ! ($delivery->metadata['manual_mailbox_test'] ?? false))) {
            $state['more'][] = $action('outbox', 'Kundennachrichten im AI-Assistenten prüfen', 'openOutbox', disable: $disabled);
        }
        if (! $blocked) {
            $state['more'][] = $action('customer', 'Kundenzuordnung ändern', 'editCustomerAssignment', disable: $disabled);
            if (AiIntakeSchema::manualReady()) {
                $state['more'][] = $action('add', 'Position per Formular erfassen', 'addManualPosition', disable: $disabled);
            }
            if (AiIntakeSchema::manualReady() && $proposal && ! in_array($proposal->status, ['applied', 'stale'], true)
                && (! $proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true))) {
                $state['more'][] = $action('edit', 'Leistung bearbeiten', 'editProposal('.$proposal->id.')', disable: $disabled);
            }
            if ($proposals->count() > 1 && $state['workPending']) {
                $state['more'][] = $action('confirm-all', $state['hasDirectOrders'] ? 'Alle Positionen gemäß Übernahmeweg bestätigen' : 'Alle Positionen als Anfragen übernehmen', 'confirmManual',
                    disable: $disabled || ($state['hasDirectOrders'] && ! $canApprove),
                    confirm: $state['hasDirectOrders'] ? 'Alle aktuellen Positionen übernehmen und die als Direktauftrag markierten Leistungen als Auftrag annehmen?' : 'Alle aktuellen Positionen als Anfragen übernehmen?');
            }
            $state['more'][] = $action('pause', 'Pausieren', 'pause', disable: $disabled);
            $state['more'][] = $action('reanalyze', 'Erneut prüfen', 'reanalyze', disable: $disabled || $record->processing_mode === 'manual'
                || (! AiDispositionSettings::enabled() && $record->source_type !== 'email') || $record->status === 'analyzing');
        }
        if (! $record->customer_id && $canApprove && ! $this->customerId && ! $blocked) {
            $state['more'][] = $action('new-customer', 'Neuen Kunden prüfen und anlegen', 'prepareCustomer', disable: $state['stale'] || $state['editing'] || $this->planningProposalId !== null);
        }
        if ($proposal && $canApprove && $record->processing_mode !== 'manual' && in_array($proposal->status, ['approved', 'failed'], true)) {
            $state['more'][] = $action('retry', 'Vorbereitung erneut prüfen', 'retryPreparation('.$proposal->id.','.$proposal->revision.')', disable: $disabled || $blocked);
        }
        $state['more'][] = $action('refresh', 'Aktuellen Stand laden', 'select('.$record->id.')', disable: $editing);

        if ($state['stale']) {
            $state['primary'] = $action('refresh', 'Aktuellen Stand laden', 'select('.$record->id.')', disable: $editing);

            return $state;
        }
        if ($this->detailTab !== 'review') {
            $state['primary'] = $action('review', 'Zur Bearbeitung', "setDetailTab('review')");

            return $state;
        }
        if ($state['editing']) {
            $state['primary'] = $action('save-position', 'Position speichern', 'save-position');
            $state['cancelAction'] = 'cancelPositionEdit';

            return $state;
        }
        if ($this->planningProposalId !== null) {
            $state['primary'] = $action('prepare', 'Schichtentwürfe erstellen', 'preparePlanning');
            $state['cancelAction'] = 'closePlanning';

            return $state;
        }
        if ($this->customerEditorOpen || $state['customerDirty']) {
            $state['primary'] = $action('save-customer', 'Zuordnung speichern', 'assignCustomer');
            $state['cancelAction'] = 'cancelCustomerAssignment';

            return $state;
        }
        if ($blocked && ! ($record->status === 'completed' && $proposal?->inquiry?->order_id)) {
            if ($proposal?->inquiry) {
                $inquiry = $proposal->inquiry;
                $state['primary'] = $inquiry->order_id && $canApprove
                    ? $action('order', 'Auftragsplanung öffnen', href: OperationsPages::url('cases', ['view' => 'orders', 'section' => 'planning', 'order' => $inquiry->order_id]))
                    : $action('inquiry', 'Anfrage / Angebot öffnen', href: OperationsPages::url('cases', ['view' => 'inbox', 'inquiry' => $inquiry->id]));
                foreach (Shift::whereIn('id', $proposal->applied_shift_ids ?? [])->orderBy('starts_at')->get() as $shift) {
                    $state['more'][] = $action('shift-'.$shift->id, 'Schicht öffnen · '.$shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i'),
                        href: OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'shift' => $shift->id,
                            'from' => $shift->starts_at->setTimezone($shift->timezone)->format('Y-m-d'),
                            'until' => $shift->starts_at->setTimezone($shift->timezone)->addDays(6)->format('Y-m-d'), 'distribution' => 'true']));
                }
            }

            return $state;
        }
        if ((! AiIntakeSchema::manualReady() && ! $proposal?->inquiry) || ! $record->customer_id || ! $proposal) {
            $state['primary'] = $action('next', $state['label'], $state['action'] ?: null, disable: $state['disabled']);

            return $state;
        }
        $args = $proposal->id.','.$proposal->revision;
        if ($proposal->error_code === 'reply_position_review') {
            $state['primary'] = $action('mapping', 'Positionszuordnung bestätigen', 'mapProposal('.$args.')');

            return $state;
        }
        $inquiry = $proposal->inquiry;
        if (! $inquiry || in_array($inquiry->status, ['new', 'verified'], true)) {
            if ($proposal->status === 'failed' || $this->proposalNeedsCorrection($proposal->payload ?? [])) {
                $state['primary'] = $action('correct', 'Angaben ergänzen', 'editProposal('.$proposal->id.')');
            } elseif (! $inquiry || empty($proposal->payload['review_confirmation']['actor_id'])
                || ($proposal->payload['review_confirmation']['source_revision'] ?? null) !== $record->source_revision
                || $proposal->inquiry_revision !== $inquiry->revision || $inquiry->customer_id !== $record->customer_id) {
                $state['primary'] = $action('confirm-inquiry', 'Als Anfrage übernehmen', "confirmDrawerPosition('inquiry')");
                $state['secondary'] = $action('confirm-direct', 'Direktauftrag annehmen', "confirmDrawerPosition('direct_order')", disable: ! $canApprove);
            } else {
                $state['primary'] = $action('inquiry', 'Anfrage / Angebot öffnen', 'continueInquiry('.$args.')');
                $state['secondary'] = $action('direct', 'Direktauftrag annehmen', 'takeDirectOrder('.$args.','.$inquiry->revision.')', disable: ! $canApprove);
            }

            return $state;
        }
        if (! $inquiry->order_id) {
            $state['primary'] = $action('inquiry', $inquiry->status === 'accepted' ? 'Auftrag anlegen' : 'Anfrage / Angebot öffnen', 'continueInquiry('.$args.')');
            $state['secondary'] = $action('direct', 'Direktauftrag annehmen', 'takeDirectOrder('.$args.','.$inquiry->revision.')', disable: ! $canApprove);

            return $state;
        }
        $orderUrl = OperationsPages::url('cases', ['view' => 'orders', 'section' => 'planning', 'order' => $inquiry->order_id]);
        if ($canApprove) {
            $state['secondary'] = $action('order', 'Auftragsplanung öffnen', href: $orderUrl);
        }
        if ($proposal->status !== 'applied') {
            if ($canApprove) {
                $state['primary'] = $action('plan', 'Schichten vorbereiten', 'openPlanning('.$args.')');
            }

            return $state;
        }
        $shifts = Shift::whereIn('id', $proposal->applied_shift_ids ?? [])->orderBy('starts_at')->get();
        $allPublished = $shifts->isNotEmpty() && $shifts->every(fn ($shift) => $shift->published_revision === $shift->revision
            && ! in_array($shift->status->value, ['draft', 'cancelled', 'completed'], true));
        $shiftActions = $shifts->map(fn ($shift) => $action('shift-'.$shift->id,
            ($allPublished ? 'Besetzung öffnen' : 'Einsatzort und Entwurf prüfen').' · '.$shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i'),
            href: OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'shift' => $shift->id,
                'from' => $shift->starts_at->setTimezone($shift->timezone)->format('Y-m-d'),
                'until' => $shift->starts_at->setTimezone($shift->timezone)->addDays(6)->format('Y-m-d'), 'distribution' => 'true'])))->all();
        if ($allPublished) {
            $state['primary'] = $shiftActions[0] ?? null;
            if ($state['primary']) {
                $state['primary']['label'] = 'Besetzung öffnen';
            }
        } elseif ($shifts->isNotEmpty() && $canApprove) {
            $state['primary'] = $action('publish', 'Zur Besetzung freigeben', 'publishPlanning('.$args.')',
                confirm: 'Diese Schichten nach Prüfung von Zeiten, Pausen und Einsatzort zur Besetzung freigeben?');
            $state['secondary'] = $shiftActions[0];
            $state['secondary']['label'] = 'Schichtentwürfe prüfen';
            $state['more'][] = $action('order', 'Auftragsplanung öffnen', href: $orderUrl);
        }
        $state['more'] = array_merge($state['more'], $shiftActions);

        return $state;
    }

    public function render()
    {
        $actor = $this->actor();
        $ready = AiIntakeSchema::ready();
        abort_unless(in_array($this->statusFilter, ['all', ...AiIntake::STATUSES], true), 422);
        abort_unless(in_array($this->detailTab, $this->embedded ? ['review', 'sources', 'communication', 'assistant'] : ['review', 'sources', 'communication', 'details'], true), 422);
        $query = $ready ? $this->scope()->with('customer')->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', '%'.mb_substr(trim($this->search), 0, 100).'%')) : null;
        $selected = $ready && $this->selectedId ? $this->selected()->load(['customer', 'contact', 'messages', 'attachments', 'runs', 'deliveries', 'proposals.inquiry.order']) : null;
        $confirmation = $selected ? app(AiIntakeService::class)->confirmationState($selected) : ['enabled' => false, 'completed' => false, 'reason' => ''];
        if ($this->editingProposalId !== null || $this->manualEditorOpen) {
            $confirmation = ['enabled' => false, 'completed' => false, 'reason' => 'Die offene Korrektur zuerst speichern.'];
        } elseif ($selected && $this->hasUnsavedAssignment($selected)) {
            $confirmation = ['enabled' => false, 'completed' => false, 'reason' => 'Die geänderte Kunden- oder Kontaktzuordnung zuerst speichern.'];
        }

        $activityCatalog = $selected && \Illuminate\Support\Facades\Schema::hasTable('duty_activities')
            ? \App\Models\DutyActivity::with('bundle')->orderBy('code')->get()->keyBy('id')
            : collect();

        $codes = $activityCatalog->isNotEmpty() ? \Illuminate\Support\Facades\DB::table('duty_activity_codes')->pluck('duty_activity_id', 'lookup') : collect();
        return view('livewire.operations.ai-intake-inbox', [
            'activityCatalog' => $codes->map(fn ($id) => $activityCatalog->get($id))->filter(),
            'ready' => $ready, 'enabled' => AiDispositionSettings::enabled(), 'labels' => self::LABELS, 'fieldLabels' => self::FIELD_LABELS,
            'intakes' => $query?->latest('id')->paginate(12, ['*'], 'aiIntakePage'), 'selected' => $selected,
            'customers' => Customer::active()->when($this->customerId, fn ($query) => $query->whereKey($this->customerId))->orderBy('company_name')->get(),
            'contacts' => $this->assignCustomerId !== '' ? CustomerContact::where('customer_id', (int) $this->assignCustomerId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'canApprove' => $actor->can('operations.manage'),
            'planningShifts' => $selected ? Shift::whereIn('id', $selected->proposals->pluck('applied_shift_ids')->flatten()->filter()->all())->get()->keyBy('id') : collect(),
            'confirmation' => $confirmation,
            'manualReady' => AiIntakeSchema::manualReady(),
            'qualifications' => QualificationType::where('is_active', true)->orderBy('name')->get(),
            'mappingInquiries' => $selected?->proposals->pluck('inquiry')->filter()->unique('id')->keyBy('id') ?? collect(),
            'customerSuggestions' => $selected ? app(AiIntakeCustomerSuggestions::class)->suggest($selected, $actor) : [],
            'contextHints' => $selected ? app(AiIntakeContextHints::class)->forReview($selected, $actor) : [],
            'limits' => array_intersect_key(AiDispositionSettings::all(), array_flip(['max_attachment_count', 'max_total_kilobytes', 'max_audio_kilobytes'])),
            'settingsRevision' => (int) AiDispositionSettings::all(true)['revision'],
            'drawerState' => $this->embedded ? [] : $this->drawerState($selected, $actor->can('operations.manage')),
        ]);
    }
}
