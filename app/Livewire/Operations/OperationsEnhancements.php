<?php

namespace App\Livewire\Operations;

use App\Models\CustomerContact;
use App\Models\EmployeeQualification;
use App\Models\OperationsCostRate;
use App\Models\OperationsMonthClosing;
use App\Models\OperationsRateRule;
use App\Models\OperationsTerminalProfile;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\CustomerProofService;
use App\Services\Operations\OperationalCostService;
use App\Services\Operations\OperationsPartnerService;
use App\Services\Operations\OperationsRuleEvaluationService;
use App\Services\Operations\OperationsTravelService;
use App\Services\Operations\OperationsWorkflowService;
use App\Services\Operations\PayrollClosingService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\ReviewedInquiryImportService;
use App\Services\Operations\WorkTimeTerminalService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class OperationsEnhancements extends Component
{
    use WithFileUploads, WithPagination;

    public const TABS = ['proofs' => 'Leistungsnachweise', 'travel' => 'Reisen', 'partners' => 'Partneranfragen', 'imports' => 'Eingangsprüfung', 'rules' => 'Fachregeln', 'payroll' => 'Monatsabschluss', 'costs' => 'Auftragskosten', 'terminal' => 'Terminalzugang'];

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public string $tab = 'proofs';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $selectedRevision = null;

    public bool $formOpen = false;

    public bool $detailOpen = false;

    public string $search = '';

    public array $form = [];

    public string $note = '';

    public ?int $contactId = null;

    public bool $duplicatesConfirmed = false;

    public array $qualificationIds = [];

    public $upload;

    public array $costResult = [];

    public function mount(bool $personal = false, string $tab = 'proofs'): void
    {
        $this->personal = $personal;
        $allowed = $this->tabs();
        abort_unless($allowed, 403);
        $this->tab = array_key_exists($tab, $allowed) ? $tab : array_key_first($allowed);
        $this->form = $this->tab === 'costs' ? ['order_id' => '', 'proof_id' => '', 'billing_cents' => ''] : [];
        $this->access();
    }

    public function ready(): bool
    {
        return OperationsEnhancementsSchema::ready();
    }

    public function tabs(): array
    {
        return array_filter(self::TABS, fn ($label, $key) => (! $this->personal || in_array($key, ['proofs', 'travel', 'partners', 'terminal'])) && $this->canTab($key), ARRAY_FILTER_USE_BOTH);
    }

    private function ability(string $tab): string
    {
        return match ($tab) {
            'rules' => 'operations.rules.manage', 'payroll' => 'operations.time.review', 'costs' => 'operations.costs.manage', 'imports' => 'operations.inquiries.manage', 'terminal' => 'operations.terminal.manage', default => 'operations.manage'
        };
    }

    private function canTab(string $tab): bool
    {
        $actor = auth()->user()?->fresh();

        return $actor && $actor->status && ($this->personal ? OperationsAccess::isEmployee($actor) : $actor->can($this->ability($tab)));
    }

    private function access(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor && $this->canTab($this->tab) && (! $this->personal || in_array($this->tab, ['proofs', 'travel', 'partners', 'terminal'])), 403);
        if ($this->personal) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            OperationsAccess::authorize($actor, $this->ability($this->tab));
        }

        return $actor;
    }

    public function setTab(string $tab): void
    {
        $this->access();
        abort_unless(array_key_exists($tab, $this->tabs()), 403);
        $this->tab = $tab;
        $this->reset('selectedId', 'selectedRevision', 'detailOpen', 'formOpen', 'form', 'note', 'costResult', 'upload', 'search');
        $this->form = $this->tab === 'costs' ? ['order_id' => '', 'proof_id' => '', 'billing_cents' => ''] : [];
        $this->resetPage();
    }

    private function query()
    {
        $actor = $this->access();
        if ($this->tab === 'rules') {
            return OperationsRateRule::with('user:id,name,role,status')->orderByDesc('id');
        }
        if ($this->tab === 'payroll') {
            return app(PersonnelScopeService::class)->applyRelatedQuery(OperationsMonthClosing::with('user'), $actor, 'operations.time.review')->orderByDesc('id');
        }
        if ($this->tab === 'costs') {
            return app(PersonnelScopeService::class)->applyRelatedQuery(OperationsCostRate::with('user:id,name,role,status'), $actor, 'operations.costs.manage')->orderByDesc('id');
        }
        if ($this->tab === 'terminal') {
            $query = OperationsTerminalProfile::with('user:id,name,role,status');

            return $this->personal ? $query->where('user_id', $actor->id) : app(PersonnelScopeService::class)->applyRelatedQuery($query, $actor, 'operations.terminal.manage');
        }
        $kind = ['proofs' => 'proof', 'travel' => 'travel', 'partners' => 'partner', 'imports' => 'import'][$this->tab];
        $query = OperationWorkflow::with('user', 'order')->where('kind', $kind)->when($this->search !== '', fn ($q) => $q->where('title', 'like', '%'.mb_substr($this->search, 0, 100).'%'))->orderByDesc('id');
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
            'rules' => ['name' => '', 'kind' => 'planning', 'user_id' => '', 'starts_on' => '', 'ends_on' => '', 'confirmed' => false, 'configuration' => ['type' => 'rolling_minutes', 'days' => '', 'limit' => '', 'timezone' => config('operations.display_timezone'), 'activity' => 'all', 'window_start' => '', 'window_end' => '', 'wage_code' => '', 'multiplier_bps' => '', 'rounding_minutes' => 0, 'rounding_mode' => 'none', 'additive' => false, 'weekdays' => []]],
            'payroll' => ['user_id' => '', 'month_date' => ''],
            'costs' => ['user_id' => '', 'starts_on' => '', 'ends_on' => '', 'hourly_cents' => '', 'currency' => 'EUR'],
            'terminal' => ['user_id' => '', 'pin' => '', 'terminal_id' => '', 'location_consent' => false, 'latitude' => '', 'longitude' => '', 'radius_metres' => ''],
        };
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
        $result = match ($this->tab) {
            'proofs' => app(CustomerProofService::class)->create($data, $actor),
            'travel' => app(OperationsTravelService::class)->request($data, $actor),
            'partners' => app(OperationsPartnerService::class)->request($data, $actor),
            'imports' => app(ReviewedInquiryImportService::class)->preview($data['format'], $data['source'], $data['reference'], $actor),
            'rules' => app(OperationsRuleEvaluationService::class)->configure($data, $actor),
            'payroll' => app(PayrollClosingService::class)->prepare(User::findOrFail((int) $data['user_id']), CarbonImmutable::parse($data['month_date'])->format('Y-m'), $actor),
            'costs' => app(OperationalCostService::class)->configure(User::findOrFail((int) $data['user_id']), $data, $actor),
            'terminal' => app(WorkTimeTerminalService::class)->configure($this->personal ? $actor : User::findOrFail((int) $data['user_id']), $data, $actor),
        };
        $this->reset('form', 'upload');
        $this->formOpen = false;
        $this->openDetails($result->id);
    }

    private function nulls(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? $this->nulls($value) : ($value === '' ? null : $value);
        }

        return $data;
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
            $this->form += ['order_id' => '', 'proof_id' => '', 'billing_cents' => ''];
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
        } elseif ($this->tab === 'payroll') {
            if ($action === 'close') {
                app(PayrollClosingService::class)->close($record->id, $this->selectedRevision, $actor);
            } elseif ($action === 'reopen') {
                app(PayrollClosingService::class)->reopen($record->id, $this->selectedRevision, $this->note, $actor);
            } elseif ($action === 'prepare') {
                app(PayrollClosingService::class)->prepare(User::findOrFail($record->user_id), $record->month, $actor);
            } else {
                abort(422);
            }
        } else {
            abort(422);
        }
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

    public function exportPayroll(bool $delta = false, ?int $revision = null)
    {
        $actor = $this->access();
        abort_unless($this->tab === 'payroll', 403);
        $record = $this->query()->findOrFail($this->selectedId);
        $revision ??= $this->selectedRevision;
        $csv = app(PayrollClosingService::class)->csv($record->id, $revision, $actor, $delta);

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Lohnvorbereitung-'.$record->month.'-r'.$revision.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function returnTime(int $id, int $revision): void
    {
        $actor = $this->access();
        abort_unless($this->tab === 'payroll', 403);
        $closing = $this->query()->findOrFail($this->selectedId);
        abort_unless($closing->status === 'reopened', 409);
        $a = CarbonImmutable::parse($closing->month.'-01', $closing->timezone)->utc();
        $b = $a->setTimezone($closing->timezone)->addMonth()->utc();
        $entry = WorkTimeEntry::where('user_id', $closing->user_id)->where('starts_at', '<', $b)->where('ends_at', '>', $a)->findOrFail($id);
        app(PayrollClosingService::class)->returnForCorrection($entry->id, $revision, $this->note, $actor);
        $this->openDetails($closing->id);
    }

    public function saveBilling(): void
    {
        $actor = $this->access();
        abort_unless($this->tab === 'costs', 403);
        $proof = OperationWorkflow::where('kind', 'proof')->where('status', 'accepted')->findOrFail((int) ($this->form['proof_id'] ?? 0));
        app(OperationalCostService::class)->setBillingAmount($proof->id, $proof->revision, (int) ($this->form['billing_cents'] ?? -1), $actor);
        $this->reset('form');
    }

    public function calculateCosts(): void
    {
        $actor = $this->access();
        abort_unless($this->tab === 'costs', 403);
        $this->costResult = app(OperationalCostService::class)->order(Order::findOrFail((int) ($this->form['order_id'] ?? 0)), $actor);
    }

    public function exportPartner()
    {
        $actor = $this->access();
        abort_unless($this->tab === 'partners' && ! $this->personal, 403);
        $record = $this->query()->findOrFail($this->selectedId);
        abort_unless($record->revision === $this->selectedRevision, 409);
        $data = app(OperationsPartnerService::class)->projection($record->id, $actor);

        return response()->streamDownload(fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'RailTime-Partneranfrage-'.$record->id.'-r'.$record->revision.'.json', ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function render()
    {
        $actor = $this->access();
        $ready = $this->ready();
        $records = $ready ? $this->query()->paginate(15) : collect();
        $selected = $ready && $this->selectedId ? $this->query()->find($this->selectedId) : null;
        $staff = collect();
        if (! $this->personal && $ready && in_array($this->tab, ['partners', 'payroll', 'costs', 'rules', 'terminal'])) {
            $ability = match ($this->tab) {
                'payroll' => 'operations.time.review', 'costs' => 'operations.costs.manage', 'rules' => 'operations.rules.manage','terminal' => 'operations.terminal.manage', default => 'employees.master-data.view'
            };
            $staff = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff')->where('status', true), $actor, $ability)->orderBy('name')->get(['id', 'name']);
        }
        $contacts = $selected instanceof OperationWorkflow && $selected->order_id && $this->tab === 'proofs' && ! $this->personal ? CustomerContact::where('customer_id', $selected->order->customer_id)->where('is_active', true)->get()->filter(fn ($c) => in_array('acceptance', $c->roles ?? [])) : collect();
        $ownProofs = $this->personal && $this->tab === 'partners' && $ready ? EmployeeQualification::where('user_id', $actor->id)->where('status', 'approved')->with('type')->get() : collect();
        $shifts = $ready && in_array($this->tab, ['proofs', 'travel', 'partners']) ? Shift::whereNotIn('status', $this->tab === 'proofs' ? ['cancelled'] : ['cancelled', 'completed'])->when($this->tab !== 'partners', fn ($q) => $q->where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->whereHas('assignments', fn ($a) => $a->where('user_id', $actor->id)->whereIn('status', ['requested', 'confirmed'])->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')))->orderByDesc('starts_at')->limit(100)->get(['id', 'order_id', 'title']) : collect();
        $orders = $ready && $this->tab === 'proofs' ? Order::whereIn('id', $shifts->pluck('order_id'))->orderBy('title')->get(['id', 'title', 'order_number']) : collect();
        $closedVersions = $selected instanceof OperationsMonthClosing ? $selected->revisions()->where('action', 'closed')->latest('revision')->get(['id', 'revision']) : collect();
        $correctionTimes = $selected instanceof OperationsMonthClosing && $selected->status === 'reopened' ? WorkTimeEntry::where('user_id', $selected->user_id)->where('status', 'approved')->where('starts_at', '<', CarbonImmutable::parse($selected->month.'-01', $selected->timezone)->addMonth()->utc())->where('ends_at', '>', CarbonImmutable::parse($selected->month.'-01', $selected->timezone)->utc())->get() : collect();
        $acceptedProofs = $ready && $this->tab === 'costs' && app(PersonnelScopeService::class)->visibleUserIds($actor, 'operations.costs.manage') === null ? OperationWorkflow::where('kind', 'proof')->where('status', 'accepted')->latest('id')->limit(100)->get(['id', 'title', 'revision']) : collect();

        return view('livewire.operations.operations-enhancements', compact('ready', 'records', 'selected', 'staff', 'contacts', 'ownProofs', 'shifts', 'orders', 'closedVersions', 'correctionTimes', 'acceptedProofs'));
    }
}
