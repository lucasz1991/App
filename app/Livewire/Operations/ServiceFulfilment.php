<?php

namespace App\Livewire\Operations;

use App\Livewire\Operations\Concerns\ManagesEnhancementRecords;
use App\Models\CustomerContact;
use App\Models\EmployeeQualification;
use App\Models\OperationsCostRate;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\CustomerProofService;
use App\Services\Operations\OperationalCostService;
use App\Services\Operations\OperationsPartnerService;
use App\Services\Operations\OperationsTravelService;
use App\Services\Operations\OperationsWorkflowService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\ReviewedInquiryImportService;
use App\Support\Exports\CsvDownload;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Leistungen rund um den Auftrag: Leistungsnachweise, Reisen, Partneranfragen,
 * Eingangsprüfung und Auftragskosten.
 *
 * Bis Phase 7 Teil der Sammelkomponente OperationsEnhancements; die
 * Zeitwirtschaft (Fachregeln, Monatsabschluss, Terminalzugang) liegt seitdem in
 * TimeAdministration. Der alte Komponentenname löst über
 * LivewireAliasServiceProvider auf diese Klasse auf.
 */
class ServiceFulfilment extends Component
{
    use ManagesEnhancementRecords, WithFileUploads;

    public const TABS = ['proofs' => 'Leistungsnachweise', 'travel' => 'Reisen', 'partners' => 'Partneranfragen', 'imports' => 'Eingangsprüfung', 'costs' => 'Auftragskosten'];

    /** Reiter der persönlichen Ansicht unter „Mein Arbeitstag“. */
    private const PERSONAL_TABS = ['proofs', 'travel', 'partners'];

    /** Reiter, die im Auftrag eingebettet werden dürfen. */
    private const ORDER_TABS = ['proofs', 'travel', 'costs'];

    #[Locked]
    public ?int $orderId = null;

    public ?int $contactId = null;

    public bool $duplicatesConfirmed = false;

    public array $qualificationIds = [];

    public $upload;

    public array $costResult = [];

    #[Locked]
    public bool $modalOnly = false;

    public function mount(bool $personal = false, string $tab = 'proofs', ?int $orderId = null, ?int $initialRecordId = null, bool $embedded = false, ?int $initialUserId = null, bool $modalOnly = false): void
    {
        $this->personal = $personal;
        $this->modalOnly = $modalOnly;
        $this->embedded = $embedded;
        $this->orderId = $orderId;
        $this->contextUserId = $initialUserId;
        if ($orderId) {
            OperationsAccess::authorize(auth()->user(), $tab === 'costs' ? 'operations.costs.manage' : 'operations.manage');
            Order::findOrFail($orderId);
        }
        $allowed = $this->tabs();
        abort_unless($allowed, 403);
        $this->tab = array_key_exists($tab, $allowed) ? $tab : array_key_first($allowed);
        $this->form = $this->blankTabForm();
        $this->access();
        if ($initialRecordId) {
            $this->openDetails($initialRecordId);
        } elseif ($modalOnly) {
            $this->create();
        }
    }

    protected function tabOffered(string $tab): bool
    {
        return (! $this->personal || in_array($tab, self::PERSONAL_TABS, true)) && (! $this->orderId || in_array($tab, self::ORDER_TABS, true));
    }

    protected function ability(string $tab): string
    {
        return match ($tab) {
            'costs' => 'operations.costs.manage', 'imports' => 'operations.inquiries.manage', default => 'operations.manage'
        };
    }

    protected function blankTabForm(): array
    {
        return $this->tab === 'costs' ? ['order_id' => $this->orderId ?? '', 'proof_id' => '', 'billing_cents' => ''] : [];
    }

    protected function resetTabState(): void
    {
        $this->reset('selectedId', 'selectedRevision', 'detailOpen', 'formOpen', 'form', 'note', 'costResult', 'upload', 'search');
    }

