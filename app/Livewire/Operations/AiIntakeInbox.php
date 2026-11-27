<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\AiIntake\BuildsIntakeDrawerState;
use App\Livewire\Concerns\AiIntake\ManagesIntakeCapture;
use App\Livewire\Concerns\AiIntake\ManagesIntakeCustomerAssignment;
use App\Livewire\Concerns\AiIntake\ManagesIntakeDetail;
use App\Livewire\Concerns\AiIntake\ManagesIntakeList;
use App\Livewire\Concerns\AiIntake\ManagesIntakePlanning;
use App\Livewire\Concerns\AiIntake\ManagesIntakePositions;
use App\Livewire\Concerns\AiIntake\ManagesIntakeRelease;
use App\Livewire\Concerns\ManagesAssistantUploads;
use App\Livewire\Concerns\ResetsPageOnFilterChange;
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
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiIntakeInbox extends Component
{
    use BuildsIntakeDrawerState, ManagesAssistantUploads, ManagesIntakeCapture, ManagesIntakeCustomerAssignment, ManagesIntakeDetail,
        ManagesIntakeList, ManagesIntakePlanning, ManagesIntakePositions, ManagesIntakeRelease, ResetsPageOnFilterChange,
        ResolvesOperationsActor, WithFileUploads, WithPagination;

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

    /** Vorgaben für ResetsPageOnFilterChange; Trait-Methoden lassen sich nur in der Klasse überschreiben. */
    protected function pageResetProperties(): array
    {
        return ['search', 'statusFilter'];
    }

    protected function filterPageName(): string
    {
        return 'aiIntakePage';
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

    public function render()
    {
        $actor = $this->actor();
        $ready = AiIntakeSchema::ready();
        abort_unless(in_array($this->statusFilter, ['all', ...AiIntake::STATUSES], true), 422);
        abort_unless(in_array($this->detailTab, $this->embedded ? ['review', 'sources', 'communication', 'assistant'] : ['review', 'sources', 'communication', 'details'], true), 422);
        $selected = $ready && $this->selectedId ? $this->selected()->load(['customer', 'contact', 'messages', 'attachments', 'runs', 'deliveries', 'proposals.inquiry.order']) : null;
        $confirmation = $selected ? app(AiIntakeService::class)->confirmationState($selected) : ['enabled' => false, 'completed' => false, 'reason' => ''];
        if ($this->editingProposalId !== null || $this->manualEditorOpen) {
            $confirmation = ['enabled' => false, 'completed' => false, 'reason' => 'Die offene Korrektur zuerst speichern.'];
        } elseif ($selected && $this->hasUnsavedAssignment($selected)) {
            $confirmation = ['enabled' => false, 'completed' => false, 'reason' => 'Die geänderte Kunden- oder Kontaktzuordnung zuerst speichern.'];
        }

        return view('livewire.operations.ai-intake-inbox', [
            'activityCatalog' => $selected ? \App\Models\DutyActivity::catalogByCode() : collect(),
            'ready' => $ready, 'enabled' => AiDispositionSettings::enabled(), 'labels' => self::LABELS, 'fieldLabels' => self::FIELD_LABELS,
            'intakes' => $this->intakePage($ready), 'selected' => $selected,
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
