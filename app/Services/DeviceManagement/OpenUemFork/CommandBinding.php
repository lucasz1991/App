<?php

namespace App\Services\DeviceManagement\OpenUemFork;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceProviderLink;
use App\Services\DeviceManagement\DeviceManagementSettings;
use RuntimeException;

final class CommandBinding
{
    public function __construct(private readonly DeviceManagementSettings $settings) {}

    /**
     * Called under the DeviceCommandService device/assignment transaction. The
     * browser chooses only a listed profile ID; device IDs are server-derived.
     *
     * @param  array<string, mixed>  $input
     * @return array{profile_id: int, agent_id: string, provider_link_id: int}
     */
    public function requestPayload(Device $device, DeviceProviderLink $link, array $input): array
    {
        if (DeviceCommand::query()->where('device_id', $device->getKey())->where('provider', 'openuem')
            ->where('type', DeviceCommandType::ApplyManagedProfile->value)
            ->whereIn('status', [DeviceCommandStatus::Queued->value, DeviceCommandStatus::Dispatched->value, DeviceCommandStatus::Running->value])
            ->exists()) {
            throw new RuntimeException('Ein nativer OpenUEM-Auftrag ist noch offen oder ungeklärt. Vor einer erneuten Ausführung muss er abgeglichen werden.');
        }
        if (array_keys($input) !== ['profile_id'] || ! is_int($input['profile_id']) || $input['profile_id'] < 1
            || $device->platform !== DevicePlatform::Windows || $link->provider !== 'openuem'
            || $link->status !== DeviceProviderLink::STATUS_ACTIVE
            || (int) $link->device_id !== (int) $device->getKey()) {
            throw new RuntimeException('Nur ein freigegebenes natives Windows-Profil darf ausgewählt werden.');
        }
        $agentId = (string) $link->external_device_id;
        new RunReference('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', $agentId, $input['profile_id']);
        $this->assertProfileAllowed($agentId, $input['profile_id']);

        return ['profile_id' => $input['profile_id'], 'agent_id' => $agentId, 'provider_link_id' => (int) $link->getKey()];
    }

    /** Read the authoritative models, never a stale UI/queue relation. */
    public function reference(DeviceCommand $command, Device $device, bool $requireAllowedProfile = true): RunReference
    {
        $current = DeviceCommand::query()->find($command->getKey());
        $currentDevice = Device::withTrashed()->find($device->getKey());
        if (! $current || ! $currentDevice || $currentDevice->trashed()
            || (int) $current->device_id !== (int) $currentDevice->getKey()
            || $current->provider !== 'openuem' || $current->type !== DeviceCommandType::ApplyManagedProfile
            || ! in_array($current->status, [DeviceCommandStatus::Queued, DeviceCommandStatus::Dispatched, DeviceCommandStatus::Running], true)
            || $currentDevice->platform !== DevicePlatform::Windows
            || $current->public_id !== $command->public_id || $current->correlation_id !== $command->correlation_id) {
            throw new RuntimeException('Die native OpenUEM-Auftragsbindung ist nicht mehr gültig.');
        }
        $assignments = DeviceAssignment::query()->where('device_id', $currentDevice->getKey())->active()->get();
        if ($assignments->count() > 1
            || ($current->device_assignment_id === null
                ? $assignments->isNotEmpty()
                : $assignments->count() !== 1 || (int) $assignments->first()->getKey() !== (int) $current->device_assignment_id)) {
            throw new RuntimeException('Die Mitarbeiterzuweisung des nativen OpenUEM-Auftrags hat sich geändert.');
        }
        $payload = $current->payload;
        $link = $currentDevice->providerLinkFor('openuem');
        if (! is_array($payload) || array_keys($payload) !== ['profile_id', 'agent_id', 'provider_link_id']
            || ! is_int($payload['profile_id']) || ! is_string($payload['agent_id']) || ! is_int($payload['provider_link_id'])
            || ! $link || $link->status !== DeviceProviderLink::STATUS_ACTIVE
            || (int) $link->getKey() !== $payload['provider_link_id']
            || $link->external_device_id !== $payload['agent_id']
            || DeviceProviderLink::query()->where('provider', 'openuem')->where('external_device_id', $payload['agent_id'])->count() !== 1) {
            throw new RuntimeException('Die native OpenUEM-Geräteverknüpfung ist nicht mehr eindeutig oder gültig.');
        }
        if ($requireAllowedProfile) {
            $this->assertProfileAllowed($payload['agent_id'], $payload['profile_id']);
        }

        return new RunReference((string) $current->public_id, (string) $current->correlation_id, $payload['agent_id'], $payload['profile_id']);
    }

    private function assertProfileAllowed(string $agentId, int $profileId): void
    {
        $runtime = $this->settings->providerRuntime('openuem', fresh: true);
        $profiles = $runtime['native_profiles'] ?? null;
        if (($runtime['adapter'] ?? null) !== 'native_fork_v1'
            || ! is_array($profiles) || ! array_is_list($profiles) || count($profiles) > 128) {
            throw new RuntimeException('Es ist keine gültige native OpenUEM-Profilfreigabe gespeichert.');
        }
        foreach ($profiles as $profile) {
            if (is_array($profile) && ($profile['agent_id'] ?? null) === $agentId && ($profile['profile_id'] ?? null) === $profileId) {
                return;
            }
        }

        throw new RuntimeException('Dieses OpenUEM-Profil ist für das gebundene Gerät nicht freigegeben.');
    }
}
