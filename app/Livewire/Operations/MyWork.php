<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\MyWork\AnswersWorkRequests;
use App\Livewire\Concerns\MyWork\ExportsOwnTimes;
use App\Livewire\Concerns\MyWork\ManagesAbsenceRequests;
use App\Livewire\Concerns\MyWork\ManagesQualificationRecords;
use App\Livewire\Concerns\MyWork\ShowsPersonalCalendar;
use App\Livewire\Concerns\MyWork\ShowsWorkdayOverview;
use App\Livewire\Concerns\MyWork\TracksWorkTime;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\WorkdayOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Mein Arbeitstag (/mein-arbeitstag). Die Klasse hält Reiter, Zugriff und render();
 * die Bereiche liegen als Concerns unter App\Livewire\Concerns\MyWork:
 *
 * - ShowsWorkdayOverview        Reiter „Heute" (Tagesleiste, Aufgaben, Kurzstatus)
 * - AnswersWorkRequests         Reiter „Anfragen" und Anfrage-Dialog
 * - ShowsPersonalCalendar       Reiter „Kalender" und Termin-Dialog
 * - TracksWorkTime              Reiter „Zeiten" und Stempeluhr
 * - ExportsOwnTimes             CSV-Ausgaben der eigenen Zeiten (v1 und v2)
 * - ManagesAbsenceRequests      Fokusansicht „Urlaub & Abwesenheit"
 * - ManagesQualificationRecords Fokusansicht „Qualifikationen"
 *
 * Öffentliche Properties und Methoden behalten ihre Namen: offene Browser-Sitzungen
 * rufen sie mit ihrem bisherigen Snapshot auf.
 */
class MyWork extends Component
{
    use AnswersWorkRequests;
    use ExportsOwnTimes;
    use ManagesAbsenceRequests;
    use ManagesQualificationRecords;
    use ShowsPersonalCalendar;
    use ShowsWorkdayOverview;
    use TracksWorkTime;
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'today';

    /**
     * Abwesenheiten und Qualifikationen haben eigene Menüpunkte und laufen
     * als fokussierte Einzelansicht; der Arbeitstag selbst behält nur den Tag.
     */
    #[Locked]
    public string $focus = '';

    private function tabs(): array
    {
        return match ($this->focus) {
            'absences' => ['absences' => 'Urlaub & Abwesenheit'],
            'records' => ['records' => 'Qualifikationen'],
            default => ['today' => 'Heute', 'requests' => 'Anfragen', 'schedule' => 'Kalender', 'time' => 'Zeiten'],
        };
    }

    public function openForm(string $form): void
    {
        $this->access();
        abort_unless(in_array($form, ['manual', 'qualification', 'absence'], true), 404);
        $this->reset(['manualOpen', 'correctionOpen', 'qualificationOpen', 'absenceOpen', 'generalManualOpen', 'sectionsOpen', 'sectionsId']);
        if ($form === 'manual') {
            $this->reset(['manualAssignmentId', 'manualPlanRevision']);
        }
        $this->resetValidation();
        $this->{$form.'Open'} = true;
    }

    #[Locked]
    public string $eventKey;

