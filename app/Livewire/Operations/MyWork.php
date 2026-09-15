<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\QualificationType;
use App\Models\ShiftAssignment;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class MyWork extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'today';

    public int $week = 0;

    #[Locked]
    public string $eventKey;

    #[Locked]
    public ?int $correctingId = null;

    #[Locked]
    public ?int $correctingRevision = null;

    public array $correction = [];

    public array $absence = ['kind' => 'vacation', 'starts_at' => '', 'ends_at' => '', 'timezone' => 'Europe/Berlin', 'note' => ''];

    public array $qualification = ['qualification_type_id' => '', 'valid_from' => '', 'valid_until' => ''];

    public $evidence;

    public ?int $manualAssignmentId = null;

    public array $manualTime = ['starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    public function saveManual(WorkTimeService $service): void
    {
        $this->access();
        $this->validate(['manualAssignmentId' => 'required|integer']);
        $assignment = ShiftAssignment::where('user_id', auth()->id())->findOrFail($this->manualAssignmentId);
        $service->manual($assignment->id, $assignment->plan_revision, $this->manualTime, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
        $this->reset(['manualTime', 'manualAssignmentId']);
        session()->flash('operations.saved', 'Zeit nachgetragen.');
    }

    public function mount(): void
    {
        $this->access();
        $this->eventKey = (string) Str::uuid();
    }

    private function access(): void
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());
    }

    public function showTab(string $tab): void
    {
        $this->access();
        abort_unless(in_array($tab, ['today', 'schedule', 'time', 'records'], true), 404);
        $this->tab = $tab;
        $this->resetValidation();
    }

    public function respond(int $id, int $revision, bool $accept, PlanPublicationService $service): void
    {
        $this->access();
        $service->respond($id, $revision, $accept, auth()->user());
    }

    public function start(int $id, int $revision, WorkTimeService $service): void
    {
        $this->access();
        $service->start($id, $revision, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
    }

    public function clock(int $id, int $revision, string $action, WorkTimeService $service): void
    {
        $this->access();
        $service->clock($id, $revision, $action, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
    }

    public function editTime(int $id): void
    {
        $this->access();
        $entry = WorkTimeEntry::where('user_id', auth()->id())->findOrFail($id);
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 403);
        $this->correctingId = $entry->id;
        $this->correctingRevision = $entry->revision;
        $this->correction = ['starts_at' => $entry->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $entry->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => intdiv($entry->pause_seconds, 60), 'note' => ''];
    }

    public function correct(WorkTimeService $service): void
    {
        $this->access();
        $service->correct($this->correctingId, $this->correctingRevision, $this->correction, auth()->user());
        $this->correctingId = null;
    }

    public function cancelCorrection(): void
    {
        $this->access();
        $this->correctingId = null;
    }

    public function requestAbsence(PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->requestAbsence(auth()->user(), $this->absence);
        $this->reset('absence');
        session()->flash('operations.saved', 'Antrag eingereicht.');
    }

    public function withdraw(int $id, int $revision, PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->absence(AbsenceRequest::where('user_id', auth()->id())->findOrFail($id), $revision, 'withdraw', '', auth()->user());
    }

    public function upload(PersonnelWorkflowService $service): void
    {
        $this->access();
        $this->validate(['evidence' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('operations.evidence_max_kilobytes')]);
        $service->submitQualification(auth()->user(), $this->qualification, $this->evidence);
        $this->reset(['evidence', 'qualification']);
        session()->flash('operations.saved', 'Nachweis eingereicht.');
    }

    public function render()
    {
        $this->access();
        abort_unless(in_array($this->tab, ['today', 'schedule', 'time', 'records'], true), 404);
        $from = now(config('operations.display_timezone'))->startOfWeek()->addWeeks(max(-12, min(52, $this->week)));
        $to = $from->copy()->addWeek();
        $rangeStart = $this->tab === 'schedule' ? $from : now(config('operations.display_timezone'))->startOfDay();
        $rangeEnd = $this->tab === 'schedule' ? $to : now()->addDays(14);
        $visible = ShiftAssignment::where('user_id', auth()->id())->whereIn('status', ['requested', 'confirmed'])
            ->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->where('status', '!=', 'cancelled')->where(fn ($q) => $q->during($rangeStart, $rangeEnd)->orWhereColumn('published_revision', '!=', 'revision')))->with(['shift.order.customer', 'timeEntry'])->get();
        $visible = $visible->map(function ($assignment) {
            $shift = $assignment->shift;
            $assignment->setAttribute('plan_is_stale', $shift->published_revision !== $shift->revision);
            if ($assignment->plan_is_stale && $shift->published_snapshot) {
                $snapshot = $shift->published_snapshot;
                foreach (['starts_at', 'ends_at'] as $key) {
                    $snapshot[$key] = CarbonImmutable::parse($snapshot[$key])->utc();
                }
                $shift->forceFill($snapshot);
                if (array_key_exists('order_id', $snapshot)) {
                    $shift->unsetRelation('order')->load('order.customer');
                }
            }

            return $assignment;
        })->filter(fn ($a) => $a->shift->starts_at->lt($rangeEnd) && $a->shift->ends_at->gt($rangeStart))->sortBy(fn ($a) => $a->shift->starts_at);

        return view('livewire.operations.my-work', [
            'from' => $from, 'to' => $to,
            'assignments' => $visible,
            'activeTime' => WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->first(),
            'times' => WorkTimeEntry::where('user_id', auth()->id())->latest('starts_at')->paginate(20, ['*'], 'timesPage'),
            'qualifications' => EmployeeQualification::where('user_id', auth()->id())->with('type')->latest()->paginate(20, ['*'], 'qualificationsPage'),
            'absences' => AbsenceRequest::where('user_id', auth()->id())->latest()->paginate(20, ['*'], 'absencesPage'),
            'types' => QualificationType::where('is_active', true)->orderBy('name')->get(),
            'manualAssignments' => $this->tab === 'time' ? ShiftAssignment::where('user_id', auth()->id())->where('status', 'confirmed')->whereDoesntHave('timeEntry')->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->whereColumn('published_revision', 'revision')->where('starts_at', '<=', now()->utc())->where('ends_at', '>=', now()->subDays(90)->utc())->where('status', '!=', 'cancelled'))->with('shift')->limit(50)->get() : collect(),
        ]);
    }
}
