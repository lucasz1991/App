<?php

namespace App\Services\DeviceManagement;

use App\Models\Device;
use Illuminate\Validation\ValidationException;

final class DeviceEnrollmentModeCatalog
{
    /**
     * The provider/platform/ownership matrix is deliberately code-owned. A
     * mutable provider setting may disable an option, but cannot make an
     * unsafe enrollment mode valid for another device class.
     *
     * @var array<string, array<string, array<string, list<string>>>>
     */
    private const MATRIX = [
        'openuem' => [
            'windows' => ['corporate' => ['agent'], 'byod' => ['agent']],
            'macos' => ['corporate' => ['agent'], 'byod' => ['agent']],
            'linux' => ['corporate' => ['agent'], 'byod' => ['agent']],
        ],
        'meshcentral' => [
            'windows' => ['corporate' => ['agent'], 'byod' => ['agent']],
            'macos' => ['corporate' => ['agent'], 'byod' => ['agent']],
            'linux' => ['corporate' => ['agent'], 'byod' => ['agent']],
        ],
        'headwind' => [
            'android' => [
                'corporate' => ['agent', 'fully_managed'],
                'byod' => ['agent'],
            ],
        ],
        'nanomdm' => [
            'macos' => ['corporate' => ['profile', 'ade'], 'byod' => ['profile']],
            'ios' => ['corporate' => ['profile', 'ade'], 'byod' => ['profile']],
            'ipados' => ['corporate' => ['profile', 'ade'], 'byod' => ['profile']],
        ],
        // Deterministic test provider: it emulates only combinations that a
        // corresponding production connector is allowed to represent.
        'simulation' => [
            'windows' => ['corporate' => ['agent'], 'byod' => ['agent']],
            'macos' => ['corporate' => ['agent', 'profile', 'ade'], 'byod' => ['agent', 'profile']],
            'linux' => ['corporate' => ['agent'], 'byod' => ['agent']],
            'android' => [
                'corporate' => ['agent', 'work_profile', 'fully_managed'],
                'byod' => ['agent', 'work_profile'],
            ],
            'ios' => ['corporate' => ['profile', 'ade'], 'byod' => ['profile']],
            'ipados' => ['corporate' => ['profile', 'ade'], 'byod' => ['profile']],
        ],
    ];

    /** @var array<string, array{label: string, description: string, requires_reset: bool}> */
    private const MODES = [
        'agent' => [
            'label' => 'Agent installieren',
            'description' => 'Der Mitarbeiter installiert den freigegebenen Verwaltungsagenten auf dem bestehenden Gerät.',
            'requires_reset' => false,
        ],
        'work_profile' => [
            'label' => 'Android-Arbeitsprofil',
            'description' => 'Geschäftliche Apps und Daten bleiben in einem getrennten, verwalteten Arbeitsprofil.',
            'requires_reset' => false,
        ],
        'profile' => [
            'label' => 'Apple-MDM-Profil',
            'description' => 'Ein bestehendes Apple-Gerät nimmt ein entfernbares MDM-Profil an; Supervision entsteht dadurch nicht.',
            'requires_reset' => false,
        ],
        'ade' => [
            'label' => 'Apple ADE / Supervision',
            'description' => 'Vollständige Firmenbereitstellung über Apple Business Manager bei Neueinrichtung oder geplantem Reset.',
            'requires_reset' => true,
        ],
        'fully_managed' => [
            'label' => 'Android vollständig verwaltet',
            'description' => 'Corporate Device Owner bei Neueinrichtung oder geplantem Werksreset.',
            'requires_reset' => true,
        ],
    ];

    /** @return list<array{value: string, label: string, description: string, requires_reset: bool, limited_management: bool}> */
    public function optionsFor(Device $device, string $provider): array
    {
        $provider = strtolower(trim($provider));
        $platform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : strtolower((string) $device->platform);
        $ownership = strtolower((string) $device->ownership);

        return array_values(array_map(
            fn (string $mode): array => [
                'value' => $mode,
                ...self::MODES[$mode],
                'limited_management' => $this->isLimited($device, $provider, $mode),
            ],
            self::MATRIX[$provider][$platform][$ownership] ?? [],
        ));
    }

    public function supports(Device $device, string $provider, string $mode): bool
    {
        return collect($this->optionsFor($device, $provider))
            ->contains(fn (array $option): bool => $option['value'] === strtolower(trim($mode)));
    }

    public function assertSupported(Device $device, string $provider, string $mode): void
    {
        if (! $this->supports($device, $provider, $mode)) {
            throw ValidationException::withMessages([
                'mode' => 'Diese Registrierungsart ist für Plattform, Eigentum und Provider des Geräts nicht zulässig.',
            ]);
        }
    }

    public function defaultFor(Device $device, string $provider): ?string
    {
        return $this->optionsFor($device, $provider)[0]['value'] ?? null;
    }

    public function isLimited(Device $device, string $provider, string $mode): bool
    {
        $platform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : strtolower((string) $device->platform);
        $mode = strtolower(trim($mode));

        return match (true) {
            $platform === 'android' && $mode !== 'fully_managed' => true,
            in_array($platform, ['ios', 'ipados'], true) && $mode !== 'ade' => true,
            $platform === 'macos' && $mode === 'profile' => true,
            default => false,
        };
    }
}
