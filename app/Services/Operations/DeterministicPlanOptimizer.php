<?php

namespace App\Services\Operations;

use App\Models\PlanVariant;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A deterministic, explained multi-duty heuristic, never an optimality or AI claim. */
class DeterministicPlanOptimizer
{
    public function preview(string $from, string $until, array $goals, User $actor): array
    {
        app(PlanningEnhancementService::class)->access($actor);
        [$start,$end] = app(PlanningCapacityService::class)->range($from, $until);
        $goals = Validator::make($goals, ['wish_weight' => 'required|integer|min:0|max:100', 'load_weight' => 'required|integer|min:0|max:100', 'night_weight' => 'required|integer|min:0|max:100', 'weekend_weight' => 'required|integer|min:0|max:100', 'night_start' => 'required|date_format:H:i', 'night_end' => 'required|date_format:H:i'])->validate();
        $shifts = Shift::where('status', 'draft')->where('published_revision', 0)->where('starts_at', '>=', now()->utc())->during($start, $end)->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed', 'invoiced']))->with(['qualifications', 'assignments'])->orderBy('starts_at')->orderBy('id')->get();
        abort_if($shifts->count() > 100, 422);
        $users = User::where('role', 'staff')->where('status', true)->orderBy('name')->orderBy('id')->get();
        $history = app(PlanningCapacityService::class)->fairness($start->subDays(90)->toDateString(), $until, ['night_start' => $goals['night_start'], 'night_end' => $goals['night_end'], 'unfavourable_roles' => []], $actor);
        $history = collect($history)->keyBy('id');
        $normalize = $history->isNotEmpty() && $history->every(fn ($row) => $row['weekly_target'] !== null && $row['weekly_target'] > 0);
        $additional = [];
        $virtual = [];
        $rows = [];
        $entries = [];
        // Scarce duties first. Stable chronological/id tie break, no random or opaque score.
        $scarcity = $shifts->mapWithKeys(fn ($s) => [$s->id => collect(app(StaffEligibilityService::class)->assessMany($s, $users))->filter(fn ($issues) => $issues === [])->count()]);
        $ordered = $shifts->sort(fn ($a, $b) => ($scarcity[$a->id] <=> $scarcity[$b->id]) ?: ($a->starts_at->timestamp <=> $b->starts_at->timestamp) ?: ($a->id <=> $b->id));
        foreach ($ordered as $shift) {
            $pinned = $shift->assignments->filter(fn ($a) => $a->status->blocksAvailability())->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $selected = $pinned;
            $explanations = [];
            for ($slot = count($pinned); $slot < $shift->required_staff; $slot++) {
                $candidates = [];
                foreach ($users as $user) {
                    if (in_array($user->id, $selected, true)) {
                        continue;
                    }
                    $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), false, ['additional_shifts' => $additional[$user->id] ?? []])[$user->id];
                    if ($issues !== []) {
                        continue;
                    }
                    try {
                        app(OrderDemandService::class)->assertCapacity($shift, $user->id, false, $virtual);
                    } catch (ValidationException) {
                        continue;
                    }
                    $wish = app(WorkforcePlanningService::class)->wishSummary($shift, $user)['state'];
                    $wishPenalty = ['preferred' => 0, 'available' => 1, 'unknown' => 2, 'free_requested' => 3][$wish] ?? 2;
                    $h = $history[$user->id];
                    $minutes = collect($additional[$user->id] ?? [])->sum(fn ($s) => max(0, $s->starts_at->diffInMinutes($s->ends_at) - $s->planned_break_minutes));
                    $load = $h['planned_hours'] + $minutes / 60;
                    $night = $h['nights'] + collect($additional[$user->id] ?? [])->filter(fn ($s) => $this->night($s, $goals))->count();
                    $weekend = $h['weekends'] + collect($additional[$user->id] ?? [])->filter(fn ($s) => $this->weekend($s))->count();
                    $basis = $normalize ? $h['weekly_target'] : 1;
                    $score = $wishPenalty * $goals['wish_weight'] + ($load / $basis) * $goals['load_weight'] + ($this->night($shift, $goals) ? ($night / $basis) * $goals['night_weight'] : 0) + ($this->weekend($shift) ? ($weekend / $basis) * $goals['weekend_weight'] : 0);
                    $candidates[] = ['id' => $user->id, 'name' => $user->name, 'score' => round($score, 6), 'reasons' => ['Wunsch: '.$wish, 'Planstunden: '.round($load, 1), 'Nächte: '.$night, 'Wochenenden: '.$weekend, $normalize ? 'Verteilung relativ zum gepflegten Wochensoll' : 'Vergleich auf gemeinsamer Zählerbasis']];
                }
                $best = collect($candidates)->sort(fn ($a, $b) => ($a['score'] <=> $b['score']) ?: strnatcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']))->first();
                if (! $best) {
                    break;
                }
                $selected[] = $best['id'];
                $additional[$best['id']][] = $shift;
                $virtual[] = ['shift' => $shift, 'user_id' => $best['id']];
                $explanations[] = $best;
            }
            $entries[] = ['shift_id' => $shift->id, 'source_shift_id' => null, 'order_id' => $shift->order_id, 'title' => $shift->title, 'role_name' => $shift->role_name, 'starts_at' => $shift->starts_at->setTimezone($shift->timezone)->format('Y-m-d\TH:i'), 'ends_at' => $shift->ends_at->setTimezone($shift->timezone)->format('Y-m-d\TH:i'), 'timezone' => $shift->timezone, 'location_name' => $shift->location_name, 'required_staff' => $shift->required_staff, 'planned_break_minutes' => $shift->planned_break_minutes, 'qualification_ids' => $shift->qualifications->modelKeys(), 'user_ids' => $selected, 'transfer_buffer_minutes' => $shift->disposition_details['transfer_buffer_minutes'] ?? 0];
            $rows[] = ['id' => $shift->id, 'name' => $shift->title, 'period' => $shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i'), 'before' => count($pinned), 'after' => count($selected), 'open' => max(0, $shift->required_staff - count($selected)), 'proposals' => $explanations, 'revision' => $shift->revision];
        }

        return ['entries' => $entries, 'rows' => $rows, 'open' => collect($rows)->sum('open'), 'fingerprint' => hash('sha256', json_encode([$goals, $entries, $rows], JSON_THROW_ON_ERROR))];
    }

