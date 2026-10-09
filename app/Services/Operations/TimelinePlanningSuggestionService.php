<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Read-only, bounded suggestions. Nothing here reserves a place or changes a plan. */
class TimelinePlanningSuggestionService
{
    private const MAX_SHIFTS = 12;

    private const MAX_SLOTS = 5;

    public function openShifts(string $from, string $until, User $actor): Builder
    {
        $this->access($actor);
        [$start, $end] = $this->range($from, $until);
        $reserved = ShiftAssignment::query()->selectRaw('count(*)')
            ->whereColumn('shift_assignments.shift_id', 'shifts.id')
            ->whereIn('status', ShiftAssignmentStatus::blockingValues());

        return Shift::query()->with(['order.customer', 'qualifications'])
            ->withCount(['assignments as reserved_count' => fn (Builder $query) => $query->blocking()])
            ->whereNotIn('status', [ShiftStatus::InProgress->value, ShiftStatus::Completed->value, ShiftStatus::Cancelled->value])
            ->whereHas('order', fn (Builder $query) => $query->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Invoiced->value, OrderStatus::Cancelled->value]))
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->during($start, $end)
            ->where('required_staff', '>', $reserved)
            ->orderBy('starts_at')->orderBy('id');
    }

    /** @return Collection<int, array{shift: Shift, revision: int, eligible: bool, issues: array}> */
    public function cellChoices(int $userId, string $date, string $from, string $until, User $actor): Collection
    {
        $query = $this->openShifts($from, $until, $actor);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        abort_unless($date >= $from && $date <= $until, 422);
        $user = User::where('role', 'staff')->where('status', true)->findOrFail($userId);
        $day = CarbonImmutable::parse($date, config('operations.display_timezone', 'Europe/Berlin'));

        return $query->during($day, $day->addDay())->limit(30)->get()->map(function (Shift $shift) use ($user): array {
            $issues = $this->issues($shift, $user);

            return ['shift' => $shift, 'revision' => (int) $shift->revision, 'eligible' => $issues === [], 'issues' => $issues];
        });
    }

    /**
     * Rank all active staff, never just the currently visible/search-filtered rows.
     * Wishes are soft preferences; eligibility always comes from the central hard guard.
     *
     * @return Collection<int, array{user: User, reasons: array, wish: string, planned_minutes: float, target_minutes: ?int}>
     */
    public function rankedCandidates(Shift $shift, User $actor, array $additionalByUser = []): Collection
    {
        return $this->rank($shift, $actor, $additionalByUser);
    }

    private function rank(Shift $shift, User $actor, array $additionalByUser, ?Collection $staff = null): Collection
    {
        $this->access($actor);
        $shift = Shift::with(['qualifications', 'order.customer'])->findOrFail($shift->id);
        if (! $this->isOpen($shift)) {
            return collect();
        }
        $users = $staff ?? User::where('role', 'staff')->where('status', true)->orderBy('name')->orderBy('id')->get();
        $existing = $shift->assignments()->blocking()->pluck('user_id');
        $shift->setAttribute('reserved_count', $existing->count());
        $users = $users->reject(fn (User $user) => $existing->contains($user->id))->values();
        $eligibility = app(StaffEligibilityService::class)->assessMany($shift, $users);
        $ranked = collect();
        $wishes = app(WorkforcePlanningService::class)->wishSummaries($shift, $users);
        $loads = $this->batchLoads($shift, $users);
        foreach ($users as $user) {
            $additional = $additionalByUser[$user->id] ?? [];
            $issues = $additional === [] ? ($eligibility[$user->id] ?? [])
                : app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), false, ['additional_shifts' => $additional])[$user->id];
            if ($issues !== [] || $this->capacityIssues($shift, $user, $additionalByUser) !== []) {
                continue;
            }
            $wish = $wishes->get($user->id)['state'];
            $load = $this->projectLoad($loads[$user->id], $additional);
            $wishPriority = ['preferred' => 0, 'available' => 1, 'unknown' => 2, 'free_requested' => 3][$wish] ?? 2;
            $reasons = ['Keine Planungskonflikte'];
            $wishLabel = ['preferred' => 'Wunschdienst', 'available' => 'Verfügbarkeit gemeldet', 'free_requested' => 'Freiwunsch vorhanden'][$wish] ?? null;
            if ($wishLabel) {
                $reasons[] = $wishLabel;
            }
            $reasons[] = 'Wochenplanung: '.number_format($load['planned_minutes'] / 60, 1, ',', '.').' h';
            if ($load['target_minutes'] !== null) {
                $reasons[] = 'Gepflegtes Wochensoll: '.number_format($load['target_minutes'] / 60, 1, ',', '.').' h';
            }
            $ranked->push(['user' => $user, 'reasons' => $reasons, 'wish' => $wish,
                'planned_minutes' => $load['planned_minutes'], 'target_minutes' => $load['target_minutes'],
                '_wish_priority' => $wishPriority,
                '_load_ratio' => $load['target_minutes'] > 0 ? $load['planned_minutes'] / $load['target_minutes'] : null]);
        }

        // A single comparable basis avoids fabricated targets and a non-transitive mixed ratio/minutes sort.
        $useRatios = $ranked->isNotEmpty() && $ranked->every(fn (array $candidate) => $candidate['_load_ratio'] !== null);

        return $ranked->sort(function (array $left, array $right) use ($useRatios): int {
            $wish = $left['_wish_priority'] <=> $right['_wish_priority'];
            if ($wish !== 0) {
                return $wish;
            }
            if ($useRatios) {
                $ratio = $left['_load_ratio'] <=> $right['_load_ratio'];
                if ($ratio !== 0) {
                    return $ratio;
                }
            }

            return ($left['planned_minutes'] <=> $right['planned_minutes'])
                ?: strnatcasecmp($left['user']->name, $right['user']->name)
                ?: ($left['user']->id <=> $right['user']->id);
        })->values()->map(fn (array $candidate) => collect($candidate)->except(['_wish_priority', '_load_ratio'])->all());
    }

    /** @return array{proposals: Collection, open_total: int, limited: bool} */
    public function preview(string $from, string $until, User $actor): array
    {
        $query = $this->openShifts($from, $until, $actor);
        $total = (clone $query)->count();
        $shifts = $query->limit(self::MAX_SHIFTS)->get();
        $proposals = collect();
        $additionalByUser = [];
        $limited = $total > self::MAX_SHIFTS;
        $staff = $shifts->isEmpty() ? collect() : User::where('role', 'staff')->where('status', true)->orderBy('name')->orderBy('id')->get();
        foreach ($shifts as $shift) {
            $open = max(0, $shift->required_staff - (int) $shift->reserved_count);
            $limited = $limited || $open > self::MAX_SLOTS;
            $candidates = $this->rank($shift, $actor, $additionalByUser, $staff)->take(min($open, self::MAX_SLOTS));
            foreach ($candidates as $candidate) {
                if ($this->capacityIssues($shift, $candidate['user'], $additionalByUser) !== []) {
                    continue;
                }
                $fit = $candidate['wish'] === 'free_requested' ? 'review'
                    : (in_array($candidate['wish'], ['preferred', 'available'], true) ? 'preferred' : 'suitable');
                $hoursUntilStart = CarbonImmutable::now('UTC')->diffInHours($shift->starts_at, false);
                $urgency = $hoursUntilStart <= 24 ? 'urgent' : ($hoursUntilStart <= 72 ? 'soon' : 'normal');
                $proposals->push(['shift' => $shift, 'user' => $candidate['user'], 'revision' => (int) $shift->revision, 'reasons' => $candidate['reasons'],
                    'fit' => $fit, 'fit_label' => ['preferred' => 'Passend mit Dienstwunsch / Verfügbarkeit', 'suitable' => 'Konfliktfreie Alternative', 'review' => 'Freiwunsch prüfen'][$fit],
                    'urgency' => $urgency, 'urgency_label' => ['urgent' => 'Beginn innerhalb von 24 Stunden', 'soon' => 'Beginn innerhalb von 72 Stunden', 'normal' => 'Beginn in mehr als 72 Stunden'][$urgency]]);
                $additionalByUser[$candidate['user']->id][] = $shift;
            }
        }

        return ['proposals' => $proposals, 'open_total' => $total, 'limited' => $limited];
    }

    private function access(User $actor): void
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
        OperationsAccess::requireReady();
    }

    /** Inclusive local date range, half-open instant interval. */
    private function range(string $from, string $until): array
    {
        Validator::make(compact('from', 'until'), ['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $start = CarbonImmutable::parse($from, $zone);
        $end = CarbonImmutable::parse($until, $zone)->addDay();
        abort_if($start->diffInDays($end) > 94, 422);

        return [$start, $end];
    }

    private function isOpen(Shift $shift): bool
    {
        return ! in_array($shift->status, [ShiftStatus::InProgress, ShiftStatus::Completed, ShiftStatus::Cancelled], true)
            && $shift->starts_at->gte(CarbonImmutable::now('UTC'))
            && $shift->order && ! in_array($shift->order->status, [OrderStatus::Completed, OrderStatus::Invoiced, OrderStatus::Cancelled], true)
            && $shift->assignments()->blocking()->count() < $shift->required_staff;
    }

    private function issues(Shift $shift, User $user): array
    {
        $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]))[$user->id];
        if ($shift->assignments()->blocking()->where('user_id', $user->id)->exists()) {
            $issues[] = ['code' => 'already_assigned', 'message' => 'Mitarbeiter ist dieser Schicht bereits zugewiesen.'];
        }

        return array_merge($issues, $this->capacityIssues($shift, $user));
    }

    private function capacityIssues(Shift $shift, User $user, array $additionalByUser = []): array
    {
        if (($shift->getAttribute('reserved_count') ?? $shift->assignments()->blocking()->count()) >= $shift->required_staff) {
            return [['code' => 'capacity', 'message' => 'Alle Einsatzplätze sind bereits reserviert.']];
        }
        try {
            $virtual = collect($additionalByUser)->flatMap(fn (array $duties, int $userId) => collect($duties)->map(fn (Shift $duty) => ['shift' => $duty, 'user_id' => $userId]))->all();
            app(OrderDemandService::class)->assertCapacity($shift, $user->id, false, $virtual);
        } catch (ValidationException $exception) {
            return [['code' => 'demand_capacity', 'message' => collect($exception->errors())->flatten()->first()]];
        }

        return [];
    }

    /** Call-local read projection: model timezones, weekly duties and training are loaded in batches. */
    private function batchLoads(Shift $shift, Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }
        $models = app(WorkforceAccountService::class)->effectiveModels($users, $shift->starts_at);
        $windows = $users->mapWithKeys(function (User $user) use ($models, $shift) {
            $model = $models->get($user->id);
            $from = $shift->starts_at->setTimezone($model?->timezone ?? config('operations.display_timezone', 'Europe/Berlin'))->startOfWeek(CarbonImmutable::MONDAY);

            return [$user->id => ['from' => $from, 'until' => $from->addWeek(), 'target_minutes' => $model?->weekly_target_minutes]];
        });
        $from = $windows->pluck('from')->min();
        $until = $windows->pluck('until')->max();
        $duties = ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))
            ->whereHas('shift', fn (Builder $q) => $q->notCancelled()->during($from, $until))->with('shift')->get()->groupBy('user_id');
        $trainings = Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants')
            ? PersonnelTrainingParticipant::whereIn('user_id', $users->pluck('id'))->whereIn('status', ['confirmed', 'attended'])
                ->whereHas('training', fn (Builder $q) => $q->where('status', 'scheduled')->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc()))
                ->with('training')->get()->groupBy('user_id') : collect();

        return $windows->map(function (array $window, int $id) use ($duties, $trainings) {
            return $window + [
                'duties' => $duties->get($id, collect())->pluck('shift')->filter(fn (Shift $duty) => $duty->starts_at->lt($window['until']) && $duty->ends_at->gt($window['from'])),
                'training_minutes' => $trainings->get($id, collect())->sum(fn ($row) => $this->overlapSeconds($row->training->starts_at, $row->training->ends_at, $window['from'], $window['until']) / 60),
            ];
        })->all();
    }

    /** Actual planned elapsed minutes. Crossing-week pause location stays unknown, not invented. */
    private function projectLoad(array $window, array $additional): array
    {
        $minutes = $window['duties']->concat($additional)->unique('id')->sum(function (Shift $duty) use ($window): float {
            $seconds = $this->overlapSeconds($duty->starts_at, $duty->ends_at, $window['from'], $window['until']);
            $whole = $seconds === (int) $duty->starts_at->diffInSeconds($duty->ends_at);

            return max(0, $seconds - ($whole ? (int) $duty->planned_break_minutes * 60 : 0)) / 60;
        });

        return ['planned_minutes' => $minutes + $window['training_minutes'], 'target_minutes' => $window['target_minutes']];
    }

    private function overlapSeconds(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $from, CarbonImmutable $until): int
    {
        $start = $start->max($from);
        $end = $end->min($until);

        return $start->lt($end) ? (int) $start->diffInSeconds($end) : 0;
    }
}
