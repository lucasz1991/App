<?php

namespace App\Services\Operations;

use App\Models\OperationsRateRule;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftSection;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class OperationsRuleEvaluationService
{
    public function ready(): bool
    {
        return Schema::hasColumns('operations_rate_rules', ['id', 'kind', 'user_id', 'starts_on', 'ends_on', 'configuration', 'approved_at', 'revision']);
    }

    public function configure(array $data, User $actor): OperationsRateRule
    {
        OperationsAccess::authorize($actor, 'operations.rules.manage');
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        OperationsEnhancementsSchema::requireReady();
        $data = Validator::make($data, ['name' => 'required|string|max:180', 'user_id' => 'nullable|integer|exists:users,id', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'kind' => 'required|in:planning,remuneration', 'configuration' => 'required|array', 'confirmed' => 'required|accepted'])->validate();
        $c = $data['configuration'];
        if ($data['kind'] === 'planning') {
            $c = Validator::make($c, ['type' => 'required|in:rolling_minutes,consecutive_days,night_count', 'days' => 'required|integer|min:1|max:90', 'limit' => 'required|integer|min:1|max:129600', 'timezone' => 'required|timezone', 'activity' => 'nullable|in:all,on_call,travel', 'window_start' => 'nullable|required_if:type,night_count|date_format:H:i', 'window_end' => 'nullable|required_if:type,night_count|date_format:H:i'])->validate();
            $c['days'] = (int) $c['days'];
            $c['limit'] = (int) $c['limit'];
            abort_if($c['type'] === 'consecutive_days' && $c['limit'] >= $c['days'], 422, 'Prüfzeitraum muss die Dienstfolgegrenze einschließlich Folgetag abdecken.');
        } else {
            $c = Validator::make($c, ['activity' => 'required|in:'.implode(',', array_keys(WorkTimeActivityService::KINDS)), 'wage_code' => 'required|string|max:40|regex:/^[A-Za-z0-9_.-]+$/', 'multiplier_bps' => 'required|integer|min:0|max:100000', 'rounding_minutes' => 'required|integer|min:0|max:60', 'rounding_mode' => 'required|in:none,nearest,up,down', 'window_start' => 'nullable|date_format:H:i', 'window_end' => 'nullable|date_format:H:i|required_with:window_start', 'weekdays' => 'nullable|array', 'weekdays.*' => 'integer|min:1|max:7', 'additive' => 'required|boolean'])->validate();
            $c['multiplier_bps'] = (int) $c['multiplier_bps'];
            $c['rounding_minutes'] = (int) $c['rounding_minutes'];
            $c['additive'] = (bool) $c['additive'];
            $c['weekdays'] = array_map('intval', $c['weekdays'] ?? []);
            abort_if(($c['rounding_minutes'] === 0) !== ($c['rounding_mode'] === 'none'), 422, 'Rundung vollständig konfigurieren.');
        }

        return OperationsTransaction::run(function () use ($data, $c, $actor) {
            User::lockForUpdate()->findOrFail($actor->id);
            $rule = OperationsRateRule::create(collect($data)->except(['confirmed', 'configuration'])->all() + ['configuration' => $c, 'created_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now()->utc()]);
            app(OperationsAuditService::class)->record($rule, $actor, 'rate_rule.approved', ['revision' => 1, 'kind' => $rule->kind]);

            return $rule;
        }, 3);
    }

    public function planningIssues(Shift $shift, User $user, array $context = []): array
    {
        if (! $this->ready()) {
            return Schema::hasTable('operations_rate_rules')
                ? [['code' => 'additional_rules_incomplete', 'message' => 'Fachregel-Grundlagen unvollständig. Vor der Einteilung prüfen.']]
                : [];
        }
        $issues = [];
        $rules = OperationsRateRule::where('kind', 'planning')->whereNotNull('approved_at')->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))->get();
        foreach ($rules as $rule) {
            $c = $rule->configuration;
            $day = $shift->starts_at->setTimezone($c['timezone'])->startOfDay();
            if ($day->lt($rule->starts_on) || ($rule->ends_on && $day->gt($rule->ends_on->endOfDay()))) {
                continue;
            }
            $from = $day->subDays($c['days'] - 1)->utc();
            $end = $shift->ends_at->setTimezone($c['timezone'])->startOfDay()->addDays($c['days'])->utc();
            $assigned = ShiftAssignment::where('user_id', $user->id)->whereIn('status', ['requested', 'confirmed'])->when($shift->id, fn ($q) => $q->where('shift_id', '!=', $shift->id))->when(! empty($context['exclude_shift_ids']), fn ($q) => $q->whereNotIn('shift_id', $context['exclude_shift_ids']))->whereHas('shift', fn ($q) => $q->where('starts_at', '<', $end)->where('ends_at', '>', $from)->where('status', '!=', 'cancelled'))->with('shift')->get()->pluck('shift');
            $prospective = collect($context['additional_shifts'] ?? [])->filter(fn ($s) => $s instanceof Shift && $s !== $shift && (! $s->id || ! $shift->id || $s->id !== $shift->id));
            $shifts = $assigned->concat($prospective)->push($shift)->unique(fn ($s) => $s->id ? 'persisted:'.$s->id : 'projected:'.spl_object_id($s));
            $value = 0;
            if ($c['type'] === 'rolling_minutes') {
                $intervals = [];
                $unknown = false;
                foreach ($shifts as $s) {
                    if (($c['activity'] ?? 'all') !== 'all') {
                        // A specific activity may only be checked from real configured plan sections.
                        $sections = ShiftSection::where('shift_id', $s->id)->orderBy('starts_at')->get();
                        $cursor = $s->starts_at;
                        foreach ($sections as $section) {
                            if (! $section->starts_at->equalTo($cursor)) {
                                $unknown = true;
                            }
                            $cursor = $section->ends_at;
                            if ($section->kind !== $c['activity']) {
                                continue;
                            }
                            $intervals[] = [$section->starts_at, $section->ends_at];
                        }
                        if (! $cursor->equalTo($s->ends_at)) {
                            $unknown = true;
                        }
                    } else {
                        $intervals[] = [$s->starts_at, $s->ends_at];
                    }
                }
                if ($unknown) {
                    $issues[] = ['code' => 'configured_basis_missing', 'message' => $rule->name.': vollständige Abschnittsgrundlage fehlt.'];

                    continue;
                }
                for ($windowDay = $day; $windowDay->lt($end); $windowDay = $windowDay->addDay()) {
                    $windowStart = $windowDay->subDays($c['days'] - 1)->utc();
                    $windowEnd = $windowDay->addDay()->utc();
                    if ($windowStart->gte($shift->ends_at) || $windowEnd->lte($shift->starts_at)) {
                        continue;
                    }
                    $minutes = 0;
                    foreach ($intervals as [$a,$b]) {
                        $a = $a->max($windowStart);
                        $b = $b->min($windowEnd);
                        if ($b->gt($a)) {
                            $minutes += (int) $a->diffInMinutes($b);
                        }
                    }
                    $value = max($value, $minutes);
                }
            } elseif ($c['type'] === 'night_count') {
                $nights = $shifts->filter(function ($s) use ($c) {
                    for ($date = $s->starts_at->setTimezone($c['timezone'])->startOfDay()->subDay(); $date->lt($s->ends_at->setTimezone($c['timezone'])); $date = $date->addDay()) {
                        $a = CarbonImmutable::parse($date->toDateString().' '.$c['window_start'], $c['timezone']);
                        $b = CarbonImmutable::parse($date->toDateString().' '.$c['window_end'], $c['timezone']);
                        if ($b->lte($a)) {
                            $b = $b->addDay();
                        }
                        if ($a->lt($s->ends_at) && $b->gt($s->starts_at)) {
                            return true;
                        }
                    }

                    return false;
                });
                for ($windowDay = $day; $windowDay->lt($end); $windowDay = $windowDay->addDay()) {
                    $windowStart = $windowDay->subDays($c['days'] - 1)->utc();
                    $windowEnd = $windowDay->addDay()->utc();
                    if ($windowStart->gte($shift->ends_at) || $windowEnd->lte($shift->starts_at)) {
                        continue;
                    }
                    $value = max($value, $nights->filter(fn ($s) => $s->starts_at->lt($windowEnd) && $s->ends_at->gt($windowStart))->count());
                }
            } else {
                $days = [];
                foreach ($shifts as $s) {
                    for ($date = $s->starts_at->setTimezone($c['timezone'])->startOfDay(); $date->lt($s->ends_at->setTimezone($c['timezone'])); $date = $date->addDay()) {
                        $days[$date->toDateString()] = true;
                    }
                }
                $first = $day;
                while (isset($days[$first->subDay()->toDateString()])) {
                    $first = $first->subDay();
                }
                for ($date = $first; isset($days[$date->toDateString()]); $date = $date->addDay()) {
                    $value++;
                }
            }
            if ($value > $c['limit']) {
                $issues[] = ['code' => 'configured_'.$c['type'], 'message' => $rule->name.': '.$value.' / '.$c['limit']];
            }
        }

        return $issues;
    }
}
