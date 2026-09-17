<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Services\Operations\OperationsAuditService;
use App\Services\Operations\OperationsReportService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use App\Support\Operations\ReportingPeriod;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class PersonnelReview extends Component
{
    use WithPagination;

    #[Locked]
    public string $module;

    public string $filter = 'pending';

    public string $search = '';

    public string $validity = 'all';

    public string $absenceView = 'list';

    public string $absenceKind = 'all';

    public string $from = '';

    public string $until = '';

    public function setAbsenceView(string $view): void
    {
        $this->access();
        abort_unless($this->module === 'absences' && in_array($view, ['list', 'calendar'], true), 422);
        $this->absenceView = $view;
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->resetPage();
    }

    public function updatedAbsenceKind(): void
    {
        $this->resetPage();
    }

    public function exportAbsences(OperationsReportService $service)
    {
        $this->access();
        abort_unless($this->module === 'absences', 403);
        $csv = $service->absences(auth()->user(), $this->from, $this->until, $this->filter, $this->absenceKind, $this->search);

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Abwesenheiten-'.$this->from.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function updatedValidity(): void
    {
        $this->access();
        abort_unless(in_array($this->validity, ['all', 'expired', '30', '90', 'future'], true), 422);
        if ($this->validity !== 'all') {
            $this->filter = 'approved';
        }
        $this->resetPage();
    }

    public array $notes = [];

    public bool $detailOpen = false;

    public bool $formOpen = false;

    public bool $typesOpen = false;

    #[Locked]
    public ?int $selectedId = null;

    #[On('operations-open-absence')]
    public function openDetails(int $id): void
    {
        $this->access();
        abort_if($this->module === 'rules', 404);
        ($this->module === 'qualifications' ? EmployeeQualification::query() : AbsenceRequest::query())->findOrFail($id);
        $this->selectedId = $id;
        $this->notes = [];
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function createRules(): void
    {
        $this->access();
        OperationsAccess::authorize(auth()->user(), 'operations.rules.manage');
        $this->reset('rules');
        $this->resetValidation();
        $this->formOpen = true;
    }

    public string $typeName = '';

    public array $rules = ['name' => '', 'minimum_rest_minutes' => '', 'maximum_shift_minutes' => '', 'break_after_minutes' => '', 'minimum_break_minutes' => '', 'confirmed' => false];

    public function mount(string $module): void
    {
        $this->module = $module;
        $this->access();
        if ($module === 'absences') {
            $this->from = now(config('operations.display_timezone'))->startOfMonth()->toDateString();
            $this->until = now(config('operations.display_timezone'))->endOfMonth()->toDateString();
        }
    }

    public function updatedFilter(): void
    {
        $this->validity = 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function access(): void
    {
        abort_unless(in_array($this->module, ['qualifications', 'absences', 'rules'], true), 404);
        OperationsAccess::authorize(auth()->user(), OperationsNavigation::modules()[$this->module]['ability']);
        OperationsAccess::requireReady();
    }

    public function decide(int $id, int $revision, string $action, PersonnelWorkflowService $service): void
    {
        $this->access();
        if ($this->module === 'qualifications') {
            $service->qualification(EmployeeQualification::findOrFail($id), $revision, $action, $this->notes[$id] ?? '', auth()->user());
        } elseif ($this->module === 'absences') {
            $service->absence(AbsenceRequest::findOrFail($id), $revision, $action, $this->notes[$id] ?? '', auth()->user());
        } else {
            abort(404);
        }
        unset($this->notes[$id]);
        $this->detailOpen = false;
        $this->resetValidation();
    }

    public function addType(): void
    {
        $this->access();
        OperationsAccess::authorize(auth()->user(), 'operations.qualifications.manage');
        $this->typeName = trim($this->typeName);
        $this->validate(['typeName' => 'required|string|max:180|unique:qualification_types,name']);
        $type = QualificationType::create(['name' => $this->typeName, 'is_active' => true]);
        app(OperationsAuditService::class)->record($type, auth()->user(), 'qualification_type.created');
        $this->typeName = '';
    }

    public function saveRules(PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->saveRules($this->rules, auth()->user());
        $this->rules['confirmed'] = false;
        $this->formOpen = false;
        session()->flash('operations.saved', 'Regelprofil gespeichert.');
    }

    public function render()
    {
        $this->access();
        $query = $this->module === 'absences' ? AbsenceRequest::with('user:id,name') : EmployeeQualification::with(['user:id,name', 'type']);
        $today = now(config('operations.display_timezone', 'Europe/Berlin'))->startOfDay();
        $selected = $this->selectedId && $this->module !== 'rules' ? (clone $query)->find($this->selectedId) : null;
        if ($this->module === 'absences') {
            $this->validate(['absenceKind' => 'in:all,vacation,unavailable,other', 'absenceView' => 'in:list,calendar']);
            if ($this->from && $this->until) {
                ReportingPeriod::apply($query, $this->from, $this->until, true);
            }
            $query->when($this->absenceKind !== 'all', fn ($q) => $q->where('kind', $this->absenceKind));
        }
        $certificates = $this->module === 'qualifications' && $this->detailOpen && $selected
            ? EmployeeQualification::where('user_id', $selected->user_id)->where('qualification_type_id', $selected->qualification_type_id)->where('status', 'approved')->whereHas('type', fn ($q) => $q->where('is_active', true))->get() : collect();
        if ($this->module === 'qualifications' && $this->validity !== 'all') {
            $query->where('status', 'approved');
            if ($this->validity === 'expired') {
                $query->whereDate('valid_until', '<', $today->toDateString());
            } elseif ($this->validity === 'future') {
                $query->whereDate('valid_from', '>', $today->toDateString());
            } elseif (in_array($this->validity, ['30', '90'], true)) {
                $query->whereDate('valid_until', '>=', $today->toDateString())->whereDate('valid_until', '<=', $today->copy()->addDays((int) $this->validity)->toDateString());
            }
        }

        return view('livewire.operations.personnel-review', [
            'selectedRecord' => $selected,
            'absenceConflicts' => $this->module === 'absences' && $this->detailOpen && $selected ? Shift::notCancelled()->during($selected->starts_at, $selected->ends_at)
                ->whereHas('assignments', fn ($q) => $q->blocking()->where('user_id', $selected->user_id))->with('order.customer')->orderBy('starts_at')->get() : collect(),
            'affectedShifts' => $this->module === 'qualifications' && $this->detailOpen && $selected ? Shift::notCancelled()->upcoming()
                ->whereHas('assignments', fn ($q) => $q->blocking()->where('user_id', $selected->user_id))
                ->whereHas('qualifications', fn ($q) => $q->where('qualification_types.id', $selected->qualification_type_id))
                ->with('order.customer')->orderBy('starts_at')->get()->filter(function ($shift) use ($certificates) {
                    return ! $certificates->contains(fn ($certificate) => $certificate->valid_from->toDateString() <= $shift->starts_at->toDateString() && $certificate->valid_until->toDateString() >= $shift->ends_at->toDateString());
                })->values() : collect(),
            'records' => $this->module === 'rules' ? null : $query->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))->when(filled($this->search), fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%')))->latest()->paginate(15),
            'types' => $this->module === 'qualifications' ? QualificationType::orderBy('name')->get() : collect(),
            'activeRules' => $this->module === 'rules' ? OperationsRuleProfile::where('is_active', true)->first() : null,
        ]);
    }
}
