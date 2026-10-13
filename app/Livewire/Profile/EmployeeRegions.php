<?php

namespace App\Livewire\Profile;

use App\Models\Shift;
use App\Services\Operations\EmployeeOperationsService;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Support\Operations\GermanFederalStates;
use App\Support\Operations\GermanyMap;
use App\Support\Operations\TimelineLocationPreview;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeRegions extends Component
{
    #[Locked]
    public int $userId;

    #[Locked]
    public int $revision = 0;

    public array $preferences = [];

    public string $activeZone = 'preferred';

    #[Locked]
    public bool $dirty = false;

    #[Locked]
    public ?string $notice = null;

    public const ZONES = [
        'preferred' => ['label' => 'Bevorzugt', 'score' => '+15', 'radius' => 'preferred_radius_km'],
        'possible' => ['label' => 'Möglich', 'score' => '+5', 'radius' => 'border_radius_km'],
        'emergency' => ['label' => 'Notfall', 'score' => '−10', 'radius' => 'emergency_radius_km'],
    ];

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->reload();
    }

    public function hydrate(): void
    {
        $this->authorizeProfile();
    }

    public function reload(): void
    {
        [, $employee] = $this->authorizeProfile();
        $config = app(StaffRegionalPreferenceService::class)->get($employee);
        $this->revision = (int) Arr::pull($config, 'revision');
        $this->preferences = $config + [
            'emergency_radius_km' => null,
            'federal_states' => ['preferred' => [], 'possible' => [], 'emergency' => []],
        ];
        $this->dirty = false;
        $this->notice = null;
        $this->resetValidation();
    }

    public function updatedPreferences(): void
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        $this->dirty = true;
        $this->notice = null;
        $this->resetValidation();
    }

    public function selectZone(string $zone): void
    {
        $this->authorizeProfile();
        abort_unless(isset(self::ZONES[$zone]), 422);
        $this->activeZone = $zone;
    }

    public function toggleState(string $code): void
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        abort_unless(isset(GermanFederalStates::options()[$code]) && isset(self::ZONES[$this->activeZone]), 422);
        $selected = in_array($code, $this->preferences['federal_states'][$this->activeZone] ?? [], true);
        foreach (array_keys(self::ZONES) as $zone) {
            $this->preferences['federal_states'][$zone] = array_values(array_diff($this->preferences['federal_states'][$zone] ?? [], [$code]));
        }
        if (! $selected) {
            $this->preferences['federal_states'][$this->activeZone][] = $code;
        }
        $this->updatedPreferences();
    }

    /** Explicit placement remains stable when a client retries the same choice. */
    public function assignState(string $code, ?string $zone): void
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        abort_unless(array_key_exists($code, GermanFederalStates::options())
            && ($zone === null || array_key_exists($zone, self::ZONES)), 422);

        foreach (array_keys(self::ZONES) as $targetZone) {
            $codes = array_values(array_filter(
                $this->preferences['federal_states'][$targetZone],
                fn (string $value): bool => $value !== $code,
            ));
            if ($targetZone === $zone) {
                $codes[] = $code;
            }
            sort($codes);
            $this->preferences['federal_states'][$targetZone] = $codes;
        }
        $this->updatedPreferences();
    }

    /** Clearing is a draft edit; the existing save action performs CAS and audit. */
    public function clearPreferences(): void
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        $this->preferences = [
            'schema_version' => 2,
            'enabled' => false,
            'base_location' => '',
            'preferred_radius_km' => 50,
            'border_radius_km' => 100,
            'emergency_radius_km' => null,
            'federal_states' => ['preferred' => [], 'possible' => [], 'emergency' => []],
            'no_go_areas' => [],
        ];
        $this->activeZone = 'preferred';
        $this->updatedPreferences();
        $this->notice = 'Einstellungen zum Löschen vorgemerkt. Bitte speichern.';
    }

    public function addExclusion(): void
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        if (count($this->preferences['no_go_areas'] ?? []) < 20) {
            $this->preferences['no_go_areas'][] = ['location' => '', 'radius_km' => 25];
            $this->updatedPreferences();
        }
    }

    public function removeExclusion(int $index): void
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        abort_unless(array_key_exists($index, $this->preferences['no_go_areas'] ?? []), 422);
        unset($this->preferences['no_go_areas'][$index]);
        $this->preferences['no_go_areas'] = array_values($this->preferences['no_go_areas']);
        $this->updatedPreferences();
    }

    public function save(): void
    {
        [$actor, $employee] = $this->authorizeProfile();
        try {
            $this->preferences['schema_version'] = 2;
            app(StaffRegionalPreferenceService::class)->save($employee, $actor, $this->preferences, $this->revision);
            $this->reload();
            $this->notice = 'Einsatzgebiete gespeichert.';
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(str_replace('regional_preferences', 'preferences', $key), $messages[0]);
            }
        }
    }

    public function render()
    {
        $this->authorizeProfile();
        $this->assertDraftShape();
        $states = GermanFederalStates::options();
        $selected = [];
        foreach (array_keys(self::ZONES) as $zone) {
            foreach ($this->preferences['federal_states'][$zone] ?? [] as $code) {
                if (isset($states[$code])) {
                    $selected[$code] = $zone;
                }
            }
        }
        $base = TimelineLocationPreview::forRegionalAssessment(new Shift(['location_name' => (string) ($this->preferences['base_location'] ?? '')]));
        $rings = [];
        $point = null;
        if (($base['state'] ?? null) === 'located') {
            $point = GermanyMap::projectCoordinates($base['latitude'], $base['longitude']);
            foreach (array_reverse(self::ZONES, true) as $zone => $definition) {
                $radius = $this->preferences[$definition['radius']] ?? null;
                if (is_numeric($radius) && (float) $radius > 0 && (float) $radius <= 2000) {
                    $rings[$zone] = GermanyMap::radiusPath($base['latitude'], $base['longitude'], (float) $radius);
                }
            }
        }

        return view('livewire.profile.employee-regions', [
            'states' => $states, 'statePaths' => GermanFederalStates::paths(), 'selectedStates' => $selected,
            'zones' => self::ZONES, 'base' => $base, 'basePoint' => $point, 'rings' => $rings,
            'ready' => app(StaffRegionalPreferenceService::class)->ready(),
        ]);
    }

    private function authorizeProfile(): array
    {
        return app(EmployeeOperationsService::class)->authorizeProfileAccess($this->userId);
    }

    /** Nested public Livewire state must remain safe even before save validation. */
    private function assertDraftShape(): void
    {
        abort_unless(is_array($this->preferences['federal_states'] ?? null) && is_array($this->preferences['no_go_areas'] ?? null), 422);
        foreach (array_keys(self::ZONES) as $zone) {
            abort_unless(is_array($this->preferences['federal_states'][$zone] ?? null), 422);
        }
        foreach ($this->preferences['no_go_areas'] as $area) {
            abort_unless(is_array($area), 422);
        }
        $validator = Validator::make($this->preferences, [
            'base_location' => ['nullable', 'string', 'max:200'],
            'federal_states' => ['required', 'array:preferred,possible,emergency'],
            'federal_states.*' => ['array', 'max:16'],
            'federal_states.*.*' => ['string', 'max:8'],
            'no_go_areas' => ['present', 'array', 'max:20'],
            'no_go_areas.*' => ['array:location,radius_km'],
            'no_go_areas.*.location' => ['nullable', 'string', 'max:200'],
        ]);
        abort_unless($validator->passes(), 422, 'Ungültige Gebietsangaben.');
        foreach (['preferred_radius_km', 'border_radius_km', 'emergency_radius_km'] as $key) {
            abort_unless(is_scalar($this->preferences[$key] ?? null) || ($this->preferences[$key] ?? null) === null, 422);
        }
    }
}
