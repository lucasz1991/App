<?php

namespace App\Services\Operations;

use App\Models\PlanVariant;
use App\Models\Shift;
use App\Models\User;
use App\Services\Routing\TomTomRoutingService;
use App\Services\Routing\TravelAssessmentService;
use App\Services\Routing\TravelPreflight;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A deterministic, explained multi-duty heuristic, never an optimality or AI claim. */
class DeterministicPlanOptimizer
{
    public function preview(string $from, string $until, array $goals, User $actor): array
    {
        return TomTomRoutingService::withinOperation(function () use ($from, $until, $goals, $actor): array {
            $prepared = [];

            return $this->calculatePreview($from, $until, $goals, $actor, $prepared);
        });
    }

    /** Route facts remain in server memory; locked replay rechecks every planning input. */
    private function calculatePreview(string $from, string $until, array $goals, User $actor, array &$prepared, bool $lock = false): array
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
        $scarcity = [];
        foreach ($shifts as $s) {
            $details = $this->assessCandidates($s, $users, [], $prepared, $s->id.':scarcity', $lock);
            $scarcity[$s->id] = collect($details)->filter(fn ($detail) => $detail['issues'] === [])->count();
        }
        $ordered = $shifts->sort(fn ($a, $b) => ($scarcity[$a->id] <=> $scarcity[$b->id]) ?: ($a->starts_at->timestamp <=> $b->starts_at->timestamp) ?: ($a->id <=> $b->id));
        foreach ($ordered as $shift) {
            $regions = app(StaffRegionalPreferenceService::class)->assessMany($shift, $users, $lock);
            $wishes = app(WorkforcePlanningService::class)->wishSummaries($shift, $users);
            $pinned = $shift->assignments->filter(fn ($a) => $a->status->blocksAvailability())->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $selected = $pinned;
            $explanations = [];
            for ($slot = count($pinned); $slot < $shift->required_staff; $slot++) {
                $candidates = [];
                $batch = $users->reject(fn ($user) => in_array($user->id, $selected, true))->values();
                $details = $this->assessCandidates($shift, $batch, ['additional_shifts_by_user' => $additional], $prepared, $shift->id.':slot:'.$slot, $lock);
                foreach ($users as $user) {
                    if (in_array($user->id, $selected, true)) {
                        continue;
                    }
                    $detail = $details[$user->id];
                    $issues = $detail['issues'];
                    $region = $regions[$user->id];
                    if ($issues !== [] || $region['blocked']) {
                        continue;
                    }
                    try {
                        app(OrderDemandService::class)->assertCapacity($shift, $user->id, false, $virtual);
                    } catch (ValidationException) {
                        continue;
                    }
                    $wish = $wishes->get($user->id)['state'];
                    $wishPenalty = ['preferred' => 0, 'available' => 1, 'unknown' => 2, 'free_requested' => 3][$wish] ?? 2;
                    $h = $history[$user->id];
                    $minutes = collect($additional[$user->id] ?? [])->sum(fn ($s) => max(0, $s->starts_at->diffInMinutes($s->ends_at) - $s->planned_break_minutes));
                    $load = $h['planned_hours'] + $minutes / 60;
                    $night = $h['nights'] + collect($additional[$user->id] ?? [])->filter(fn ($s) => $this->night($s, $goals))->count();
                    $weekend = $h['weekends'] + collect($additional[$user->id] ?? [])->filter(fn ($s) => $this->weekend($s))->count();
                    $basis = $normalize ? $h['weekly_target'] : 1;
                    $score = $wishPenalty * $goals['wish_weight'] + ($load / $basis) * $goals['load_weight'] + ($this->night($shift, $goals) ? ($night / $basis) * $goals['night_weight'] : 0) + ($this->weekend($shift) ? ($weekend / $basis) * $goals['weekend_weight'] : 0);
                    $travel = $this->travelPreference($detail);
                    $candidates[] = ['id' => $user->id, 'name' => $user->name, 'score' => round($score, 6), 'regional_adjustment' => (int) $region['score_adjustment'], 'regional_state' => $region['state'], 'planned_travel_seconds' => $travel['seconds'], 'minimum_margin_minutes' => $travel['margin'], 'reasons' => [$region['label'].': '.$region['detail'], 'Einsatzgebiet zuerst; innerhalb derselben Gebietseignung gelten die gewichteten Planungsziele.', 'Wunsch: '.$wish, 'Planstunden: '.round($load, 1), 'Nächte: '.$night, 'Wochenenden: '.$weekend, $normalize ? 'Verteilung relativ zum gepflegten Wochensoll' : 'Vergleich auf gemeinsamer Zählerbasis', $travel['reason']]];
                }
                // Use a common basis for each equal-score cohort. Pairwise
                // "unknown is neutral" would make the comparator non-transitive.
                $cohort = fn ($candidate) => $candidate['regional_adjustment'].':'.sprintf('%.6F', $candidate['score']);
                $comparable = collect($candidates)->groupBy($cohort)->map(fn ($group) => $group->every(fn ($candidate) => $candidate['planned_travel_seconds'] !== null));
                $best = collect($candidates)->sort(fn ($a, $b) => ($b['regional_adjustment'] <=> $a['regional_adjustment'])
                    ?: ($a['score'] <=> $b['score'])
                    ?: (($comparable->get($cohort($a)) ?? false) ? ($a['planned_travel_seconds'] <=> $b['planned_travel_seconds']) : 0)
                    ?: strnatcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']))->first();
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

    private function assessCandidates(Shift $shift, Collection $users, array $context, array &$prepared, string $key, bool $lock): array
    {
        $eligibility = app(StaffEligibilityService::class);
        $core = $eligibility->assessPreviewCoreMany($shift, $users, $context);
        $eligible = $users->filter(fn ($user) => ($core[$user->id] ?? []) === [])->values();
        if (! $lock) {
            $prepared[$key] = app(TravelAssessmentService::class)->preflight($shift, $eligible, $context);
        }
        if (! ($prepared[$key] ?? null) instanceof TravelPreflight) {
            throw ValidationException::withMessages(['workflow' => 'Planvorschau wurde geändert. Bitte neu berechnen.']);
        }

        $details = collect($core)->map(fn ($issues) => ['issues' => $issues, 'warnings' => [], 'travel' => []])->all();
        foreach ($eligibility->assessDetailedMany($shift, $eligible, $lock, $context + ['travel_preflight' => $prepared[$key]]) as $id => $detail) {
            $details[$id] = $detail;
        }

        return $details;
    }

    private function travelPreference(array $detail): array
    {
        $legs = collect($detail['travel'] ?? []);
        if ($legs->contains(fn ($leg) => ($leg['status'] ?? null) !== 'known')) {
            return ['seconds' => null, 'margin' => null, 'reason' => 'Fahrt ungeprüft; keine Bevorzugung durch fehlende Fahrtdaten.'];
        }
        $seconds = (int) $legs->sum('duration_seconds');
        $margin = $legs->isEmpty() ? null : (int) floor($legs->min(fn ($leg) => ($leg['available_seconds'] - $leg['duration_seconds']) / 60 - $leg['reserve_minutes'] - $leg['rest_minutes']));

        return ['seconds' => $seconds, 'margin' => $margin, 'reason' => $legs->isEmpty() ? 'Keine benachbarte Dienstfahrt.'
            : 'Geplante Fahrten: '.(int) ceil($seconds / 60).' min; kleinster Zeitpuffer nach Reserve und Ruhezeit: '.$margin.' min. Bei gleichem Planungsscore werden kürzere bekannte Fahrten bevorzugt.'];
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

        return TomTomRoutingService::withinOperation(function () use ($from, $until, $goals, $fingerprint, $actor): PlanVariant {
            $prepared = [];
            $fresh = $this->calculatePreview($from, $until, $goals, $actor, $prepared);
            if ($fresh['entries'] === [] || ! hash_equals($fresh['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['workflow' => 'Planvorschau wurde geändert. Bitte neu berechnen.']);
            }
            $entryPreflights = app(TravelAssessmentService::class)->preflightForEntries(collect($fresh['entries'])->map(fn ($entry) => ['shift_id' => $entry['shift_id'], 'user_ids' => $entry['user_ids']])->all());

            return OperationsTransaction::run(function () use ($from, $until, $goals, $fingerprint, $actor, &$prepared, $entryPreflights) {
                [$start,$end] = app(PlanningCapacityService::class)->range($from, $until);
                $ids = Shift::where('status', 'draft')->where('published_revision', 0)->during($start, $end)->pluck('id')->all();
                PlanningLocks::acquire($ids, User::where('role', 'staff')->where('status', true)->pluck('id')->all());
                $preview = $this->calculatePreview($from, $until, $goals, $actor, $prepared, true);
                if ($preview['entries'] === [] || ! hash_equals($preview['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['workflow' => 'Planvorschau wurde geändert. Bitte neu berechnen.']);
                }
                $last = substr(collect($preview['entries'])->max('ends_at'), 0, 10);
                $variant = app(PlanVariantService::class)->save(null, null, ['name' => 'Planvorschlag '.$from, 'from' => $from, 'until' => max($until, $last), 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'entries' => $preview['entries']], $actor);
                $variantPreflights = [];
                foreach ($preview['entries'] as $index => $entry) {
                    $variantPreflights[$index] = $entryPreflights[$entry['shift_id']] ?? [];
                }
                $check = app(PlanVariantService::class)->preview($variant, $actor, $variantPreflights, true);
                if (! $check['valid']) {
                    throw ValidationException::withMessages(['workflow' => 'Gesamtvorschlag enthält Konflikte.']);
                }

                return $variant->fresh();
            }, 3);
        });
    }
}
