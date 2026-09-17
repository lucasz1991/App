<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\QualificationType;
use App\Models\ShiftAssignment;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanChangeService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MyWork extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'today';

    public int $week = 0;

    public string $anchorDate = '';

    public string $viewMode = 'week';

    public bool $calendarEventOpen = false;

    #[Locked]
    public ?string $selectedCalendarEventId = null;

    public bool $manualOpen = false;

    public bool $correctionOpen = false;

    public bool $qualificationOpen = false;

    public bool $absenceOpen = false;

    public string $timeFrom = '';

    public string $timeUntil = '';

    public function updatedTimeFrom(): void
    {
        $this->resetPage('timesPage');
    }

    public function updatedTimeUntil(): void
    {
        $this->resetPage('timesPage');
    }

    public function exportOwnTimes(\App\Services\Operations\OperationsReportService $service)
    {
        $this->access();
        $csv = $service->ownTimes(auth()->user(), $this->timeFrom, $this->timeUntil);

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Meine-Zeiten-'.$this->timeFrom.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function openForm(string $form): void
    {
        $this->access();
        abort_unless(in_array($form, ['manual', 'qualification', 'absence'], true), 404);
        $this->reset(['manualOpen', 'correctionOpen', 'qualificationOpen', 'absenceOpen']);
        $this->resetValidation();
        $this->{$form.'Open'} = true;
    }

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
        $this->manualOpen = false;
        session()->flash('operations.saved', 'Zeit nachgetragen.');
    }

    public function mount(): void
    {
        $this->access();
        $this->eventKey = (string) Str::uuid();
        $this->today();
        if (request()->query('tab') === 'schedule') {
            $this->tab = 'schedule';
        }
    }

    private function access(): void
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());
    }

    public function showTab(string $tab): void
    {
        $this->access();
        abort_unless(in_array($tab, ['today', 'schedule', 'time', 'records', 'absences'], true), 404);
        $this->tab = $tab;
        $this->closeCalendarEvent();
        $this->reset(['manualOpen', 'correctionOpen', 'qualificationOpen', 'absenceOpen']);
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
        $this->resetValidation();
        $this->correctionOpen = true;
        $this->correctingRevision = $entry->revision;
        $this->correction = ['starts_at' => $entry->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $entry->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => intdiv($entry->pause_seconds, 60), 'note' => ''];
    }

    public function correct(WorkTimeService $service): void
    {
        $this->access();
        $service->correct($this->correctingId, $this->correctingRevision, $this->correction, auth()->user());
        $this->correctingId = null;
        $this->correctionOpen = false;
    }

    public function cancelCorrection(): void
    {
        $this->access();
        $this->correctingId = null;
        $this->correctionOpen = false;
    }

    public function requestAbsence(PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->requestAbsence(auth()->user(), $this->absence);
        $this->reset('absence');
        $this->absenceOpen = false;
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
        $this->qualificationOpen = false;
        session()->flash('operations.saved', 'Nachweis eingereicht.');
    }

    private function displayTimezone(): string
    {
        return (string) config('operations.display_timezone', 'Europe/Berlin');
    }

    private function anchor(): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->anchorDate, $this->displayTimezone());
            if ($date && $date->toDateString() === $this->anchorDate) {
                return $date;
            }
        } catch (\Throwable) {
        }

        return CarbonImmutable::now($this->displayTimezone())->startOfDay();
    }

    public function updatedAnchorDate(): void
    {
        $this->access();
        $this->closeCalendarEvent();
        $this->validate(['anchorDate' => 'required|date_format:Y-m-d']);
    }

    public function updatedWeek(): void
    {
        $this->access();
        $this->anchorDate = CarbonImmutable::now($this->displayTimezone())->startOfWeek()->addWeeks(max(-12, min(52, $this->week)))->toDateString();
        $this->closeCalendarEvent();
    }

    public function today(): void
    {
        $this->access();
        $this->anchorDate = CarbonImmutable::now($this->displayTimezone())->toDateString();
        $this->week = 0;
        $this->closeCalendarEvent();
        $this->resetValidation('anchorDate');
    }

    public function switchView(string $view): void
    {
        $this->access();
        abort_unless(in_array($view, ['day', 'week', 'month', 'list'], true), 404);
        $this->viewMode = $view;
        $this->closeCalendarEvent();
    }

    public function showDay(string $date): void
    {
        $this->access();
        $this->anchorDate = $date;
        $this->updatedAnchorDate();
        $this->viewMode = 'day';
    }

    public function previousPeriod(): void
    {
        $this->movePeriod(-1);
    }

    public function nextPeriod(): void
    {
        $this->movePeriod(1);
    }

    private function movePeriod(int $direction): void
    {
        $this->access();
        $date = $this->anchor();
        $this->anchorDate = match ($this->viewMode) {
            'day' => $date->addDays($direction)->toDateString(),
            'month' => $date->addMonthsNoOverflow($direction)->toDateString(),
            default => $date->addWeeks($direction)->toDateString(),
        };
        $this->closeCalendarEvent();
        $this->resetValidation('anchorDate');
    }

    public function openCalendarEvent(string $id): void
    {
        $this->access();
        $event = $this->calendarEvent($id);
        if ($event->kind === 'shift') {
            app(PlanChangeService::class)->opened($event->record->id, $event->record->plan_revision, auth()->user());
        }
        $this->selectedCalendarEventId = $id;
        $this->calendarEventOpen = true;
        $this->resetValidation();
    }

    public function closeCalendarEvent(): void
    {
        $this->access();
        $this->calendarEventOpen = false;
        $this->selectedCalendarEventId = null;
    }

    private function event(object $record, string $kind): object
    {
        $scheduled = $kind === 'shift' ? $record->shift : $record;

        return (object) [
            'id' => $kind.'-'.$record->id,
            'kind' => $kind,
            'record' => $record,
            'starts' => $scheduled->starts_at->setTimezone($this->displayTimezone()),
            'ends' => $scheduled->ends_at->setTimezone($this->displayTimezone()),
            'title' => $kind === 'shift' ? $scheduled->title : (['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit'][$record->kind] ?? 'Abwesenheit'),
            'status' => $kind === 'shift' ? $record->status->value : $record->status,
        ];
    }

    private function calendarEvent(string $id): object
    {
        abort_unless(preg_match('/\A(shift|absence)-([1-9][0-9]{0,17})\z/', $id, $match), 404);
        if ($match[1] === 'shift') {
            $record = app(PersonalSchedule::class)->assignment(auth()->user(), (int) $match[2]);
            $record->setAttribute('has_active_time', WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->exists());
        } else {
            $record = AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved'])->find((int) $match[2]);
            abort_unless($record, 404);
        }

        return $this->event($record, $match[1]);
    }

    public function render()
    {
        $this->access();
        abort_unless(in_array($this->tab, ['today', 'schedule', 'time', 'records', 'absences'], true), 404);
        $timeQuery = WorkTimeEntry::where('user_id', auth()->id());
        if ($this->tab === 'time' && $this->timeFrom && $this->timeUntil) {
            \App\Support\Operations\ReportingPeriod::apply($timeQuery, $this->timeFrom, $this->timeUntil);
        }
        abort_unless(in_array($this->viewMode, ['day', 'week', 'month', 'list'], true), 404);
        $anchor = $this->anchor();
        $from = match ($this->viewMode) {
            'day' => $anchor,
            'month' => $anchor->startOfMonth()->startOfWeek(),
            default => $anchor->startOfWeek(),
        };
        $to = match ($this->viewMode) {
            'day' => $from->addDay(),
            'month' => $anchor->endOfMonth()->endOfWeek()->addDay()->startOfDay(),
            default => $from->addWeek(),
        };
        $rangeStart = $this->tab === 'schedule' ? $from : CarbonImmutable::now($this->displayTimezone())->startOfDay();
        $rangeEnd = $this->tab === 'schedule' ? $to : $rangeStart->addDays(14);
        $visible = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart, $rangeEnd);

        $activeTime = WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->first();
        $visible->each(fn ($assignment) => $assignment->setAttribute('has_active_time', $activeTime !== null));

        $events = $this->tab === 'schedule' ? $visible->map(fn ($assignment) => $this->event($assignment, 'shift'))
            ->concat(AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved'])
                ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()
                ->map(fn ($absence) => $this->event($absence, 'absence')))
            ->sortBy(fn ($event) => $event->starts->getTimestamp())->values() : collect();
        $days = collect(range(0, (int) $from->diffInDays($to) - 1))->map(function ($offset) use ($from, $anchor, $events) {
            $date = $from->addDays($offset);

            return ['date' => $date, 'is_today' => $date->isToday(), 'in_month' => $date->month === $anchor->month,
                'events' => $events->filter(fn ($event) => $event->starts->lt($date->addDay()) && $event->ends->gt($date))->values()];
        });
        $selected = null;
        if ($this->calendarEventOpen && $this->selectedCalendarEventId) {
            try {
                $selected = $this->calendarEvent($this->selectedCalendarEventId);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 404) {
                    throw $exception;
                }
                $this->closeCalendarEvent();
            }
        }

        return view('livewire.operations.my-work', [
            'from' => $from, 'to' => $to,
            'calendarDays' => $days, 'calendarEvents' => $events, 'selectedCalendarEvent' => $selected,
            'selectedPlanChanges' => $selected?->kind === 'shift' ? app(PlanChangeService::class)->publishedChanges($selected->record->shift_id, $selected->record->plan_revision) : [],
            'displayTimezone' => $this->displayTimezone(),
            'periodLabel' => match ($this->viewMode) {
                'day' => $anchor->locale('de')->isoFormat('dddd, D. MMMM YYYY'),
                'month' => $anchor->locale('de')->isoFormat('MMMM YYYY'),
                default => $from->format('d.m.').' – '.$to->subDay()->format('d.m.Y'),
            },
            'assignments' => $visible,
            'activeTime' => $activeTime,
            'times' => $timeQuery->latest('starts_at')->paginate(20, ['*'], 'timesPage'),
            'qualifications' => EmployeeQualification::where('user_id', auth()->id())->with('type')->latest()->paginate(20, ['*'], 'qualificationsPage'),
            'absences' => AbsenceRequest::where('user_id', auth()->id())->latest()->paginate(20, ['*'], 'absencesPage'),
            'types' => QualificationType::where('is_active', true)->orderBy('name')->get(),
            'manualAssignments' => $this->tab === 'time' ? ShiftAssignment::where('user_id', auth()->id())->where('status', 'confirmed')->whereDoesntHave('timeEntry')->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->whereColumn('published_revision', 'revision')->where('starts_at', '<=', now()->utc())->where('ends_at', '>=', now()->subDays(90)->utc())->where('status', '!=', 'cancelled'))->with('shift')->limit(50)->get() : collect(),
        ]);
    }
}
