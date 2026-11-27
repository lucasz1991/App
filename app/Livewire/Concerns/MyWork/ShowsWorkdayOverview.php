<?php

namespace App\Livewire\Concerns\MyWork;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsReminderService;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\Operations\WorkdayOverview;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Reiter „Heute": Tagesleiste, Aufgaben-Dialog und der Kurzstatus der ausgelagerten Bereiche.
 *
 * Erwartet in der Komponente: access(), displayTimezone(), $tab und $focus.
 */
trait ShowsWorkdayOverview
{
    public string $overviewDate = '';

    public bool $tasksOpen = false;

    public function selectOverviewDay(string $date): void
    {
        $this->access();
        abort_unless($this->focus === '' && WorkdayOverview::date($date, $this->displayTimezone()), 404);
        $this->overviewDate = $date;
        $this->tab = 'today';
    }

    public function moveOverviewDays(int $direction): void
    {
        $this->access();
        abort_unless(in_array($direction, [-1, 1], true), 404);
        $this->selectOverviewDay($this->overviewDay()->addDays($direction * 7)->toDateString());
    }

    public function resetOverviewDay(): void
    {
        $this->selectOverviewDay(CarbonImmutable::now($this->displayTimezone())->toDateString());
    }

    public function updatedOverviewDate(): void
    {
        $this->selectOverviewDay($this->overviewDate);
    }

    private function overviewDay(): CarbonImmutable
    {
        $date = WorkdayOverview::date($this->overviewDate ?: CarbonImmutable::now($this->displayTimezone())->toDateString(), $this->displayTimezone());
        abort_unless($date, 404);

        return $date;
    }

    public function openTasks(): void
    {
        $this->access();
        abort_unless($this->focus === '', 404);
        $this->tasksOpen = true;
    }

    public function closeTasks(): void
    {
        $this->access();
        $this->tasksOpen = false;
    }

    private function taskCount(): ?int
    {
        if (! Schema::hasTable('personnel_tasks') && ! app(OperationsReminderService::class)->ready()
            && ! app(PersonnelEnhancementService::class)->ready() && ! CustomerPortalIntakeSchema::ready()) {
            return null;
        }

        return app(UnifiedOperationsInboxService::class)->items(auth()->user(), true)->count();
    }

    /**
     * Kurzstatus der ausgelagerten Bereiche, damit auf dem Arbeitstag sichtbar
     * bleibt, was ansteht – ohne deren Listen hier erneut zu führen.
     */
    private function glance(?array $completeness = null): array
    {
        $userId = auth()->id();
        $now = now();
        $nextAbsence = AbsenceRequest::where('user_id', $userId)->where('status', 'approved')->where('ends_at', '>', $now->utc())->orderBy('starts_at')->first(['id', 'kind', 'starts_at', 'ends_at']);

        return [
            'time_complete' => $completeness['complete'] ?? null,
            'time_issue_count' => $completeness ? count($completeness['missing_shift_times']) + count($completeness['missing_training_times'] ?? []) + count($completeness['unfinished_times']) : null,
            'open_times' => WorkTimeSchema::ready() ? WorkTimeEntry::where('user_id', $userId)->whereIn('status', ['completed', 'returned'])->count() : 0,
            'pending_absences' => AbsenceRequest::where('user_id', $userId)->where('status', 'pending')->count(),
            'next_absence' => $nextAbsence?->starts_at->setTimezone($this->displayTimezone()),
            'expiring_qualifications' => EmployeeQualification::where('user_id', $userId)->where('status', 'approved')
                ->whereDate('valid_until', '>=', $now->toDateString())->whereDate('valid_until', '<=', $now->copy()->addDays(30)->toDateString())->count(),
        ];
    }

    private function workdayOverviewData(?Collection $loaded, bool $hasActiveTime): ?array
    {
        $workdayOverview = $this->tab === 'today' ? WorkdayOverview::make($loaded, $this->overviewDay(), CarbonImmutable::now($this->displayTimezone())) : null;
        if ($workdayOverview !== null) {
            $workdayOverview['assignments']->each(fn ($assignment) => $assignment->setAttribute('has_active_time', $hasActiveTime));
        }

        return $workdayOverview;
    }

    /** Daten für den Reiter „Heute". */
    private function todayViewData(?array $workdayOverview, ?array $timeCompleteness): array
    {
        return [
            'workdayOverview' => $workdayOverview,
            'taskCount' => $this->tab === 'today' ? $this->taskCount() : null,
            'glance' => $this->tab === 'today' ? $this->glance($timeCompleteness) : [],
        ];
    }
}
