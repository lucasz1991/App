<?php

namespace App\Services\Operations;

use App\Models\OperationsRateRule;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsEnhancementsSchema;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class WorkTimeRemunerationService
{
    /** Raw timestamps and account credits are never modified by remuneration rules. */
    public function evaluate(WorkTimeEntry $entry, ?CarbonInterface $from = null, ?CarbonInterface $until = null): array
    {
        if (! OperationsEnhancementsSchema::ready()) {
            return ['complete' => false, 'issues' => ['Vergütungsregeln fehlen.'], 'quantities' => []];
        }
        if ($entry->status !== 'approved' || ! $entry->ends_at) {
            return ['complete' => false, 'issues' => ['Arbeitszeit nicht freigegeben.'], 'quantities' => []];
        }
        $rules = OperationsRateRule::where('kind', 'remuneration')->whereNotNull('approved_at')->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $entry->user_id))->orderBy('id')->get();
        $sections = $entry->activities()->orderBy('starts_at')->get();
        if ($sections->isEmpty()) {
            if ($entry->pause_seconds > 0) {
                return ['complete' => false, 'issues' => ['Pausenlage für Vergütungsbewertung fehlt.'], 'quantities' => []];
            }
            $sections = collect([(object) ['kind' => in_array($entry->work_context, ['internal', 'training']) ? $entry->work_context : 'work', 'starts_at' => $entry->starts_at, 'ends_at' => $entry->ends_at, 'source' => 'reported']]);
        }
        $issues = [];
        $quantities = [];
        $cursor = $entry->starts_at->utc();
        $pause = 0;
        foreach ($sections as $section) {
            if (! $section->ends_at || $section->source === 'needs_review' || ! $section->starts_at->equalTo($cursor)) {
                $issues[] = 'Istabschnitte prüfen.';

                continue;
            }
            $cursor = $section->ends_at->utc();
            if ($section->kind === 'break') {
                $pause += (int) $section->starts_at->diffInSeconds($section->ends_at);
            }
            $sectionStart = $section->starts_at->max($from ?? $entry->starts_at)->utc();
            $sectionEnd = $section->ends_at->min($until ?? $entry->ends_at)->utc();
            if (! $sectionEnd->gt($sectionStart)) {
                continue;
            }
            for ($date = $sectionStart->setTimezone($entry->timezone)->startOfDay(); $date->lt($sectionEnd); $date = $date->addDay()) {
                $start = $sectionStart->max($date->utc());
                $end = $sectionEnd->min($date->addDay()->utc());
                $applicable = $rules->filter(fn ($r) => $r->configuration['activity'] === $section->kind && $date->toDateString() >= $r->starts_on->toDateString() && (! $r->ends_on || $date->toDateString() <= $r->ends_on->toDateString()) && (empty($r->configuration['weekdays']) || in_array($date->isoWeekday(), $r->configuration['weekdays'], true)));
                $bases = $applicable->filter(fn ($r) => ! $r->configuration['additive'] && empty($r->configuration['window_start']));
                if ($bases->count() !== 1) {
                    $issues[] = $section->kind.': eindeutige Grundbewertung fehlt.';

                    continue;
                }
                foreach ($applicable as $rule) {
                    $c = $rule->configuration;
                    $seconds = (int) $start->diffInSeconds($end);
                    if (! empty($c['window_start'])) {
                        $seconds = 0;
                        foreach ([$date->subDay(), $date] as $windowDate) {
                            $a = CarbonImmutable::parse($windowDate->toDateString().' '.$c['window_start'], $entry->timezone);
                            $b = CarbonImmutable::parse($windowDate->toDateString().' '.$c['window_end'], $entry->timezone);
                            if ($b->lte($a)) {
                                $b = $b->addDay();
                            }
                            $a = $a->max($start);
                            $b = $b->min($end);
                            if ($b->gt($a)) {
                                $seconds += (int) $a->diffInSeconds($b);
                            }
                        }
                    }
                    if ($seconds === 0) {
                        continue;
                    }
                    $key = $rule->id.':'.$c['wage_code'];
                    if (! isset($quantities[$key])) {
                        $quantities[$key] = ['rule_id' => $rule->id, 'rule_revision' => $rule->revision, 'wage_code' => $c['wage_code'], 'raw_seconds' => 0, 'multiplier_bps' => $c['multiplier_bps'], 'rounding_minutes' => $c['rounding_minutes'], 'rounding_mode' => $c['rounding_mode']];
                    }
                    $quantities[$key]['raw_seconds'] += $seconds;
                }
            }
        }
        if (! $cursor->equalTo($entry->ends_at) || $pause !== (int) $entry->pause_seconds) {
            $issues[] = 'Istabschnitte und Pausensumme stimmen nicht überein.';
        }
        foreach ($quantities as &$q) {
            $seconds = $q['raw_seconds'];
            $unit = $q['rounding_minutes'] * 60;
            if ($unit > 0) {
                $seconds = (int) (match ($q['rounding_mode']) {
                    'up' => ceil($seconds / $unit), 'down' => floor($seconds / $unit), default => round($seconds / $unit, 0, PHP_ROUND_HALF_UP)
                } * $unit);
            }
            $q['quantity_seconds'] = (int) floor($seconds * $q['multiplier_bps'] / 10000);
        }
        unset($q);

        return ['complete' => $issues === [], 'issues' => array_values(array_unique($issues)), 'quantities' => array_values($quantities)];
    }
}