    public function mount(string $focus = '', string $initialTab = '', array $initialRequest = []): void
    {
        $this->access();
        $this->eventKey = (string) Str::uuid();
        $this->today();
        $this->overviewDate = CarbonImmutable::now($this->displayTimezone())->toDateString();
        $this->setCurrentTimePeriod();
        $this->focus = in_array($focus, ['absences', 'records'], true) ? $focus : '';
        $queryTab = request()->query('tab', '');
        $initialTab = $initialTab ?: (is_string($queryTab) ? $queryTab : '');
        $this->tab = $this->focus ?: (array_key_exists($initialTab, $this->tabs()) ? $initialTab : 'today');
        // "Urlaub planen" auf dem Arbeitstag öffnet den Antrag direkt.
        if ($this->focus === 'absences' && request()->boolean('new')) {
            $this->absenceOpen = true;
        }
        if ($this->focus === 'records' && request()->boolean('new')) {
            $this->qualificationOpen = true;
        }
        if ($this->focus === '') {
            $this->initializeRequest($initialRequest ?: request()->only(['request_type', 'request_id', 'request_revision']));
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
        abort_unless(array_key_exists($tab, $this->tabs()), 404);
        $this->tab = $tab;
        $this->closeCalendarEvent();
        $this->closeConsentRequest();
        $this->reset(['manualOpen', 'correctionOpen', 'qualificationOpen', 'absenceOpen', 'tasksOpen', 'generalManualOpen', 'generalStartOpen']);
        $this->resetValidation();
    }

    private function displayTimezone(): string
    {
        return (string) config('operations.display_timezone', 'Europe/Berlin');
    }

    /** Domain validators use bare keys; bind errors to the visible form field. */
    private function withFormErrors(string $prefix, callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $key => $errors) {
                $key = ['startsAt' => 'starts_at', 'endsAt' => 'ends_at'][$key] ?? $key;
                $field = $key === 'file' && $prefix === 'qualification' ? 'evidence'
                    : (array_key_exists($key, $this->{$prefix}) ? $prefix.'.'.$key : $key);
                if ($prefix === 'absence' && $this->absenceWholeDays && in_array($key, ['starts_at', 'ends_at'], true)) {
                    $field = $key === 'starts_at' ? 'absenceFirstDay' : 'absenceLastDay';
                }
                $messages[$field] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Veröffentlichte eigene Dienste für den aktiven Reiter. „loaded" ist die breitere Quelle für
     * Tagesleiste und Anfragen (nur Heute/Anfragen), „visible" das angezeigte Fenster.
     */
    private function workdayAssignments(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $loaded = null;
        $rangeStart = $this->tab === 'schedule' ? $from : CarbonImmutable::now($this->displayTimezone())->startOfDay();
        $rangeEnd = $this->tab === 'schedule' ? $to : $rangeStart->addDays(14);
        if ($this->tab === 'today') {
            // Gemeinsame veröffentlichte Quelle für Tagesleiste und Dienste; geladen wird unverändert
            // ab Wochenbeginn des laufenden Monats (früheres Mini-Kalender-Fenster).
            $monthStart = CarbonImmutable::now($this->displayTimezone())->startOfMonth()->startOfWeek();
            // Keep the 14-day duty view and month intact while the request inbox
            // covers every future published request, including later months.
            $loaded = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart->min($monthStart)->min(WorkdayOverview::stripStart($this->overviewDay())));
            $visible = $loaded->filter(fn ($assignment) => $assignment->shift->ends_at->gt($rangeStart) && $assignment->shift->starts_at->lt($rangeEnd))->values();
        } elseif ($this->tab === 'requests') {
            $loaded = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart);
            $visible = $loaded->filter(fn ($assignment) => $assignment->shift->ends_at->gt($rangeStart) && $assignment->shift->starts_at->lt($rangeEnd))->values();
        } else {
            $visible = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart, $rangeEnd);
        }

        return ['loaded' => $loaded, 'visible' => $visible];
    }

    public function render()
    {
        $this->access();
        $this->forgetRequestCaches();
        abort_unless(array_key_exists($this->tab, $this->tabs()), 404);
        $timeQuery = $this->timeEntriesQuery();
        $window = $this->calendarWindow();
        ['loaded' => $loaded, 'visible' => $visible] = $this->workdayAssignments($window['from'], $window['to']);
        $requestInbox = $this->requestInbox($loaded);

        $activeTime = $this->activeTimeEntry();
        $visible->each(fn ($assignment) => $assignment->setAttribute('has_active_time', $activeTime !== null));
        $clockOffers = $this->clockOffers($visible, $activeTime);
        $workdayOverview = $this->workdayOverviewData($loaded, $activeTime !== null);

        $calendarGrid = $this->calendarGrid($visible, $window);
        $calendarSelection = $this->selectedCalendarEventData();
        $selectedRequest = $this->selectedRequestData();

        $timeCompleteness = $this->timeCompleteness();
        $requestHistory = $this->tab === 'requests' ? $this->requestHistory($loaded) : null;

        return view('livewire.operations.my-work', [
            'personalTabs' => $this->tabs(),
            'displayTimezone' => $this->displayTimezone(),
            'assignments' => $visible,
            ...$this->todayViewData($workdayOverview, $timeCompleteness),
            ...$this->requestViewData($requestInbox, $requestHistory, $selectedRequest),
            ...$this->calendarViewData($window, $calendarGrid, $calendarSelection),
            ...$this->timeViewData($timeQuery, $timeCompleteness, $clockOffers, $activeTime),
            ...$this->absencesViewData(),
            ...$this->recordsViewData(),
        ]);
    }
}
