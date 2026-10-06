<?php

namespace App\Services\Operations;

use App\Models\EmployeeWorkModel;
use App\Models\PersonnelTrainingParticipant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Read-only display projection. Workload is not an eligibility or payroll decision. */
class TimelineWorkloadService
{
    public function forRows(Collection $users, Collection $assignments, Collection $absences, Collection $days): Collection
    {
        $from = $days->first();
        $until = $days->last()->addDay();
        $models = Schema::hasTable('employee_work_models')
            ? EmployeeWorkModel::whereIn('user_id', $users->pluck('id'))->where('status', 'active')
                ->where('starts_on', '<=', $days->last()->toDateString())
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from->toDateString()))
                ->orderByDesc('starts_on')->get()->groupBy('user_id') : collect();
        $trainings = Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants')
            ? PersonnelTrainingParticipant::whereIn('user_id', $users->pluck('id'))->whereIn('status', ['confirmed', 'attended'])
                ->whereHas('training', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc()))
                ->with('training')->get()->groupBy('user_id') : collect();

        return $users->mapWithKeys(function ($user) use ($assignments, $absences, $days, $from, $until, $models, $trainings) {
            $target = 0;
            $missing = 0;
            foreach ($days as $day) {
                $model = $models->get($user->id, collect())->first(fn ($model) => $model->starts_on <= $day->toDateString() && ($model->ends_on === null || $model->ends_on >= $day->toDateString()));
                $daily = $model?->daily_minutes[$day->isoWeekday()] ?? null;
                if ($daily === null) {
                    $missing++;
                } else {
                    $target += max(0, (int) $daily);
                }
            }
            $totals = ['confirmed' => 0.0, 'requested' => 0.0, 'training' => 0.0];
            $count = 0;
            $clipped = false;
            foreach ($assignments->get($user->id, collect()) as $assignment) {
                $shift = $assignment->shift;
                $minutes = $this->minutes($shift->starts_at, $shift->ends_at, $from, $until);
                if ($minutes <= 0) {
                    continue;
                }
                $whole = $shift->starts_at->gte($from) && $shift->ends_at->lte($until);
                // The position of a break outside the range is unknown. Never invent it.
                $clipped = $clipped || (! $whole && $shift->planned_break_minutes > 0);
                $totals[$assignment->status->value] += max(0, $minutes - ($whole ? $shift->planned_break_minutes : 0));
                $count++;
            }
            foreach ($trainings->get($user->id, collect()) as $participant) {
                $totals['training'] += $this->minutes($participant->training->starts_at, $participant->training->ends_at, $from, $until);
            }
            $planned = array_sum($totals);
            $knownTarget = $missing === 0 ? $target : null;
            $ratio = $knownTarget > 0 ? $planned / $knownTarget : null;

            return [$user->id => $totals + [
                'planned' => $planned, 'target' => $knownTarget, 'ratio' => $ratio,
                'percent' => $ratio !== null ? (int) round($ratio * 100) : null,
                'state' => $knownTarget !== null && $planned > $knownTarget ? 'over' : ($ratio === null ? 'unknown' : ($ratio >= .85 ? 'full' : 'available')),
                'shift_count' => $count, 'absence_count' => $absences->get($user->id, collect())->count(),
                'missing_days' => $missing, 'clipped_breaks' => $clipped,
            ]];
        });
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $from, CarbonImmutable $until): float
    {
        $start = $start->max($from);
        $end = $end->min($until);

        return $start->lt($end) ? $start->diffInSeconds($end) / 60 : 0.0;
    }
}