    protected function access(): User
    {
        $actor = $this->freshActor(requireReady: false);
        abort_unless($this->canTab($this->tab) && (! $this->personal || in_array($this->tab, self::PERSONAL_TABS, true)), 403);
        if ($this->personal) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            OperationsAccess::authorize($actor, $this->ability($this->tab));
            if ($this->contextUserId && $this->tab === 'costs') {
                app(PersonnelScopeService::class)->authorize($actor, $this->contextUserId, $this->ability($this->tab));
            }
            if ($this->orderId) {
                OperationsAccess::authorize($actor, $this->tab === 'costs' ? 'operations.costs.manage' : 'operations.manage');
                abort_unless(in_array($this->tab, self::ORDER_TABS, true), 403);
                Order::findOrFail($this->orderId);
            }
        }

        return $actor;
    }

    private function query()
    {
        $actor = $this->access();
        if ($this->tab === 'costs') {
            return app(PersonnelScopeService::class)->applyRelatedQuery(OperationsCostRate::with('user:id,name,role,status'), $actor, 'operations.costs.manage')->orderByDesc('id');
        }
        $kind = ['proofs' => 'proof', 'travel' => 'travel', 'partners' => 'partner', 'imports' => 'import'][$this->tab];
        $query = OperationWorkflow::with('user:id,name', 'order')->where('kind', $kind)->when($this->orderId, fn ($q) => $q->where('order_id', $this->orderId))->when($this->search !== '', fn ($q) => $q->where('title', 'like', '%'.mb_substr($this->search, 0, 100).'%'))->orderByDesc('id');
        if ($this->personal) {
            return $query->where('user_id', $actor->id);
        }
        if ($kind !== 'import') {
            app(PersonnelScopeService::class)->applyRelatedQuery($query, $actor, 'employees.master-data.view');
        }

        return $query;
    }

    public function create(): void
    {
        $this->access();
        OperationsEnhancementsSchema::requireReady();
        abort_if($this->personal && $this->tab === 'partners', 403);
        $this->reset('selectedId', 'selectedRevision', 'upload', 'note');
        $this->resetValidation();
        $this->form = match ($this->tab) {
            'proofs' => ['order_id' => '', 'shift_id' => '', 'title' => '', 'rows' => [['activity' => '', 'quantity' => '', 'unit' => '']], 'note' => ''],
            'travel' => ['shift_id' => '', 'title' => '', 'travel_kind' => 'train', 'starts_at' => '', 'ends_at' => '', 'destination' => '', 'note' => ''],
            'partners' => ['shift_id' => '', 'user_id' => '', 'partner_name' => '', 'deadline' => '', 'note' => ''],
            'imports' => ['format' => 'text', 'source' => '', 'reference' => ''],
            'costs' => ['user_id' => '', 'starts_on' => '', 'ends_on' => '', 'hourly_cents' => '', 'currency' => 'EUR'],
        };
        if ($this->contextUserId && array_key_exists('user_id', $this->form)) {
            $this->form['user_id'] = $this->contextUserId;
        }
        if ($this->orderId && array_key_exists('order_id', $this->form)) {
            $this->form['order_id'] = $this->orderId;
        }
        $this->formOpen = true;
    }

    public function addRow(): void
    {
        $this->access();
        abort_unless($this->tab === 'proofs' && count($this->form['rows'] ?? []) < 40, 422);
        $this->form['rows'][] = ['activity' => '', 'quantity' => '', 'unit' => ''];
    }

    public function save(): void
    {
        $actor = $this->access();
        OperationsEnhancementsSchema::requireReady();
        $data = $this->nulls($this->form);
        if ($this->orderId && in_array($this->tab, ['proofs', 'travel'], true)) {
            abort_if(isset($data['order_id']) && (int) $data['order_id'] !== $this->orderId, 403);
            if (! empty($data['shift_id'])) {
                Shift::where('order_id', $this->orderId)->findOrFail((int) $data['shift_id']);
            }
        }
        $result = match ($this->tab) {
            'proofs' => app(CustomerProofService::class)->create($data, $actor),
            'travel' => app(OperationsTravelService::class)->request($data, $actor),
            'partners' => app(OperationsPartnerService::class)->request($data, $actor),
            'imports' => app(ReviewedInquiryImportService::class)->preview($data['format'], $data['source'], $data['reference'], $actor),
            'costs' => app(OperationalCostService::class)->configure(User::findOrFail((int) $data['user_id']), $data, $actor),
        };
        $this->reset('form', 'upload');
        $this->formOpen = false;
        $this->openDetails($result->id);
    }

    public function openDetails(int $id): void
    {
        $actor = $this->access();
        OperationsEnhancementsSchema::requireReady();
        $record = $this->query()->findOrFail($id);
        if ($record instanceof OperationWorkflow) {
            app(OperationsWorkflowService::class)->authorize($record, $actor);
        }
        $this->selectedId = $record->id;
        $this->selectedRevision = $record->revision ?? 1;
        $this->form = $record instanceof OperationWorkflow ? $record->payload : [];
        if ($this->tab === 'travel') {
            $this->form += ['booking_reference' => '', 'cancel_until' => '', 'actual_cents' => ''];
        }
        if ($this->tab === 'imports') {
            $this->form['rows'] = array_map(fn ($row) => $row + ['title' => '', 'customer_id' => '', 'starts_at' => '', 'ends_at' => '', 'timezone' => '', 'role_name' => '', 'required_staff' => '', 'location_name' => ''], $this->form['rows'] ?? []);
        }
        if ($this->tab === 'costs') {
            $this->form += ['order_id' => $this->orderId ?? '', 'proof_id' => '', 'billing_cents' => ''];
        }
        $this->reset('upload', 'note', 'contactId', 'qualificationIds', 'duplicatesConfirmed');
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function act(string $action): void
    {
        $actor = $this->access();
        OperationsEnhancementsSchema::requireReady();
        abort_unless($this->selectedId && $this->selectedRevision, 422);
        $record = $this->query()->findOrFail($this->selectedId);
        if ($this->tab === 'proofs') {
            if ($action === 'revise') {
                app(CustomerProofService::class)->revise($record->id, $this->selectedRevision, $this->form['rows'] ?? [], $this->note, $actor);
            } elseif ($action === 'submit') {
                app(CustomerProofService::class)->submit($record->id, $this->selectedRevision, $actor);
            } else {
                app(CustomerProofService::class)->decide($record->id, $this->selectedRevision, $action, $this->note, $this->contactId, $actor);
            }
        } elseif ($this->tab === 'travel') {
            app(OperationsTravelService::class)->decide($record->id, $this->selectedRevision, $action, ['note' => $this->note] + $this->nulls(array_intersect_key($this->form, array_flip(['booking_reference', 'cancel_until', 'actual_cents']))), $actor);
        } elseif ($this->tab === 'partners') {
            if (in_array($action, ['consent', 'revoke'], true)) {
                app(OperationsPartnerService::class)->consent($record->id, $this->selectedRevision, $action === 'consent', array_map('intval', $this->qualificationIds), $actor);
            } else {
                abort_unless($action === 'prepare', 422);
                app(OperationsPartnerService::class)->prepare($record->id, $this->selectedRevision, $actor);
            }
        } elseif ($this->tab === 'imports') {
            if ($action === 'review') {
                app(ReviewedInquiryImportService::class)->review($record->id, $this->selectedRevision, $this->form['rows'] ?? [], $this->duplicatesConfirmed, $actor);
            } else {
                abort_unless($action === 'convert', 422);
                app(ReviewedInquiryImportService::class)->createInquiries($record->id, $this->selectedRevision, $actor);
            }
        } else {
            abort(422);
        }
        $this->openDetails($record->id);
    }

    public function saveDeviation(): void
    {
        $actor = $this->access();
        $record = app(CustomerProofService::class)->deviation($this->selectedId, $this->selectedRevision, $this->note, $actor);
        $this->openDetails($record->id);
    }

    public function uploadFile(): void
    {
        $actor = $this->access();
        $record = $this->query()->findOrFail($this->selectedId);
        abort_unless($this->upload, 422);
        if ($this->tab === 'proofs') {
            app(CustomerProofService::class)->attachment($record->id, $this->selectedRevision, $this->upload, $actor);
        } elseif ($this->tab === 'travel') {
            app(OperationsTravelService::class)->receipt($record->id, $this->selectedRevision, $this->upload, $actor);
        } else {
            abort(403);
        }
        $this->openDetails($record->id);
    }

    public function saveBilling(): void
    {
        $actor = $this->access();
        abort_unless($this->tab === 'costs', 403);
        $proof = OperationWorkflow::where('kind', 'proof')->where('status', 'accepted')->when($this->orderId, fn ($q) => $q->where('order_id', $this->orderId))->findOrFail((int) ($this->form['proof_id'] ?? 0));
        app(OperationalCostService::class)->setBillingAmount($proof->id, $proof->revision, (int) ($this->form['billing_cents'] ?? -1), $actor);
        $this->reset('form');
        if ($this->orderId) {
            $this->form['order_id'] = $this->orderId;
        }
    }

    public function calculateCosts(): void
    {
        $actor = $this->access();
        abort_unless($this->tab === 'costs', 403);
        abort_if($this->orderId && (int) ($this->form['order_id'] ?? 0) !== $this->orderId, 403);
        $this->costResult = app(OperationalCostService::class)->order(Order::findOrFail($this->orderId ?? (int) ($this->form['order_id'] ?? 0)), $actor);
    }

    public function exportPartner()
    {
        $actor = $this->access();
        abort_unless($this->tab === 'partners' && ! $this->personal, 403);
        $record = $this->query()->findOrFail($this->selectedId);
        abort_unless($record->revision === $this->selectedRevision, 409);
        $data = app(OperationsPartnerService::class)->projection($record->id, $actor);

        return CsvDownload::streamContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'RailTime-Partneranfrage-'.$record->id.'-r'.$record->revision.'.json', CsvDownload::JSON);
    }

    public function render()
    {
        $actor = $this->access();
        $ready = $this->ready();
        $records = $ready ? $this->query()->paginate(15) : collect();
        $selected = $ready && $this->selectedId ? $this->query()->find($this->selectedId) : null;
        $staff = collect();
        if (! $this->personal && $ready && $this->formOpen && in_array($this->tab, ['partners', 'costs'], true)) {
            $staff = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff')->where('status', true), $actor, $this->tab === 'costs' ? 'operations.costs.manage' : 'employees.master-data.view')->orderBy('name')->get(['id', 'name']);
        }
        $contacts = $selected instanceof OperationWorkflow && $selected->order_id && $this->tab === 'proofs' && ! $this->personal ? CustomerContact::where('customer_id', $selected->order->customer_id)->where('is_active', true)->get()->filter(fn ($c) => in_array('acceptance', $c->roles ?? [])) : collect();
        $ownProofs = $this->personal && $this->tab === 'partners' && $ready ? EmployeeQualification::where('user_id', $actor->id)->where('status', 'approved')->with('type')->get() : collect();
        $shifts = $ready && $this->formOpen && in_array($this->tab, ['proofs', 'travel', 'partners']) ? Shift::whereNotIn('status', $this->tab === 'proofs' ? ['cancelled'] : ['cancelled', 'completed'])->when($this->orderId, fn ($q) => $q->where('order_id', $this->orderId))->when($this->tab !== 'partners', fn ($q) => $q->where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->whereHas('assignments', fn ($a) => $a->where('user_id', $actor->id)->whereIn('status', ['requested', 'confirmed'])->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')))->orderByDesc('starts_at')->limit(100)->get(['id', 'order_id', 'title']) : collect();
        $orders = $ready && $this->tab === 'proofs' ? Order::whereIn('id', $shifts->pluck('order_id'))->orderBy('title')->get(['id', 'title', 'order_number']) : collect();
        $acceptedProofs = $ready && $this->tab === 'costs' && app(PersonnelScopeService::class)->visibleUserIds($actor, 'operations.costs.manage') === null ? OperationWorkflow::where('kind', 'proof')->where('status', 'accepted')->when($this->orderId, fn ($q) => $q->where('order_id', $this->orderId))->latest('id')->limit(100)->get(['id', 'title', 'revision']) : collect();

        return view('livewire.operations.service-fulfilment', compact('ready', 'records', 'selected', 'staff', 'contacts', 'ownProofs', 'shifts', 'orders', 'acceptedProofs'));
    }
}
