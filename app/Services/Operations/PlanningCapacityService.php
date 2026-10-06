<?php

namespace App\Services\Operations;

use App\Models\EmployeeQualification;
use App\Models\OrderDemand;
use App\Models\PersonnelTrainingParticipant;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Models\WorkforcePosition;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanningCapacityService
{
    public function range(string $from, string $until, int $maximum = 94): array
    {
        Validator::make(compact('from', 'until'), ['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $start = CarbonImmutable::parse($from, $zone);
        $end = CarbonImmutable::parse($until, $zone)->addDay();
        abort_if($start->diffInDays($end) > $maximum, 422);

        return [$start, $end];
    }

    /** Availability is per actual duty, never a sum of duplicated people across simultaneous rows. */
    public function matrix(string $from, string $until, array $filters, User $actor): array
    {
        app(PlanningEnhancementService::class)->access($actor);
        [$start,$end] = $this->range($from, $until);
        $filters = Validator::make($filters, ['location' => 'nullable|string|max:160', 'role' => 'nullable|string|max:160', 'pool_id' => 'nullable|integer|exists:workforce_pools,id'])->validate();
        $users = User::where('role', 'staff')->where('status', true)->orderBy('name')->get();
        if (! empty($filters['pool_id'])) {
            $pool = WorkforcePool::findOrFail($filters['pool_id']);
            $users = $users->whereIn('id', $pool->users()->pluck('users.id'))->values();
        }
        $query = Shift::notCancelled()->during($start, $end)->with(['qualifications', 'assignments', 'order.customer'])->orderBy('starts_at');
        if (! empty($filters['location'])) {
            $query->where('location_name', $filters['location']);
        }
        if (! empty($filters['role'])) {
            $query->where('role_name', $filters['role']);
        }
        $count = (clone $query)->count();
        $shifts = $query->limit(500)->get();
        $rows = [];
        foreach ($shifts as $shift) {
            if (! empty($filters['pool_id']) && $shift->order_demand_id && OrderDemand::find($shift->order_demand_id)?->workforce_pool_id !== (int) $filters['pool_id']) {
                continue;
            }
            $issues = app(StaffEligibilityService::class)->assessMany($shift, $users);
            $reserved = $shift->assignments->filter(fn ($a) => $a->status->blocksAvailability());
            $eligible = $users->filter(function ($user) use ($issues, $reserved, $shift) {
                if (($issues[$user->id] ?? []) !== [] || $reserved->contains('user_id', $user->id)) {
                    return false;
                }
                try {
                    app(OrderDemandService::class)->assertCapacity($shift, $user->id, false);
                } catch (ValidationException) {
                    return false;
                }

                return true;
            });
            $proofRisk = $users->filter(fn ($user) => collect($issues[$user->id] ?? [])->contains(fn ($issue) => str_starts_with($issue['code'], 'qualification_') || $issue['code'] === 'bundle_requirement_missing'))->count();
            $confirmed = $reserved->filter(fn ($a) => $a->status->value === 'confirmed' && $a->plan_revision === $shift->published_revision && $shift->published_revision === $shift->revision)->count();
            $open = max(0, $shift->required_staff - $reserved->count());
            $rows[] = ['id' => 'shift-'.$shift->id, 'name' => $shift->title, 'context' => $shift->role_name.' · '.($shift->location_name ?: '—'), 'period' => $shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i'), 'required' => $shift->required_staff, 'reserved' => $reserved->count(), 'confirmed' => $confirmed, 'eligible' => $eligible->count(), 'missing' => max(0, $open - $eligible->count()), 'proof_risks' => $proofRisk, 'open' => $open];
        }
        $demandRows = [];
        if (WorkforcePlanningSchema::demandsReady()) {
            $demands = OrderDemand::where('status', 'active')->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->with('order')->orderBy('starts_at');
            if (! empty($filters['role'])) {
                $demands->where('role_name', $filters['role']);
            }
            if (! empty($filters['pool_id'])) {
                $demands->where('workforce_pool_id', $filters['pool_id']);
            }
            foreach ($demands->limit(500)->get() as $demand) {
                if (! empty($filters['location']) && ! $demand->shifts()->where('location_name', $filters['location'])->exists()) {
                    continue;
                }
                $coverage = app(OrderDemandService::class)->coverage($demand);
                $demandRows[] = ['id' => 'demand-'.$demand->id, 'name' => $demand->order?->title.' · '.$demand->role_name, 'required' => $demand->required_staff, 'planned' => $coverage['planned'], 'reserved' => $coverage['reserved'] ?? 0, 'confirmed' => $coverage['confirmed'], 'missing' => $coverage['missing'] ?? $coverage['open']];
            }
        }
        $weekly = [];
        $week = $start->startOfWeek();
        while ($week->lt($end)) {
            $known = 0;
            $unknown = 0;
            $target = 0;
            foreach ($users as $user) {
                $sum = 0;
                $complete = true;
                for ($d = 0; $d < 7; $d++) {
                    $date = $week->addDays($d);
                    $model = app(WorkforceAccountService::class)->effectiveModel($user, $date->startOfDay());
                    if (! $model) {
                        $complete = false;
                        break;
                    }
                    $sum += (int) ($model->daily_minutes[$date->isoWeekday()] ?? 0);
                }
                if ($complete) {
                    $known++;
                    $target += $sum;
                } else {
                    $unknown++;
                }
            }
            $planned = ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))->whereHas('shift', fn ($q) => $q->notCancelled()->during($week, $week->addWeek()))->with('shift')->get()->sum(function ($assignment) use ($week) {
                $s = $assignment->shift;
                // A break without a located section cannot safely be deducted twice across week boundaries.
                $gross = max(0, $s->starts_at->max($week)->diffInMinutes($s->ends_at->min($week->addWeek()), false));

                return $s->starts_at->gte($week) && $s->ends_at->lte($week->addWeek()) ? max(0, $gross - $s->planned_break_minutes) : $gross;
            });
            $training = app(PersonnelProcessService::class)->ready() ? PersonnelTrainingParticipant::whereIn('user_id', $users->pluck('id'))->whereIn('status', ['enrolled', 'attended', 'completed'])->whereHas('training', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '<', $week->addWeek()->utc())->where('ends_at', '>', $week->utc()))->with('training')->get()->sum(fn ($p) => max(0, $p->training->starts_at->max($week)->diffInMinutes($p->training->ends_at->min($week->addWeek()), false))) : 0;
            $crossBoundary = ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))->whereHas('shift', fn ($q) => $q->notCancelled()->during($week, $week->addWeek())->where(fn ($r) => $r->where('starts_at', '<', $week->utc())->orWhere('ends_at', '>', $week->addWeek()->utc())))->exists();
            $weekly[] = ['id' => $week->toDateString(), 'name' => 'KW '.$week->isoWeek().' · '.$week->format('d.m.'), 'known' => $known, 'unknown' => $unknown, 'target_hours' => round($target / 60, 1), 'planned_hours' => round($planned / 60, 1), 'training_hours' => round($training / 60, 1), 'remaining_hours' => $unknown || $crossBoundary ? null : round(max(0, $target - $planned - $training) / 60, 1)];
            $week = $week->addWeek();
        }

        return ['duties' => $rows, 'demands' => $demandRows, 'weeks' => $weekly, 'limited' => $count > 500];
    }

    public function fairness(string $from, string $until, array $options, User $actor): array
    {
        app(PlanningEnhancementService::class)->access($actor);
        [$start,$end] = $this->range($from, $until, 366);
        $options = Validator::make($options, ['night_start' => 'required|date_format:H:i', 'night_end' => 'required|date_format:H:i', 'unfavourable_roles' => 'present|array|max:50', 'unfavourable_roles.*' => 'string|max:160'])->validate();
        $rows = [];
        $users = User::where('role', 'staff')->where('status', true)->orderBy('name')->get();
        $assignments = ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))->whereHas('shift', fn ($q) => $q->notCancelled()->during($start, $end))->with('shift')->get()->groupBy('user_id');
        foreach ($users as $user) {
            $nights = 0;
            $weekends = 0;
            $unfavourable = 0;
            $minutes = 0;
            foreach ($assignments->get($user->id, collect()) as $assignment) {
                $shift = $assignment->shift;
                $zone = $shift->timezone;
                $s = $shift->starts_at->setTimezone($zone);
                $e = $shift->ends_at->setTimezone($zone);
                $night = false;
                $weekend = false;
                for ($day = $s->startOfDay()->subDay(); $day->lt($e); $day = $day->addDay()) {
                    $n1 = $day->setTimeFromTimeString($options['night_start']);
                    $n2 = $day->setTimeFromTimeString($options['night_end']);
                    if ($n2->lte($n1)) {
                        $n2 = $n2->addDay();
                    }
                    if ($s->lt($n2) && $e->gt($n1)) {
                        $night = true;
                    }
                    if ($day->isoWeekday() >= 6 && $s->lt($day->addDay()) && $e->gt($day)) {
                        $weekend = true;
                    }
                }
                $nights += (int) $night;
                $weekends += (int) $weekend;
                $unfavourable += (int) in_array($shift->role_name, $options['unfavourable_roles'], true);
                $minutes += max(0, $s->diffInMinutes($e) - $shift->planned_break_minutes);
            }
            $model = app(WorkforceAccountService::class)->effectiveModel($user, $end->subDay());
            $rows[] = ['id' => $user->id, 'name' => $user->name, 'nights' => $nights, 'weekends' => $weekends, 'unfavourable' => $unfavourable, 'planned_hours' => round($minutes / 60, 1), 'weekly_target' => $model?->weekly_target_minutes ? round($model->weekly_target_minutes / 60, 1) : null];
        }

        return $rows;
    }

    public function positions(string $date, User $actor): array
    {
        app(PlanningEnhancementService::class)->access($actor);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        $rows = [];
        foreach (WorkforcePosition::where('from', '<=', $date)->where('until', '>=', $date)->orderBy('name')->get() as $position) {
            $requirementsKnown = $position->qualification_ids !== [] && QualificationType::whereKey($position->qualification_ids)->where('is_active', true)->count() === count($position->qualification_ids);
            $users = User::where('role', 'staff')->where('status', true)->get()->filter(function ($user) use ($position, $date) {
                foreach ($position->qualification_ids as $id) {
                    if (! EmployeeQualification::where('user_id', $user->id)->where('qualification_type_id', $id)->where('status', 'approved')->where('valid_from', '<=', $date)->where('valid_until', '>=', $date)->exists()) {
                        return false;
                    }
                }

                return true;
            });
            $known = 0;
            $unknown = 0;
            $fte = 0;
            foreach ($users as $user) {
                $model = app(WorkforceAccountService::class)->effectiveModel($user, $date);
                $locationKnown = ! $position->location_name || WorkforcePool::where('is_active', true)->where('location_name', $position->location_name)->whereHas('users', fn ($q) => $q->where('users.id', $user->id))->exists();
                if (! $model || ! $position->full_time_week_minutes || ! $requirementsKnown || ! $locationKnown) {
                    $unknown++;

                    continue;
                }
                $known++;
                $fte += $model->weekly_target_minutes / $position->full_time_week_minutes;
            }
            $rows[] = ['id' => $position->id, 'name' => $position->name, 'context' => $position->role_name.' · '.($position->location_name ?: '—'), 'period' => $position->from.' – '.$position->until, 'status' => $position->status, 'revision' => $position->revision, 'target_fte' => (float) $position->target_fte, 'known_fte' => round($fte, 3), 'unknown' => $unknown, 'gap_fte' => $position->status === 'approved' && ! $unknown ? round(max(0, (float) $position->target_fte - $fte), 3) : null];
        }

        return $rows;
    }
}
