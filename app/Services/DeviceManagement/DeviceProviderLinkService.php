<?php

namespace App\Services\DeviceManagement;

use App\Models\Device;
use App\Models\DeviceProviderLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DeviceProviderLinkService
{
    public function __construct(private readonly DeviceProviderRegistry $providers) {}

    public function link(
        Device $device,
        string $providerKey,
        string $role,
        string $externalDeviceId,
        User $actor,
    ): DeviceProviderLink {
        Gate::forUser($actor)->authorize('devices.manage');

        $data = validator([
            'provider' => strtolower(trim($providerKey)),
            'role' => strtolower(trim($role)),
            'external_device_id' => trim($externalDeviceId),
        ], [
            'provider' => ['required', 'string', 'regex:/\A[a-z0-9_-]{2,64}\z/'],
            'role' => ['required', Rule::in([
                DeviceProviderLink::ROLE_PRIMARY,
                DeviceProviderLink::ROLE_SUPPORT,
            ])],
            'external_device_id' => [
                'required',
                'string',
                'max:191',
                'regex:/\A[A-Za-z0-9._:@$+=\/-]+\z/',
            ],
        ])->validate();

        try {
            $provider = $this->providers->get($data['provider'], fresh: true);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'provider' => 'Dieser Geräteprovider ist nicht im RailTime-Connector-Katalog freigegeben.',
            ]);
        }

        $platform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : (string) $device->platform;
        if (! $provider->supportsPlatform($platform)) {
            throw ValidationException::withMessages([
                'provider' => 'Der Provider unterstützt die Plattform dieses Geräts nicht.',
            ]);
        }

        if ($data['provider'] === 'meshcentral'
            && ! preg_match(
                '~\A(?:node/[A-Za-z0-9_.@-]{0,64}/[A-Za-z0-9_@$+=.-]{20,160}|[A-Za-z0-9_@$+=.]{20,160})\z~',
                $data['external_device_id'],
            )) {
            throw ValidationException::withMessages([
                'external_device_id' => 'Bitte die vollständige native MeshCentral-Node-ID eintragen.',
            ]);
        }

        $link = DB::transaction(function () use ($device, $data, $actor): DeviceProviderLink {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->getKey());
            $existing = DeviceProviderLink::query()
                ->where('device_id', $lockedDevice->getKey())
                ->where('provider', $data['provider'])
                ->lockForUpdate()
                ->first();

            if ($existing
                && filled($existing->external_device_id)
                && ! hash_equals((string) $existing->external_device_id, $data['external_device_id'])) {
                throw ValidationException::withMessages([
                    'external_device_id' => 'Dieser Provider ist bereits mit einer anderen Geräte-ID verknüpft. Die bestehende Bindung wird nicht still ersetzt.',
                ]);
            }

            if ($data['role'] === DeviceProviderLink::ROLE_PRIMARY) {
                $lockedDevice->forceFill([
                    'primary_provider' => $data['provider'],
                    'primary_provider_device_id' => $data['external_device_id'],
                    'updated_by' => $actor->getKey(),
                ])->save();
                $link = $lockedDevice->syncPrimaryProviderLink();
            } else {
                $link = $lockedDevice->ensureProviderLink(
                    $data['provider'],
                    DeviceProviderLink::ROLE_SUPPORT,
                    $data['external_device_id'],
                );
            }

            if (! $link) {
                throw ValidationException::withMessages([
                    'provider' => 'Die Provider-Verknüpfung konnte nicht gespeichert werden.',
                ]);
            }

            return $link;
        });

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => (string) $device->public_id,
                'provider' => $link->provider,
                'role' => $link->role,
                // Do not duplicate provider identifiers into generic audit
                // metadata; the normalized link remains authoritative.
                'link_id' => $link->getKey(),
            ])
            ->log('device_provider_linked');

        return $link->fresh();
    }
}
