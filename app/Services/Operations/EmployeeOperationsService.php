<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Exact employee assignments, not attendance or published personal schedules. */
class EmployeeOperationsService
{
    public const PAGE_NAME = 'employeeShiftsPage';

    public const PER_PAGE = 15;

    public const MAX_PAGE = 1000000;

    public const ZONE = 'Europe/Berlin';

    private const SHIFT_FIELDS = [
        'id', 'order_id', 'public_id', 'title', 'role_name', 'starts_at', 'ends_at',
        'timezone', 'location_name', 'location_snapshot', 'status', 'revision', 'published_revision',
    ];

    /** @return array{User, User} */
    public function authorizeProfileAccess(int $userId, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        // CustomerPortalIdentity can carry the same numeric ID as an internal
        // account. Check the actual principal before looking up that ID.
        abort_unless($actor instanceof User, 403);
        $actor = User::with('currentTeam')->find($actor->id);
        abort_unless($actor?->isActive() && $actor->canViewManagementDashboard(), 403);
        Gate::forUser($actor)->authorize('employees.view');
        Gate::forUser($actor)->authorize('operations.manage');
        $employee = User::query()->select(['id', 'role'])->findOrFail($userId);
        abort_unless($employee->role === 'staff', 403);

        return [$actor, $employee];
    }

    /** Only this read's schema matters; optional payroll/GPS modules do not. */
    public function ready(): bool
    {
        return Schema::hasColumns('shifts', [...self::SHIFT_FIELDS, 'deleted_at'])
            && Schema::hasColumns('shift_assignments', ['id', 'shift_id', 'user_id', 'status', 'plan_revision'])
            && Schema::hasColumns('orders', ['id', 'customer_id', 'deleted_at'])
            && Schema::hasColumns('customers', ['id', 'company_name', 'deleted_at']);
    }

    public function validateFilters(string $period, string $search): void
    {
        if (! in_array($period, ['upcoming', 'past', 'all'], true)) {
            throw ValidationException::withMessages(['period' => 'Bitte einen gültigen Schichtzeitraum auswählen.']);
        }
        if (mb_strlen($search) > 120 || str_contains($search, "\0")) {
            throw ValidationException::withMessages(['search' => 'Die Schichtsuche darf höchstens 120 Zeichen enthalten.']);
        }
    }

    public function shiftUrl(int $userId, int $shiftId): string
    {
        [, $employee] = $this->authorizeProfileAccess($userId);
        abort_unless($shiftId > 0, 422);
        abort_unless($this->ready(), 503, 'Die Schichtdaten sind noch nicht eingerichtet.');
        $rows = $this->rows(Shift::query()->whereKey($shiftId)
            ->whereHas('assignments', fn (Builder $assignments) => $assignments->where('user_id', $employee->id))->limit(1), (int) $employee->id, CarbonImmutable::now('UTC'));
        abort_unless(isset($rows[0]), 404);

        return $rows[0]['url'];
    }

