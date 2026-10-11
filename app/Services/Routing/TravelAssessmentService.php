<?php

namespace App\Services\Routing;

use App\Models\OperationsRuleProfile;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Services\EmployeeLocation\EmployeeLocationReader;
use App\Services\Operations\WorkforceAccountService;
use App\Support\Operations\OperationsAccess;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TravelAssessmentService
{
    public function preflight(Shift $shift, Collection $users, array $context = []): TravelPreflight
    {
        unset($context['travel_preflight'], $context['skip_provider'], $context['skip_routing'], $context['include_employee_location']);

        return new TravelPreflight($this->assessMany($shift, $users, $context), $context);
    }

    /** Only after a workflow validated its unchanged projections under PlanningLocks. */
    public function rebasePreflight(Shift $saved, User $user, TravelPreflight $projected, array $context = []): TravelPreflight
    {
        $current = $this->assessMany($saved, collect([$user]), ['skip_provider' => true] + $context, true)[$user->id];
        $previous = $projected->assessments[$user->id] ?? [];
        $compare = fn ($legs) => collect($legs)->map(fn ($leg) => array_intersect_key($leg, array_flip([
            'direction', 'origin', 'destination', 'departure', 'available_seconds', 'reserve_minutes', 'rest_minutes', 'same_location',
        ])))->all();
        if ($compare($current['travel']) !== $compare($previous['travel'] ?? [])) {
            throw ValidationException::withMessages(['workflow' => 'Die gespeicherte Einsatzfolge weicht von der geprüften Fahrzeitplanung ab. Bitte erneut prüfen.']);
        }
        $previous['basis'] = $current['basis'];
        foreach ($current['travel'] as $direction => $leg) {
            $previous['travel'][$direction] = array_replace($previous['travel'][$direction], array_intersect_key($leg, array_flip(['shift_id', 'title', 'origin_label', 'destination_label'])));
        }

        return new TravelPreflight([$user->id => $previous], $context);
    }

    /** Route facts are ephemeral; basis contains only our own planning inputs. */
    public function assessMany(Shift $shift, Collection $users, array $context = [], bool $lock = false): array
    {
        if (($context['travel_preflight'] ?? null) instanceof TravelPreflight) {
            $context = array_replace($context['travel_preflight']->context, $context);
        }
        $settings = TomTomSettings::all();
        $results = $users->mapWithKeys(fn ($user) => [$user->id => ['issues' => [], 'warnings' => [], 'travel' => [], 'basis' => '']])->all();
        if ($users->isEmpty() || ! OperationsAccess::ready() || ($context['skip_routing'] ?? false)) {
            return $results;
        }
        if (! $settings['enabled']) {
            // Disabled rollout adds no schedule/profile/catalogue reads to existing guards.
            foreach ($users as $user) {
                $results[$user->id]['basis'] = hash('sha256', json_encode(['target' => $this->snapshot($shift), 'enabled' => false, 'user_id' => $user->id], JSON_THROW_ON_ERROR));
                $previous = ($context['travel_preflight'] ?? null) instanceof TravelPreflight ? ($context['travel_preflight']->assessments[$user->id] ?? null) : null;
                if ($lock && $previous !== null && ! hash_equals($results[$user->id]['basis'], (string) ($previous['basis'] ?? ''))) {
                    throw ValidationException::withMessages(['workflow' => 'Fahrt- und Planungsgrundlagen haben sich geändert. Bitte erneut prüfen.']);
                }
            }

            return $results;
        }
        $start = CarbonImmutable::instance($shift->starts_at);
        $end = CarbonImmutable::instance($shift->ends_at);
        $query = ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))
            ->whereNotIn('shift_id', array_unique(array_merge($context['exclude_shift_ids'] ?? [], $shift->id ? [$shift->id] : [])))
            ->whereHas('shift', fn ($q) => $q->notCancelled()->during($start->subDays(90), $end->addDays(90)))
            ->orderBy('id')->with('shift');
        $assignments = ($lock ? $query->lockForUpdate() : $query)->get()->groupBy('user_id');
        $accounts = app(WorkforceAccountService::class);
        if ($lock) {
            OperationsRuleProfile::orderBy('id')->lockForUpdate()->get();
        }
        $fallback = OperationsRuleProfile::where('is_active', true)->first();
        $neighbors = [];
        $moments = [];
        foreach ($users as $user) {
            $others = $assignments->get($user->id, collect())->pluck('shift')->merge(collect($context['additional_shifts'] ?? []));
            if (class_exists(CustomerCapacityService::class)) {
                $others = $others->merge(app(CustomerCapacityService::class)->additionalShifts($shift, $user));
            }
            $others = $others->filter(fn ($other) => $other instanceof Shift && $other !== $shift
                && ! ($shift->id && $other->id === $shift->id))
                ->unique(fn ($other) => $other->id ?: json_encode($this->snapshot($other)));
            $before = $others->filter(fn ($other) => $other->ends_at->lte($start))->sortByDesc(fn ($other) => $other->ends_at->getTimestamp())->first();
            $after = $others->filter(fn ($other) => $other->starts_at->gte($end))->sortBy(fn ($other) => $other->starts_at->getTimestamp())->first();
            $neighbors[$user->id] = ['before' => $before, 'after' => $after];
            $moments[$user->id] = ['target' => $start] + array_filter(['before' => $before?->starts_at, 'after' => $after?->starts_at]);
        }
        $profiles = $accounts->ready() ? $accounts->effectiveRulesForMoments($users, $moments, $fallback, $lock)
            : $users->mapWithKeys(fn ($user) => [$user->id => array_fill_keys(array_keys($moments[$user->id]), $fallback)]);
        $requests = [];
        foreach ($users as $user) {
            $ownProfile = $profiles->get($user->id)['target'];
            $basis = ['target' => $this->snapshot($shift), 'user_id' => $user->id, 'settings' => array_intersect_key($settings, array_flip(['enabled', 'reserve_minutes'])), 'profile' => $this->profileSnapshot($ownProfile), 'neighbors' => []];
            foreach ($neighbors[$user->id] as $direction => $other) {
                if (! $other) {
                    continue;
                }
                $otherProfile = $profiles->get($user->id)[$direction];
                $rest = max((int) $ownProfile?->minimum_rest_minutes, (int) $otherProfile?->minimum_rest_minutes);
                $originShift = $direction === 'before' ? $other : $shift;
                $destinationShift = $direction === 'before' ? $shift : $other;
                $origin = self::point($originShift);
                $destination = self::point($destinationShift);
                $same = $origin !== null && $destination !== null && $origin === $destination;
                $basis['neighbors'][$direction] = ['shift' => $this->snapshot($other), 'profile' => $this->profileSnapshot($otherProfile)];
                $gapSeconds = $originShift->ends_at->diffInSeconds($destinationShift->starts_at);
                $leg = [
                    'direction' => $direction, 'shift_id' => $other->id, 'title' => $other->title,
                    'origin' => $origin, 'destination' => $destination,
                    'origin_label' => $originShift->location_snapshot['name'] ?? $originShift->location_name,
                    'destination_label' => $destinationShift->location_snapshot['name'] ?? $destinationShift->location_name,
                    'departure' => $originShift->ends_at->utc()->toIso8601String(),
                    'available_seconds' => (int) $gapSeconds, 'available_minutes' => (int) floor($gapSeconds / 60),
                    'reserve_minutes' => $same ? 0 : (int) $settings['reserve_minutes'], 'rest_minutes' => $rest,
                    'same_location' => $same, 'duration_seconds' => $same ? 0 : null,
                    'distance_meters' => $same ? 0 : null, 'status' => $same ? 'known' : 'unknown',
                    'reason' => $same ? null : (! $settings['enabled'] ? 'disabled' : ($origin === null || $destination === null ? 'site_unconfirmed' : 'pending')),
                ];
                $results[$user->id]['travel'][$direction] = $leg;
                if ($settings['enabled'] && ! $same && $origin !== null && $destination !== null) {
                    $requests[$user->id.':'.$direction] = ['origin' => $origin, 'destination' => $destination, 'departure' => $leg['departure']];
                }
            }
            $results[$user->id]['basis'] = hash('sha256', json_encode($basis, JSON_THROW_ON_ERROR));
        }
        $preflight = $context['travel_preflight'] ?? null;
        if ($preflight instanceof TravelPreflight) {
            foreach ($users as $user) {
                $previous = $preflight->assessments[$user->id] ?? null;
                if (! is_array($previous) || ! hash_equals($results[$user->id]['basis'], (string) ($previous['basis'] ?? ''))) {
                    if ($lock) {
                        throw ValidationException::withMessages(['workflow' => 'Fahrt- und Planungsgrundlagen haben sich geändert. Bitte erneut prüfen.']);
                    }

                    continue;
                }
                $results[$user->id] = $previous;
                unset($requests[$user->id.':before'], $requests[$user->id.':after']);
            }
        }
        $routeResults = ($context['skip_provider'] ?? false) ? [] : app(TomTomRoutingService::class)->summaryMany($requests);
        foreach ($users as $user) {
            foreach ($results[$user->id]['travel'] as $direction => &$leg) {
                $route = $routeResults[$user->id.':'.$direction] ?? null;
                if ($route !== null) {
                    $leg = array_replace($leg, $route);
                }
                if ($leg['status'] === 'known') {
                    $leg['duration_minutes'] = (int) ceil($leg['duration_seconds'] / 60);
                    $leg['required_minutes'] = $leg['duration_minutes'] + $leg['reserve_minutes'] + $leg['rest_minutes'];
                    $leg['missing_minutes'] = max(0, (int) ceil(($leg['duration_seconds'] + ($leg['reserve_minutes'] + $leg['rest_minutes']) * 60 - $leg['available_seconds']) / 60));
                    if ($settings['enabled'] && $leg['missing_minutes'] > 0) {
                        $results[$user->id]['issues'][] = ['code' => 'travel_'.$direction, 'message' => 'Fahrzeit, Reserve und Ruhezeit '.($direction === 'before' ? 'vom vorherigen Dienst' : 'zum folgenden Dienst').' reichen nicht aus ('.$leg['missing_minutes'].' min fehlen).'];
                    }
                } elseif ($settings['enabled']) {
                    $results[$user->id]['warnings'][] = ['code' => 'travel_unchecked_'.$direction, 'message' => 'Fahrt ungeprüft: '.self::reasonLabel($leg['reason']), 'reason' => $leg['reason']];
                }
            }
            unset($leg);
            $results[$user->id]['issues'] = collect($results[$user->id]['issues'])->unique('code')->values()->all();
            $results[$user->id]['warnings'] = collect($results[$user->id]['warnings'])->unique('code')->values()->all();
        }
        if (($context['include_employee_location'] ?? false) && $settings['use_employee_locations'] && DB::transactionLevel() === 0) {
            $this->addEmployeeArrival($shift, $users, $context, $results);
        }

        return $results;
    }

    public static function point(Shift $shift): ?array
    {
        $snapshot = $shift->location_snapshot ?? [];
        if (($snapshot['confirmed'] ?? false) !== true || ! TomTomRoutingService::validPoint($snapshot)) {
            return null;
        }

        return ['latitude' => (float) $snapshot['latitude'], 'longitude' => (float) $snapshot['longitude']];
    }

    public function geometryFor(Shift $shift, User $user, string $direction, array $context = []): array
    {
        // Derive coordinates server-side from the current authorized selection.
        if ($direction === 'arrival') {
            $settings = TomTomSettings::all();
            $now = CarbonImmutable::now();
            if (! $settings['use_employee_locations'] || ! $this->arrivalWindow($shift, $now) || ! class_exists(EmployeeLocationReader::class)) {
                return TomTomRoutingService::unknown('location_disabled');
            }
            $value = app(EmployeeLocationReader::class)->latestForMany(collect([$user]), $context)[$user->id] ?? [];
            $point = $value['point'] ?? null;
            $target = self::point($shift);
            if (! $this->validEmployeePoint($point, $now) || $target === null) {
                return TomTomRoutingService::unknown($value['reason'] ?? 'point_unavailable');
            }

            return app(TomTomRoutingService::class)->geometry($point, $target, $now);
        }
        $detail = $this->assessMany($shift, collect([$user]), ['skip_provider' => true] + $context)[$user->id];
        $leg = $detail['travel'][$direction] ?? null;
        if (! $leg || ! is_array($leg['origin'] ?? null) || ! is_array($leg['destination'] ?? null)) {
            return TomTomRoutingService::unknown('site_unconfirmed');
        }

        return app(TomTomRoutingService::class)->geometry($leg['origin'], $leg['destination'], $leg['departure']);
    }

    private function addEmployeeArrival(Shift $shift, Collection $users, array $context, array &$results): void
    {
        $now = CarbonImmutable::now();
        if (! $this->arrivalWindow($shift, $now)) {
            return;
        }
        $points = class_exists(EmployeeLocationReader::class) ? app(EmployeeLocationReader::class)->latestForMany($users, $context) : [];
        $target = self::point($shift);
        $requests = [];
        foreach ($users as $user) {
            $value = $points[$user->id] ?? ['status' => 'unavailable', 'reason' => 'location_disabled', 'point' => null];
            $point = $value['point'] ?? null;
            $valid = $this->validEmployeePoint($point, $now);
            $results[$user->id]['arrival'] = ['status' => 'unknown', 'reason' => $value['reason'] ?? 'point_unavailable', 'label' => 'Aktueller Anfahrtsweg nicht verfügbar', 'departure_assumption' => 'jetzt'];
            if ($valid && $target !== null && TomTomSettings::all()['enabled']) {
                $requests[$user->id] = ['origin' => $point, 'destination' => $target, 'departure' => $now];
                $results[$user->id]['arrival'] += ['measured_at' => $point['measured_at'], 'accuracy_m' => $point['accuracy_m']];
            }
        }
        foreach (($context['skip_provider'] ?? false) ? [] : app(TomTomRoutingService::class)->summaryMany($requests) as $id => $route) {
            $arrival = &$results[$id]['arrival'];
            $arrival = array_replace($arrival, $route);
            if ($route['status'] === 'known') {
                $arrival['label'] = 'Anfahrt ab letzter Messung';
                $arrival['arrival_at'] = $now->addSeconds($route['duration_seconds'])->toIso8601String();
                $arrival['late_hint'] = $now->addSeconds($route['duration_seconds'])->gt($shift->starts_at);
            }
            unset($arrival);
        }
    }

    private function arrivalWindow(Shift $shift, CarbonImmutable $now): bool
    {
        return $shift->starts_at->setTimezone('Europe/Berlin')->toDateString() === $now->setTimezone('Europe/Berlin')->toDateString()
            && $shift->starts_at->gte($now) && $shift->starts_at->lte($now->addHour());
    }

    private function validEmployeePoint(mixed $point, CarbonImmutable $now): bool
    {
        if (! is_array($point) || ! TomTomRoutingService::validPoint($point) || ! is_numeric($point['accuracy_m'] ?? null)
            || $point['accuracy_m'] < 0 || $point['accuracy_m'] > 50 || ! is_string($point['measured_at'] ?? null) || $point['measured_at'] === '') {
            return false;
        }
        try {
            return CarbonImmutable::parse($point['measured_at'] ?? '')->betweenIncluded($now->subMinutes(15), $now);
        } catch (\Throwable) {
            return false;
        }
    }

    private function snapshot(Shift $shift): array
    {
        return ['id' => $shift->id, 'revision' => $shift->revision, 'starts_at' => $shift->starts_at?->utc()->toIso8601String(), 'ends_at' => $shift->ends_at?->utc()->toIso8601String(), 'location_snapshot' => $shift->location_snapshot ?? [], 'transfer_buffer_minutes' => (int) ($shift->disposition_details['transfer_buffer_minutes'] ?? 0)];
    }

    private function profileSnapshot(?OperationsRuleProfile $profile): ?array
    {
        return $profile?->only(['id', 'is_active', 'minimum_rest_minutes', 'maximum_shift_minutes', 'break_after_minutes', 'minimum_break_minutes', 'approved_at']);
    }

    public static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'disabled' => 'Routenplanung deaktiviert', 'missing_key' => 'API-Schlüssel fehlt',
            'site_unconfirmed' => 'Einsatzstandort nicht bestätigt', 'budget_exhausted' => 'Monatsgrenze erreicht',
            'schema_missing' => 'Routenplanung noch nicht eingerichtet',
            'provider_limit' => 'TomTom-Limit erreicht', 'transaction_requires_preflight' => 'Vorprüfung fehlt',
            'provider_unavailable' => 'Anbieter derzeit nicht erreichbar', default => 'Route derzeit nicht berechenbar',
        };
    }
}
