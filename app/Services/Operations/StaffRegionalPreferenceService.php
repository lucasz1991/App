<?php

namespace App\Services\Operations;

use App\Models\Shift;
use App\Models\StaffRegionalPreference;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\TimelineLocationPreview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** Explicit planning wishes, never derived from a private employee address. */
class StaffRegionalPreferenceService
{
    public function ready(): bool
    {
        return Schema::hasTable('staff_regional_preferences');
    }

    public function get(User $employee): array
    {
        if (! $this->ready()) {
            return $this->defaults();
        }

        $preference = StaffRegionalPreference::query()->where('user_id', $employee->id)->first();

        return $preference ? $this->configuration($preference) : $this->defaults();
    }

    /** Actor and expected revision are checked again inside the transaction. */
    public function save(User $employee, User $actor, array $config, int $expectedRevision): array
    {
        $this->authorize($actor->fresh() ?? abort(403));
        abort_unless($this->ready(), 503, 'Regionale Einsatzgebiete sind noch nicht eingerichtet.');
        OperationsAccess::requireReady();
        $validated = $this->validate($config);
        if ($expectedRevision < 0) {
            throw ValidationException::withMessages(['regional_preferences' => 'Die Versionsnummer ist ungültig.']);
        }

        return OperationsTransaction::run(function () use ($employee, $actor, $validated, $expectedRevision): array {
            // The same employee lock is used by assignment. This also serializes
            // first-time preference creation, when there is no row to lock yet.
            $users = User::query()->whereIn('id', [$employee->id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedActor = $users->get($actor->id) ?? abort(403);
            $this->authorize($lockedActor);
            $lockedEmployee = $users->get($employee->id) ?? abort(404);
            abort_unless($lockedEmployee->role === 'staff' && $lockedEmployee->status, 422, 'Nur aktive Mitarbeitende können Einsatzgebiete erhalten.');

            $preference = StaffRegionalPreference::query()->where('user_id', $employee->id)->lockForUpdate()->first();
            if (($preference?->revision ?? 0) !== $expectedRevision) {
                throw ValidationException::withMessages(['regional_preferences' => 'Die Einsatzgebiete wurden zwischenzeitlich geändert. Bitte neu laden und erneut prüfen.']);
            }
            $before = $preference ? $this->configuration($preference) : $this->defaults();
            $preference ??= new StaffRegionalPreference(['user_id' => $employee->id]);
            $preference->fill([
                'revision' => $expectedRevision + 1,
                'preferences' => ['schema_version' => 1] + $validated,
                'updated_by' => $actor->id,
            ])->save();
            app(OperationsAuditService::class)->record($preference, $lockedActor, 'staff.regional_preferences.saved', [
                'user_id' => $employee->id,
                'before' => $before,
                'after' => $this->configuration($preference),
            ]);

            return $this->configuration($preference);
        });
    }

    /**
     * A single preference query for a candidate batch; locality lookups are
     * immutable local data. Distance is approximate straight-line distance,
     * never commuting time or verified worksite precision.
     *
     * @return array<int, array{state:string,label:string,detail:string,score_adjustment:int,distance_km:?float,location_known:bool,blocked:bool,revision:int}>
     */
    public function assessMany(Shift $shift, Collection $users, bool $lock = false): array
    {
        if ($users->isEmpty()) {
            return [];
        }
        $preferences = $this->ready()
            ? StaffRegionalPreference::query()->whereIn('user_id', $users->pluck('id')->all())
                ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('user_id')
            : collect();
        // Loading the order is deliberate here; the preview renderer itself
        // remains query-free and never mixes an override with the order city.
        $shift->loadMissing('order');
        $location = TimelineLocationPreview::forRegionalAssessment($shift);
        $resolved = [];
        $results = [];
        foreach ($users as $user) {
            $preference = $preferences->get($user->id);
            $config = $preference ? $this->configuration($preference) : $this->defaults();
            $revision = $config['revision'];
            if (! $config['enabled']) {
                $results[$user->id] = $this->result('neutral', 'Keine Gebietsvorgabe', 'Keine aktive regionale Einschränkung; ohne Einfluss auf die Eignung.', 0, $revision, $location);

                continue;
            }
            if ($location['state'] !== 'located') {
                $results[$user->id] = $this->result('unknown', 'Gebiet ungeprüft', 'Einsatzort nicht eindeutig verortet. Bevorzugte und ausgeschlossene Gebiete bitte manuell prüfen.', 0, $revision, $location);

                continue;
            }
            $noGo = null;
            foreach ($config['no_go_areas'] as $area) {
                $point = $resolved[$area['location']] ??= $this->resolve($area['location']);
                if ($point['state'] !== 'located') {
                    throw new UnexpectedValueException('Stored regional exclusion cannot be resolved.');
                }
                $distance = $this->distance($location, $point);
                if ($distance <= $area['radius_km']) {
                    $noGo = $this->result('no_go', 'No-Go-Gebiet', 'Ausgeschlossenes Einsatzgebiet: '.$point['place'].' · '.$area['radius_km'].' km Radius (ungefähre Ortslage).', -100, $revision, $location, $distance);

                    break;
                }
            }
            if ($noGo) {
                $results[$user->id] = $noGo;

                continue;
            }
            $base = $resolved[$config['base_location']] ??= $this->resolve($config['base_location']);
            if ($base['state'] !== 'located') {
                throw new UnexpectedValueException('Stored regional preference cannot be resolved.');
            }
            $distance = $this->distance($location, $base);
            $distanceText = number_format($distance, 0, ',', '.').' km Luftlinie ab '.$base['place'].' · Ortslagen ungefähr, keine Fahrzeit.';
            if ($distance <= $config['preferred_radius_km']) {
                $results[$user->id] = $this->result('preferred', 'Bevorzugtes Gebiet', $distanceText, 15, $revision, $location, $distance);
            } elseif ($distance <= $config['border_radius_km']) {
                $results[$user->id] = $this->result('border', 'Grenzgebiet', $distanceText, 5, $revision, $location, $distance);
            } else {
                $results[$user->id] = $this->result('outside', 'Außerhalb Wunschgebiet', $distanceText.' Nicht gesperrt.', -10, $revision, $location, $distance);
            }
        }

        return $results;
    }

    public function assertAllowed(Shift $shift, User $user, bool $lock = false): void
    {
        $assessment = $this->assessMany($shift, new \Illuminate\Database\Eloquent\Collection([$user]), $lock)[$user->id];
        if ($assessment['blocked']) {
            throw ValidationException::withMessages(['workflow' => $assessment['detail'].' Eine Zeitkonflikt-Ausnahme hebt dieses No-Go-Gebiet nicht auf.']);
        }
    }

    private function authorize(User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        abort_unless(in_array($actor->dashboardAudience(), ['admin', 'administration', 'management'], true), 403);
    }

    private function defaults(): array
    {
        return ['revision' => 0, 'enabled' => false, 'base_location' => '', 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => []];
    }

    private function configuration(StaffRegionalPreference $preference): array
    {
        $config = $preference->preferences;
        // Corruption or unknown schema must not silently drop a saved No-Go.
        if (! is_array($config) || ($config['schema_version'] ?? null) !== 1
            || ! is_bool($config['enabled'] ?? null)
            || ! is_string($config['base_location'] ?? null)
            || ! is_numeric($config['preferred_radius_km'] ?? null)
            || ! is_numeric($config['border_radius_km'] ?? null)
            || ! is_array($config['no_go_areas'] ?? null)
            || ($config['enabled'] && trim($config['base_location']) === '')
            || $config['preferred_radius_km'] < 1 || $config['preferred_radius_km'] > 1000
            || $config['border_radius_km'] < $config['preferred_radius_km'] || $config['border_radius_km'] > 2000
            || count($config['no_go_areas']) > 20) {
            throw new UnexpectedValueException('Unsupported or invalid regional preference configuration.');
        }
        foreach ($config['no_go_areas'] as $area) {
            if (! is_array($area) || ! is_string($area['location'] ?? null) || ! is_numeric($area['radius_km'] ?? null) || $area['radius_km'] < 1 || $area['radius_km'] > 1000) {
                throw new UnexpectedValueException('Invalid stored regional exclusion.');
            }
        }

        return ['revision' => $preference->revision] + array_intersect_key($config, $this->defaults());
    }

    private function validate(array $config): array
    {
        $validated = Validator::make(['regional_preferences' => $config], [
            'regional_preferences' => ['required', 'array:enabled,base_location,preferred_radius_km,border_radius_km,no_go_areas'],
            'regional_preferences.enabled' => ['required', 'boolean'],
            'regional_preferences.base_location' => ['nullable', 'string', 'max:200', 'required_if:regional_preferences.enabled,true,1'],
            'regional_preferences.preferred_radius_km' => ['required', 'integer', 'min:1', 'max:1000'],
            'regional_preferences.border_radius_km' => ['required', 'integer', 'gte:regional_preferences.preferred_radius_km', 'max:2000'],
            'regional_preferences.no_go_areas' => ['present', 'array', 'max:20'],
            'regional_preferences.no_go_areas.*' => ['array:location,radius_km'],
            'regional_preferences.no_go_areas.*.location' => ['required', 'string', 'max:200'],
            'regional_preferences.no_go_areas.*.radius_km' => ['required', 'integer', 'min:1', 'max:1000'],
        ])->validate()['regional_preferences'];
        $validated['enabled'] = (bool) $validated['enabled'];
        $validated['base_location'] = trim($validated['base_location'] ?? '');
        $validated['preferred_radius_km'] = (int) $validated['preferred_radius_km'];
        $validated['border_radius_km'] = (int) $validated['border_radius_km'];
        if ($validated['base_location'] !== '') {
            $validated['base_location'] = $this->validatedLocation($validated['base_location'], 'regional_preferences.base_location');
        }
        $areas = [];
        foreach ($validated['no_go_areas'] as $index => $area) {
            $place = $this->validatedLocation(trim($area['location']), 'regional_preferences.no_go_areas.'.$index.'.location');
            // Canonical city aliases are one area; preserve the larger exclusion.
            $areas[$place] = ['location' => $place, 'radius_km' => max((int) $area['radius_km'], $areas[$place]['radius_km'] ?? 0)];
        }
        $validated['no_go_areas'] = array_values($areas);

        return $validated;
    }

    private function validatedLocation(string $name, string $field): string
    {
        $point = $this->resolve($name);
        if ($point['state'] !== 'located') {
            throw ValidationException::withMessages([$field => 'Bitte einen eindeutig bekannten Ort in Deutschland angeben. Mehrdeutige oder unbekannte Orte werden nicht geschätzt.']);
        }

        return $point['place'];
    }

    private function resolve(string $location): array
    {
        return TimelineLocationPreview::forRegionalAssessment(new Shift(['location_name' => $location]));
    }

    private function distance(array $a, array $b): float
    {
        $latA = deg2rad($a['latitude']);
        $latB = deg2rad($b['latitude']);
        $halfLat = sin(($latB - $latA) / 2);
        $halfLon = sin(deg2rad($b['longitude'] - $a['longitude']) / 2);
        $haversine = $halfLat ** 2 + cos($latA) * cos($latB) * $halfLon ** 2;

        return 6371.0088 * 2 * asin(sqrt(max(0, min(1, $haversine))));
    }

    private function result(string $state, string $label, string $detail, int $score, int $revision, array $location, ?float $distance = null): array
    {
        return ['state' => $state, 'label' => $label, 'detail' => $detail, 'score_adjustment' => $score, 'distance_km' => $distance === null ? null : round($distance, 1), 'location_known' => $location['state'] === 'located', 'blocked' => $state === 'no_go', 'revision' => $revision];
    }
}