    /**
     * The returned models are projected before rendering and never belong in
     * public Livewire properties, a session or a persistent/browser cache.
     */
    public function read(int $userId, string $viewMode = 'overview', string $period = 'upcoming', string $search = '', int $page = 1, ?User $actor = null): array
    {
        [, $employee] = $this->authorizeProfileAccess($userId, $actor);
        abort_unless(in_array($viewMode, ['overview', 'shifts'], true), 422);
        $this->validateFilters($period, $search);
        $now = CarbonImmutable::now('UTC');
        $view = [
            'state' => 'unconfigured', 'summary' => array_fill_keys(['total', 'ongoing', 'upcoming', 'past', 'confirmed', 'requested'], 0),
            'current' => [], 'next' => [], 'previous' => [], 'records' => null,
            'updated_at' => $now->setTimezone(self::ZONE)->format('d.m.Y H:i'),
            'now_iso' => $now->toIso8601String(), 'period' => $period, 'search' => trim($search),
        ];
        if (! $this->ready()) {
            return $view;
        }

        // Database/query failures intentionally propagate. A broken configured
        // backend must not masquerade as an employee with no assignments.
        $base = Shift::query()->whereHas('assignments', fn (Builder $assignments) => $assignments->where('user_id', $employee->id));
        if ($viewMode === 'overview') {
            $timestamp = $now->format('Y-m-d H:i:s');
            $activeSql = 'status not in (?, ?) and starts_at is not null and ends_at > starts_at and ends_at > ?';
            $activeAssignmentsSql = 'exists (select 1 from shift_assignments as employee_operation_assignment where employee_operation_assignment.shift_id = shifts.id and employee_operation_assignment.user_id = ? and employee_operation_assignment.status in (?, ?))';
            $activeBindings = [ShiftStatus::Cancelled->value, ShiftStatus::Completed->value, $timestamp];
            $assignmentBindings = [$employee->id, ...ShiftAssignmentStatus::blockingValues()];
            $summary = (clone $base)->selectRaw('count(*) as total')
                ->selectRaw('sum(case when ends_at <= ? then 1 else 0 end) as past', [$timestamp])
                ->selectRaw('sum(case when '.$activeSql.' and starts_at <= ? and '.$activeAssignmentsSql.' then 1 else 0 end) as ongoing', [...$activeBindings, $timestamp, ...$assignmentBindings])
                ->selectRaw('sum(case when '.$activeSql.' and starts_at > ? and '.$activeAssignmentsSql.' then 1 else 0 end) as upcoming', [...$activeBindings, $timestamp, ...$assignmentBindings]);
            foreach (['confirmed', 'requested'] as $status) {
                $summary->selectRaw('sum(case when '.$activeSql.' and exists (select 1 from shift_assignments as employee_operation_response where employee_operation_response.shift_id = shifts.id and employee_operation_response.user_id = ? and employee_operation_response.status = ?) then 1 else 0 end) as '.$status, [...$activeBindings, $employee->id, $status]);
            }
            $counts = $summary->first();
            $view['summary'] = collect($view['summary'])->map(fn ($value, $key) => (int) $counts?->getAttribute($key))->all();
            $active = $this->activeAssignments(clone $base, (int) $employee->id, $now);
            $view['current'] = $this->rows((clone $active)->where('starts_at', '<=', $timestamp)->orderBy('starts_at')->orderBy('id')->limit(3), (int) $employee->id, $now);
            $view['next'] = $this->rows((clone $active)->where('starts_at', '>', $timestamp)->orderBy('starts_at')->orderBy('id')->limit(3), (int) $employee->id, $now);
            $view['previous'] = $this->rows((clone $base)->where('ends_at', '<=', $timestamp)->orderByDesc('ends_at')->orderByDesc('starts_at')->orderByDesc('id')->limit(1), (int) $employee->id, $now);
            $view['state'] = $view['summary']['total'] === 0 ? 'empty' : 'available';

            return $view;
        }

        $query = match ($period) {
            'upcoming' => $this->activeAssignments(clone $base, (int) $employee->id, $now)->orderBy('starts_at')->orderBy('id'),
            'past' => (clone $base)->where('ends_at', '<=', $now->format('Y-m-d H:i:s'))->orderByDesc('starts_at')->orderByDesc('id'),
            default => (clone $base)->orderByDesc('starts_at')->orderByDesc('id'),
        };
        if ($view['search'] !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($view['search'])).'%';
            $query->where(function (Builder $searchQuery) use ($needle): void {
                foreach (['title', 'role_name', 'location_name', 'public_id'] as $index => $field) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $searchQuery->{$method}("LOWER(shifts.$field) LIKE ? ESCAPE '!'", [$needle]);
                }
                $searchQuery->orWhereHas('order.customer', fn (Builder $customer) => $customer->whereRaw("LOWER(company_name) LIKE ? ESCAPE '!'", [$needle]));
            });
        }
        $total = (clone $query)->count();
        $page = max(1, min($page, self::MAX_PAGE, max(1, (int) ceil($total / self::PER_PAGE))));
        $items = $this->rows((clone $query)->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE), (int) $employee->id, $now);
        $view['records'] = new LengthAwarePaginator($items, $total, self::PER_PAGE, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => self::PAGE_NAME,
        ]);
        $view['summary']['total'] = $total;
        $view['state'] = $total === 0 ? 'empty' : 'available';

        return $view;
    }

    private function activeAssignments(Builder $query, int $userId, CarbonImmutable $now): Builder
    {
        return $query->whereNotIn('status', [ShiftStatus::Cancelled->value, ShiftStatus::Completed->value])
            ->whereNotNull('starts_at')->whereColumn('ends_at', '>', 'starts_at')
            ->where('ends_at', '>', $now->format('Y-m-d H:i:s'))
            ->whereHas('assignments', fn (Builder $assignments) => $assignments->where('user_id', $userId)->whereIn('status', ShiftAssignmentStatus::blockingValues()));
    }

    private function rows(Builder $query, int $userId, CarbonImmutable $now): array
    {
        return $query->select(self::SHIFT_FIELDS)->with([
            'assignments' => fn (HasMany $assignments) => $assignments->select(['id', 'shift_id', 'user_id', 'status', 'plan_revision'])->where('user_id', $userId),
            'order:id,customer_id', 'order.customer:id,company_name',
        ])->get()->map(fn (Shift $shift) => $this->project($shift, $now))->all();
    }

    private function project(Shift $shift, CarbonImmutable $now): array
    {
        $assignment = $shift->getRelation('assignments')->first();
        $start = $this->scheduleTime($shift, 'starts_at');
        $end = $this->scheduleTime($shift, 'ends_at');
        $validPeriod = $start && $end && $end->gt($start);
        $shiftStatus = $shift->status;
        $assignmentStatus = $assignment->status;
        $closed = in_array($shiftStatus, [ShiftStatus::Cancelled, ShiftStatus::Completed], true)
            || in_array($assignmentStatus, [ShiftAssignmentStatus::Declined, ShiftAssignmentStatus::Cancelled], true);
        $published = $shift->published_revision > 0 && $shift->published_revision === $shift->revision;
        $assignmentPublishedBasis = $assignment->plan_revision > 0 && $assignment->plan_revision === $shift->published_revision;
        $assignmentRevisionNote = match (true) {
            $assignment->plan_revision <= 0 || $shift->published_revision <= 0 => 'Keine veröffentlichte Planbasis für diese Zuordnung.',
            ! $assignmentPublishedBasis => 'Zuordnung zu Planrevision '.$assignment->plan_revision.'; veröffentlicht ist Revision '.$shift->published_revision.'.',
            ! $published => 'Zuordnung zur veröffentlichten Revision '.$shift->published_revision.'; aktueller Entwurf ist Revision '.$shift->revision.'.',
            default => null,
        };
        $parameters = ['shift' => (int) $shift->id];
        if ($validPeriod) {
            $displayZone = (string) config('operations.display_timezone', self::ZONE);
            try {
                new \DateTimeZone($displayZone);
            } catch (\Exception) {
                $displayZone = self::ZONE;
            }
            $linkStart = $start->setTimezone($displayZone);
            $linkEnd = $end->setTimezone($displayZone);
            if ($linkStart->year >= 2000 && $linkEnd->year <= 2100 && $linkStart->startOfDay()->diffInDays($linkEnd->startOfDay()) <= 93) {
                $parameters += ['from' => $linkStart->toDateString(), 'until' => $linkEnd->toDateString()];
            }
        }
        // Keep only the saved display label. Coordinates, provider metadata,
        // customer addresses and employee location points are never projected.
        $snapshot = $shift->location_snapshot;
        $location = is_array($snapshot) ? ($snapshot['label'] ?? $snapshot['name'] ?? null) : null;
        $location = is_string($location) && trim($location) !== '' ? trim($location) : trim((string) $shift->location_name);

        return [
            'id' => (int) $shift->id, 'assignment_id' => (int) $assignment->id, 'public_id' => (string) $shift->public_id,
            'title' => (string) $shift->title, 'role' => (string) $shift->role_name,
            'location' => $location !== '' ? $location : 'Einsatzort nicht hinterlegt',
            'customer' => $shift->getRelation('order')?->getRelation('customer')?->company_name ?: 'Kunde nicht hinterlegt',
            'date_label' => $start?->locale('de')->isoFormat('dd, D. MMM YYYY') ?? 'Zeitraum nicht hinterlegt',
            'start_date' => $start?->format('d.m.Y') ?? '—', 'end_date' => $end?->format('d.m.Y') ?? '—',
            'start_time' => $start?->format('H:i') ?? '—', 'end_time' => $end?->format('H:i') ?? '—',
            'starts_at' => $start?->utc()->toIso8601String(), 'ends_at' => $end?->utc()->toIso8601String(),
            'timezone' => $start?->timezoneName ?? $end?->timezoneName ?? self::ZONE,
            'duration_hours' => $validPeriod ? max(0, $start->diffInSeconds($end) / 3600) : null,
            'dates_available' => (bool) $validPeriod,
            'shift_status' => $shiftStatus->value, 'shift_status_label' => $shiftStatus->label(),
            'shift_status_tone' => match ($shiftStatus) {
                ShiftStatus::Confirmed, ShiftStatus::Staffed => 'green',
                ShiftStatus::Requested => 'amber', ShiftStatus::Cancelled => 'red', default => 'slate',
            },
            'assignment_status' => $assignmentStatus->value, 'assignment_status_label' => $assignmentStatus->label(),
            'assignment_status_tone' => match ($assignmentStatus) {
                ShiftAssignmentStatus::Confirmed => 'green', ShiftAssignmentStatus::Requested => 'amber', default => 'slate',
            },
            'ongoing' => $validPeriod && ! $closed && $start->lte($now) && $end->gt($now),
            'upcoming' => $validPeriod && ! $closed && $start->gt($now), 'past' => $validPeriod && $end->lte($now), 'closed' => $closed,
            'published' => $published, 'publication_label' => $published ? 'Veröffentlicht' : ($shift->published_revision > 0 ? 'Unveröffentlichte Änderung' : 'Entwurf'),
            'revision' => (int) $shift->revision, 'published_revision' => (int) $shift->published_revision,
            'plan_revision' => (int) $assignment->plan_revision,
            'assignment_published_basis' => $assignmentPublishedBasis, 'assignment_current_plan' => $assignmentPublishedBasis && $published,
            'assignment_revision_note' => $assignmentRevisionNote, 'url' => OperationsPages::url('shifts', $parameters),
        ];
    }

    private function scheduleTime(Shift $shift, string $attribute): ?CarbonImmutable
    {
        $raw = $shift->getRawOriginal($attribute);
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $raw)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw, 'UTC');
            if (! $date || $date->format('Y-m-d H:i:s') !== $raw) {
                return null;
            }
            $zone = (string) $shift->getRawOriginal('timezone');
            try {
                new \DateTimeZone($zone);
            } catch (\Exception) {
                $zone = self::ZONE;
            }

            return $date->setTimezone($zone);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
