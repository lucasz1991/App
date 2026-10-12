<?php

namespace App\Services\Operations;

use App\Models\Shift;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Request-local display projection. Never an assignment permission or a provider call. */
class PlanningTravelDisplay
{
    public function current(Shift $shift, Collection $users, array $stored = [], array $context = []): array
    {
        $core = app(StaffEligibilityService::class)->assessPreviewCoreMany($shift, $users, $context);
        $fresh = app(TravelAssessmentService::class)->assessMany($shift, $users, array_replace($context, ['skip_provider' => true, 'include_employee_location' => false]));
        foreach ($fresh as $id => &$detail) {
            $previous = $stored[$id] ?? null;
            if ($this->reusable($detail, $previous)) {
                // Recompute core rules every render; only reuse the planned route facts.
                $detail = array_replace($detail, array_intersect_key($previous, array_flip(['travel', 'warnings', '_display_checked_at'])));
                $detail['issues'] = array_values(array_filter($previous['issues'] ?? [], fn ($issue) => str_starts_with($issue['code'] ?? '', 'travel_')));
            }
            $detail['issues'] = collect(array_merge($core[$id] ?? [], $detail['issues'] ?? []))->unique('code')->values()->all();
            $detail += $this->status($detail);
        }
        unset($detail);

        return $fresh;
    }

    public function reusable(array $current, ?array $previous): bool
    {
        if (! $previous || empty($current['basis']) || ! hash_equals($current['basis'], (string) ($previous['basis'] ?? '')) || empty($previous['_display_checked_at'])) {
            return false;
        }
        try {
            $now = CarbonImmutable::now('UTC');
            $checked = CarbonImmutable::parse($previous['_display_checked_at']);
            $minutes = max(1, min(5, (int) TomTomSettings::all()['server_route_cache_minutes']));
            if ($checked->isAfter($now) || $checked->addMinutes($minutes)->lte($now)) {
                return false;
            }
            foreach ($previous['travel'] ?? [] as $leg) {
                if (! empty($leg['expires_at']) && CarbonImmutable::parse($leg['expires_at'])->lte($now)) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function stamp(array $details): array
    {
        foreach ($details as &$detail) {
            $detail['_display_checked_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        }
        unset($detail);

        return $details;
    }

    public function status(array $detail): array
    {
        $legs = collect($detail['travel'] ?? []);
        $state = ! TomTomSettings::all()['enabled'] ? 'disabled'
            : (collect($detail['issues'] ?? [])->contains(fn ($issue) => str_starts_with($issue['code'] ?? '', 'travel_')) ? 'conflict'
                : ($legs->contains(fn ($leg) => ($leg['status'] ?? null) !== 'known')
                    ? ($legs->contains(fn ($leg) => ($leg['reason'] ?? '') === 'pending') ? 'pending' : 'unknown') : 'known'));

        return ['route_state' => $state,
            'route_label' => ['disabled' => 'Fahrzeitprüfung deaktiviert', 'pending' => 'Fahrt noch nicht geprüft', 'unknown' => 'Fahrt ungeprüft', 'conflict' => 'Fahrzeitkonflikt', 'known' => $legs->isEmpty() ? 'Keine benachbarten Einsätze' : 'Fahrten geprüft'][$state],
            'planned_duration_seconds' => in_array($state, ['known', 'conflict'], true) && $legs->every(fn ($leg) => ($leg['status'] ?? null) === 'known') ? (int) $legs->sum('duration_seconds') : null];
    }
}
