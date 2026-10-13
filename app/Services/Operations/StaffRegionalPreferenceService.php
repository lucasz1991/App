<?php

namespace App\Services\Operations;

use App\Models\Shift;
use App\Models\StaffRegionalPreference;
use App\Models\User;
use App\Support\Operations\GermanFederalStates;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\RegionalOriginPoint;
use App\Support\Operations\TimelineLocationPreview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

    /** One population read and one preference read; return only an opaque hash. */
    public function revisionFingerprint(): string
    {
        $population = User::query()->where('role', 'staff')->where('status', true);
        $ids = (clone $population)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ready = $this->ready();
        $preferences = $ready && $ids !== []
            ? StaffRegionalPreference::query()->whereIn('user_id', $population->select('id'))->orderBy('user_id')->get(['user_id', 'revision', 'preferences'])
                ->map(fn (StaffRegionalPreference $preference) => [(int) $preference->user_id, (int) $preference->revision, hash('sha256', (string) $preference->getRawOriginal('preferences'))])->all()
            : [];

        return hash('sha256', json_encode([$ready, $ids, $preferences], JSON_THROW_ON_ERROR));
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
                'preferences' => $validated,
                'updated_by' => $actor->id,
            ])->save();
            app(OperationsAuditService::class)->record($preference, $lockedActor, 'staff.regional_preferences.saved', [
                'user_id' => $employee->id,
                'before' => $this->auditConfiguration($before),
                'after' => $this->auditConfiguration($this->configuration($preference)),
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
        $legacyLocation = TimelineLocationPreview::forRegionalAssessment($shift);
        // Existing radius/no-go profiles retain their established locality
        // semantics. New zone profiles can use the shift's own confirmed site.
        $snapshot = $shift->getAttribute('location_snapshot');
        $confirmedLocation = is_array($snapshot) && ($snapshot['confirmed'] ?? false) === true
            ? TimelineLocationPreview::fromShift($shift) : null;
        $states = [];
        $resolved = [];
        $results = [];
        foreach ($users as $user) {
            $preference = $preferences->get($user->id);
            $config = $preference ? $this->configuration($preference) : $this->defaults();
            $revision = $config['revision'];
            $extended = $this->hasExtendedZones($config);
            $location = $extended && $confirmedLocation !== null ? $confirmedLocation : $legacyLocation;
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
            if ($extended && ! $this->hasPositiveAreas($config)) {
                $results[$user->id] = $this->result('neutral', 'Keine Gebietsvorgabe', 'Außerhalb der ausgeschlossenen Gebiete; keine bevorzugten Einsatzgebiete hinterlegt.', 0, $revision, $location);

                continue;
            }
            $distance = null;
            $distanceText = '';
            $radiusTier = null;
            if ($config['base_location'] !== '') {
                $origin = $config['base_point'];
                $base = $origin !== null
                    ? ['state' => 'located', 'place' => $origin['label'], 'latitude' => $origin['latitude'], 'longitude' => $origin['longitude']]
                    : ($resolved[$config['base_location']] ??= $this->resolve($config['base_location']));
                if ($base['state'] !== 'located') {
                    throw new UnexpectedValueException('Stored regional preference cannot be resolved.');
                }
                $distance = $this->distance($location, $base);
                $distanceText = $origin !== null
                    ? number_format($distance, 1, ',', '.').' km Luftlinie ab '.RegionalOriginPoint::description($origin).'. Keine Fahrzeit.'
                    : number_format($distance, 0, ',', '.').' km Luftlinie ab '.$base['place'].' · Ortslagen ungefähr, keine Fahrzeit.';
                $radiusTier = match (true) {
                    $distance <= $config['preferred_radius_km'] => 'preferred',
                    $distance <= $config['border_radius_km'] => 'possible',
                    $config['emergency_radius_km'] !== null && $distance <= $config['emergency_radius_km'] => 'emergency',
                    default => null,
                };
            }
            $stateCode = null;
            $hasStates = count(array_merge(...array_values($config['federal_states']))) > 0;
            if ($hasStates) {
                $key = $location['latitude'].'|'.$location['longitude'];
                if (! array_key_exists($key, $states)) {
                    $states[$key] = GermanFederalStates::containing((float) $location['latitude'], (float) $location['longitude']);
                }
                $stateCode = $states[$key];
            }
            $tier = null;
            foreach (['preferred', 'possible', 'emergency'] as $candidateTier) {
                if ($radiusTier === $candidateTier || ($stateCode !== null && in_array($stateCode, $config['federal_states'][$candidateTier], true))) {
                    $tier = $candidateTier;
                    break;
                }
            }
            if ($hasStates && $stateCode === null && $tier !== 'preferred') {
                $results[$user->id] = $this->result('unknown', 'Gebiet ungeprüft', 'Bundesland am Einsatzort nicht eindeutig bestimmt; Gebiet bitte manuell prüfen.', 0, $revision, $location, $distance);

                continue;
            }
            $stateText = $stateCode !== null ? (GermanFederalStates::options()[$stateCode].' · ') : '';
            $detail = trim($stateText.$distanceText, ' ·');
            $detail = ($detail !== '' ? $detail.' ' : '').match ($tier) {
                'preferred' => 'Im bevorzugten Einsatzgebiet.',
                'possible' => 'Im möglichen Einsatzgebiet.',
                'emergency' => 'Notfallgebiet: nachrangig berücksichtigen; alle Eignungs- und Fahrzeitregeln bleiben wirksam.',
                default => 'Außerhalb der hinterlegten Einsatzgebiete. Nicht gesperrt.',
            };
            [$state, $label, $score] = match ($tier) {
                'preferred' => ['preferred', 'Bevorzugtes Gebiet', 15],
                'possible' => ['border', 'Mögliches Gebiet', 5],
                'emergency' => ['emergency', 'Notfallgebiet', -10],
                default => ['outside', 'Außerhalb Wunschgebiet', $extended ? -20 : -10],
            };
            $results[$user->id] = $this->result($state, $label, $detail, $score, $revision, $location, $distance);
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
        return ['revision' => 0, 'schema_version' => 1, 'enabled' => false, 'base_location' => '', 'base_point' => null, 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'emergency_radius_km' => null, 'federal_states' => ['preferred' => [], 'possible' => [], 'emergency' => []], 'no_go_areas' => []];
    }

    private function configuration(StaffRegionalPreference $preference): array
    {
        $config = $preference->preferences;
        // Corruption or unknown schema must not silently drop a saved No-Go.
        if (! is_array($config) || ! in_array($config['schema_version'] ?? null, [1, 2], true)
            || (($config['schema_version'] ?? null) === 2 && (! array_key_exists('emergency_radius_km', $config) || ! array_key_exists('federal_states', $config)))
            || ! is_bool($config['enabled'] ?? null)
            || ! is_string($config['base_location'] ?? null)
            || ! is_numeric($config['preferred_radius_km'] ?? null)
            || ! is_numeric($config['border_radius_km'] ?? null)
            || ! is_array($config['no_go_areas'] ?? null)
            || (isset($config['federal_states']) && ! is_array($config['federal_states']))
            || (($config['schema_version'] ?? null) === 1 && $config['enabled'] && trim($config['base_location']) === '')
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

        $config = array_replace($this->defaults(), $config);
        if ($config['base_point'] !== null && ($config['schema_version'] !== 2 || ! RegionalOriginPoint::valid($config['base_point'], $config['base_location']))) {
            throw new UnexpectedValueException('Invalid stored regional origin.');
        }
        if (($config['emergency_radius_km'] !== null && (! is_numeric($config['emergency_radius_km']) || trim($config['base_location']) === '' || $config['emergency_radius_km'] < $config['border_radius_km'] || $config['emergency_radius_km'] > 2000))
            || ! is_array($config['federal_states']) || array_keys($config['federal_states']) !== ['preferred', 'possible', 'emergency']) {
            throw new UnexpectedValueException('Invalid stored extended regional configuration.');
        }
        $seen = [];
        foreach ($config['federal_states'] as $codes) {
            if (! is_array($codes) || ! array_is_list($codes) || count($codes) > 16) {
                throw new UnexpectedValueException('Invalid stored federal states.');
            }
            foreach ($codes as $code) {
                if (! is_string($code) || ! array_key_exists($code, GermanFederalStates::options()) || isset($seen[$code])) {
                    throw new UnexpectedValueException('Invalid or conflicting stored federal state.');
                }
                $seen[$code] = true;
            }
        }
        if ($config['schema_version'] === 2) {
            $config['enabled'] = $this->hasConfiguredAreas($config);
        }

        return ['revision' => $preference->revision] + array_intersect_key($config, $this->defaults());
    }

    private function validate(array $config): array
    {
        // Optional array rules skip empty strings in Laravel. Structural input
        // must still be rejected before any array operation or persistence.
        foreach (['federal_states', 'no_go_areas'] as $field) {
            if (array_key_exists($field, $config) && ! is_array($config[$field])) {
                throw ValidationException::withMessages(['regional_preferences.'.$field => 'Bitte gültige Gebietsangaben verwenden.']);
            }
        }
        foreach ($config['federal_states'] ?? [] as $tier => $codes) {
            if (! is_array($codes)) {
                throw ValidationException::withMessages(['regional_preferences.federal_states.'.$tier => 'Bitte Bundesländer als Auswahl angeben.']);
            }
        }
        $validated = Validator::make(['regional_preferences' => $config], [
            'regional_preferences' => ['required', 'array:schema_version,enabled,base_location,base_point,preferred_radius_km,border_radius_km,emergency_radius_km,federal_states,no_go_areas'],
            'regional_preferences.schema_version' => ['sometimes', 'integer', Rule::in([1, 2])],
            'regional_preferences.enabled' => ['required', 'boolean'],
            'regional_preferences.base_location' => ['nullable', 'string', 'max:200'],
            'regional_preferences.preferred_radius_km' => ['required', 'integer', 'min:1', 'max:1000'],
            'regional_preferences.border_radius_km' => ['required', 'integer', 'gte:regional_preferences.preferred_radius_km', 'max:2000'],
            'regional_preferences.emergency_radius_km' => ['nullable', 'integer', 'gte:regional_preferences.border_radius_km', 'max:2000'],
            'regional_preferences.federal_states' => ['sometimes', 'array:preferred,possible,emergency'],
            'regional_preferences.federal_states.*' => ['array', 'max:16'],
            'regional_preferences.federal_states.*.*' => ['required', 'string', Rule::in(array_keys(GermanFederalStates::options()))],
            'regional_preferences.no_go_areas' => ['present', 'array', 'max:20'],
            'regional_preferences.no_go_areas.*' => ['array:location,radius_km'],
            'regional_preferences.no_go_areas.*.location' => ['required', 'string', 'max:200'],
            'regional_preferences.no_go_areas.*.radius_km' => ['required', 'integer', 'min:1', 'max:1000'],
        ])->validate()['regional_preferences'];
        $validated['enabled'] = (bool) $validated['enabled'];
        $validated['base_location'] = trim($validated['base_location'] ?? '');
        $validated['base_point'] = $config['base_point'] ?? null;
        if ($validated['base_point'] !== null && ! RegionalOriginPoint::valid($validated['base_point'], $validated['base_location'])) {
            throw ValidationException::withMessages(['regional_preferences.base_point' => 'Die bestätigte Adresse ist ungültig oder wurde verändert. Bitte erneut auswählen.']);
        }
        $validated['preferred_radius_km'] = (int) $validated['preferred_radius_km'];
        $validated['border_radius_km'] = (int) $validated['border_radius_km'];
        $validated['emergency_radius_km'] = filled($validated['emergency_radius_km'] ?? null) ? (int) $validated['emergency_radius_km'] : null;
        $validated['federal_states'] = array_replace($this->defaults()['federal_states'], $validated['federal_states'] ?? []);
        $seen = [];
        foreach ($validated['federal_states'] as $tier => $codes) {
            foreach ($codes as $code) {
                if (isset($seen[$code])) {
                    throw ValidationException::withMessages(['regional_preferences.federal_states.'.$tier => 'Jedes Bundesland kann nur einer Zone zugeordnet werden.']);
                }
                $seen[$code] = true;
            }
            sort($codes);
            $validated['federal_states'][$tier] = array_values($codes);
        }
        $validated['schema_version'] = $this->hasExtendedZones($validated) ? 2 : 1;
        if ($validated['schema_version'] === 2) {
            $validated['enabled'] = $this->hasConfiguredAreas($validated);
        }
        if ($validated['schema_version'] === 1 && $validated['enabled'] && $validated['base_location'] === '') {
            throw ValidationException::withMessages(['regional_preferences.base_location' => 'Bitte einen Ausgangsort oder mindestens ein Bundesland auswählen.']);
        }
        if ($validated['emergency_radius_km'] !== null && $validated['base_location'] === '') {
            throw ValidationException::withMessages(['regional_preferences.base_location' => 'Für den Notfallradius wird ein Ausgangsort benötigt.']);
        }
        if ($validated['base_location'] !== '' && $validated['base_point'] === null) {
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

    private function hasConfiguredAreas(array $config): bool
    {
        return $this->hasPositiveAreas($config) || $config['no_go_areas'] !== [];
    }

    private function hasPositiveAreas(array $config): bool
    {
        return trim($config['base_location']) !== '' || array_filter($config['federal_states']) !== [];
    }

    private function hasExtendedZones(array $config): bool
    {
        if ((int) ($config['schema_version'] ?? 1) === 2 || ($config['base_point'] ?? null) !== null || ($config['emergency_radius_km'] ?? null) !== null) {
            return true;
        }

        return collect($config['federal_states'] ?? [])->contains(fn ($codes) => is_array($codes) && $codes !== []);
    }

    /** Provider coordinates and a precise street address never enter audit logs. */
    private function auditConfiguration(array $config): array
    {
        $point = $config['base_point'] ?? null;
        unset($config['base_point']);
        if ($point !== null) {
            $config['base_location'] = '[Adresse]';
            $config['origin_reference'] = array_intersect_key($point, array_flip(['provider', 'type', 'precision']));
            $config['origin_reference']['provider_id_hash'] = hash('sha256', $point['provider_id']);
        }

        return $config;
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