    private function night(Shift $shift, array $goals): bool
    {
        $s = $shift->starts_at->setTimezone($shift->timezone);
        $e = $shift->ends_at->setTimezone($shift->timezone);
        for ($day = $s->startOfDay()->subDay(); $day->lt($e); $day = $day->addDay()) {
            $a = $day->setTimeFromTimeString($goals['night_start']);
            $b = $day->setTimeFromTimeString($goals['night_end']);
            if ($b->lte($a)) {
                $b = $b->addDay();
            }
            if ($s->lt($b) && $e->gt($a)) {
                return true;
            }
        }

        return false;
    }

    private function weekend(Shift $shift): bool
    {
        $s = $shift->starts_at->setTimezone($shift->timezone);
        $e = $shift->ends_at->setTimezone($shift->timezone);
        for ($day = $s->startOfDay(); $day->lt($e); $day = $day->addDay()) {
            if ($day->isoWeekday() >= 6) {
                return true;
            }
        }

        return false;
    }

    public function createVariant(string $from, string $until, array $goals, string $fingerprint, User $actor): PlanVariant
    {
        app(PlanningEnhancementService::class)->access($actor);

        return OperationsTransaction::run(function () use ($from, $until, $goals, $fingerprint, $actor) {
            [$start,$end] = app(PlanningCapacityService::class)->range($from, $until);
            $ids = Shift::where('status', 'draft')->where('published_revision', 0)->during($start, $end)->pluck('id')->all();
            PlanningLocks::acquire($ids, User::where('role', 'staff')->where('status', true)->pluck('id')->all());
            $preview = $this->preview($from, $until, $goals, $actor);
            if ($preview['entries'] === [] || ! hash_equals($preview['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['workflow' => 'Planvorschau wurde geändert. Bitte neu berechnen.']);
            }
            $last = substr(collect($preview['entries'])->max('ends_at'), 0, 10);
            $variant = app(PlanVariantService::class)->save(null, null, ['name' => 'Planvorschlag '.$from, 'from' => $from, 'until' => max($until, $last), 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'entries' => $preview['entries']], $actor);
            $check = app(PlanVariantService::class)->preview($variant, $actor);
            if (! $check['valid']) {
                throw ValidationException::withMessages(['workflow' => 'Gesamtvorschlag enthält Konflikte.']);
            }

            return $variant->fresh();
        }, 3);
    }
}
