<?php

namespace App\Livewire\Concerns\MyWork;

use App\Models\AbsenceRequest;
use App\Models\PersonnelTraining;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PlanChangeService;
use App\Support\Operations\PersonalCalendarLayout;
use App\Support\Operations\PersonalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reiter „Kalender" und der Termin-Dialog: Zeitraum wählen, Ansicht wechseln,
 * eigene Dienste, Abwesenheiten und Schulungen als Termine zeigen.
 *
 * Erwartet in der Komponente: access(), displayTimezone(), showTab(), $tab sowie aus
 * AnswersWorkRequests shiftRequestRow(), firstPublishedRevisions(), requestLocationPreview()
 * und requestCalendar().
 */
trait ShowsPersonalCalendar
{
    public int $week = 0;

    public string $anchorDate = '';

    public string $viewMode = 'week';

    public bool $calendarEventOpen = false;

    #[Locked]
    public ?string $selectedCalendarEventId = null;

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

    /** Select a date without leaving the month or week overview. */
    public function selectCalendarDay(string $date): void
    {
        $this->access();
        $this->anchorDate = $date;
        $this->updatedAnchorDate();
    }

    /** Sprung aus dem Mini-Kalender in die Tagesansicht des großen Kalenders. */
    public function openDay(string $date): void
    {
        $this->access();
        abort_unless(preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1, 404);
        $this->showTab('schedule');
        $this->showDay($date);
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
            'title' => in_array($kind, ['shift', 'training'], true) ? $scheduled->title : (['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit', 'sick' => 'Krankmeldung'][$record->kind] ?? 'Abwesenheit'),
            'status' => $kind === 'shift' ? $record->status->value : $record->status,
        ];
    }

    private function calendarEvent(string $id): object
    {
        abort_unless(preg_match('/\A(shift|absence|training)-([1-9][0-9]{0,17})\z/', $id, $match), 404);
        if ($match[1] === 'shift') {
            $record = app(PersonalSchedule::class)->assignment(auth()->user(), (int) $match[2]);
            $record->setAttribute('has_active_time', WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->exists());
        } elseif ($match[1] === 'training') {
            abort_unless(Schema::hasTable('personnel_trainings'), 404);
            $record = PersonnelTraining::where('status', 'scheduled')->whereHas('participants', fn ($q) => $q->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']))->find((int) $match[2]);
            abort_unless($record, 404);
        } else {
            $record = AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved', 'reported'])->find((int) $match[2]);
            abort_unless($record, 404);
        }

        return $this->event($record, $match[1]);
    }

    /** Sichtbarer Zeitraum der gewählten Ansicht: Anker, erster Tag und der Tag nach dem letzten. */
    private function calendarWindow(): array
    {
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

        return ['anchor' => $anchor, 'from' => $from, 'to' => $to];
    }

    /** Termine und Tagesraster; Termine werden nur im Reiter „Kalender" geladen. */
    private function calendarGrid(Collection $visible, array $window): array
    {
        ['anchor' => $anchor, 'from' => $from, 'to' => $to] = $window;
        $events = $this->tab === 'schedule' ? $visible->map(fn ($assignment) => $this->event($assignment, 'shift'))
            ->concat(AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved', 'reported'])
                ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()
                ->map(fn ($absence) => $this->event($absence, 'absence')))
            ->sortBy(fn ($event) => $event->starts->getTimestamp())->values() : collect();
        if ($this->tab === 'schedule' && Schema::hasTable('personnel_trainings')) {
            $events = $events->concat(PersonnelTraining::where('status', 'scheduled')->whereHas('participants', fn ($q) => $q->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']))->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()->map(fn ($training) => $this->event($training, 'training')))->sortBy(fn ($event) => $event->starts->getTimestamp())->values();
        }
        $days = collect(range(0, (int) $from->diffInDays($to) - 1))->map(function ($offset) use ($from, $anchor, $events) {
            $date = $from->addDays($offset);

            return ['date' => $date, 'is_today' => $date->isToday(), 'in_month' => $date->month === $anchor->month,
                'events' => $events->filter(fn ($event) => $event->starts->lt($date->addDay()) && $event->ends->gt($date))->values()];
        });

        return ['days' => $days, 'events' => $events];
    }

    /** Der im Dialog geöffnete Termin samt Planänderungen und Anfrage-Darstellung. */
    private function selectedCalendarEventData(): array
    {
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
        $selectedPlanChanges = $selected?->kind === 'shift' ? app(PlanChangeService::class)->publishedChanges($selected->record->shift_id, $selected->record->plan_revision) : [];
        $selectedCalendarRequest = null;
        if ($selected?->kind === 'shift') {
            $assignment = $selected->record;
            $row = $this->shiftRequestRow($assignment, $this->firstPublishedRevisions(collect([$assignment])));
            $row['label'] = $assignment->status->value === 'requested' ? $row['label'] : 'Dienst';
            $snapshot = array_key_exists('location_snapshot', $assignment->shift->getAttributes()) ? $assignment->shift->getAttribute('location_snapshot') : null;
            $row['location_preview'] = $this->requestLocationPreview($row, $snapshot);
            $selectedCalendarRequest = ['can_respond' => false, 'notice' => $assignment->plan_is_stale ? 'Der Dienst wird gerade überarbeitet. Angezeigt wird der veröffentlichte Stand.' : '',
                'row' => $row, 'changes' => $row['changed_plan'] ? $selectedPlanChanges : []];
        }

        return ['selected' => $selected, 'planChanges' => $selectedPlanChanges, 'request' => $selectedCalendarRequest];
    }

    /** Daten für den Reiter „Kalender" und den Termin-Dialog. */
    private function calendarViewData(array $window, array $grid, array $selection): array
    {
        ['anchor' => $anchor, 'from' => $from, 'to' => $to] = $window;

        return [
            'from' => $from, 'to' => $to,
            'calendarDays' => $grid['days'], 'calendarEvents' => $grid['events'], 'selectedCalendarEvent' => $selection['selected'],
            'calendarLayout' => $this->tab === 'schedule' ? PersonalCalendarLayout::make($grid['days'], $anchor) : null,
            'selectedPlanChanges' => $selection['planChanges'],
            'selectedCalendarRequest' => $selection['request'],
            'selectedCalendarContext' => $this->requestCalendar($selection['request']),
            'periodLabel' => match ($this->viewMode) {
                'day' => $anchor->locale('de')->isoFormat('dddd, D. MMMM YYYY'),
                'month' => $anchor->locale('de')->isoFormat('MMMM YYYY'),
                default => $from->format('d.m.').' – '.$to->subDay()->format('d.m.Y'),
            },
        ];
    }
}
