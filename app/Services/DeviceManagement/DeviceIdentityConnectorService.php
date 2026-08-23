<?php

namespace App\Services\DeviceManagement;

use RuntimeException;

final class DeviceIdentityConnectorService
{
    private const PROVIDER = 'identity';

    private const ACCOUNT_PROVIDERS = [
        'microsoft_365',
        'google_workspace',
        'apple_managed',
    ];

    public function __construct(
        private readonly DeviceManagementSettings $settings,
        private readonly ConnectorHttpClient $http,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null Null means intentionally not dispatched.
     */
    public function sync(array $payload): ?array
    {
        $this->assertPayload($payload);
        $runtime = $this->settings->providerRuntime(self::PROVIDER, fresh: true);
        if (! ($runtime['enabled'] ?? false)) {
            return null;
        }
        if (! $this->settings->productionMutationsEnabledFor(self::PROVIDER)) {
            return null;
        }

        $path = data_get($runtime, 'paths.identity_sync');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Für den Identity-Connector fehlt der Identity-Sync-Pfad.');
        }

        return ConnectorContractValidator::commandResponse(
            $this->http->request(self::PROVIDER, $runtime, 'POST', $path, $payload),
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertPayload(array $payload): void
    {
        $allowed = [
            'sync_id',
            'correlation_id',
            'device_id',
            'assignment_id',
            'employee_reference',
            'accounts',
            'profile_assignment_ids',
        ];
        if (array_is_list($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []) {
            throw new RuntimeException('Der Identity-Sync besitzt kein gültiges Datenformat.');
        }

        foreach ([
            'sync_id' => 64,
            'correlation_id' => 128,
            'device_id' => 64,
            'assignment_id' => 64,
            'employee_reference' => 128,
        ] as $key => $maximum) {
            if (! is_string($payload[$key]) || $payload[$key] === '' || mb_strlen($payload[$key]) > $maximum) {
                throw new RuntimeException('Der Identity-Sync besitzt keine gültigen Bezeichner.');
            }
        }

        $accounts = $payload['accounts'];
        if (! is_array($accounts) || ! array_is_list($accounts) || $accounts === [] || count($accounts) > 3) {
            throw new RuntimeException('Der Identity-Sync besitzt keine gültige Kontenliste.');
        }
        foreach ($accounts as $account) {
            if (! is_array($account)
                || array_is_list($account)
                || array_keys($account) !== ['provider', 'principal', 'desired_state']
                || ! in_array($account['provider'] ?? null, self::ACCOUNT_PROVIDERS, true)
                || ! is_string($account['principal'] ?? null)
                || trim($account['principal']) === ''
                || mb_strlen($account['principal']) > 254
                || ! in_array($account['desired_state'] ?? null, ['assigned', 'revoked'], true)) {
                throw new RuntimeException('Der Identity-Sync enthält ein ungültiges Konto.');
            }
        }

        $profileIds = $payload['profile_assignment_ids'];
        if (! is_array($profileIds)
            || ! array_is_list($profileIds)
            || $profileIds === []
            || count($profileIds) > 100
            || count(array_unique($profileIds, SORT_REGULAR)) !== count($profileIds)) {
            throw new RuntimeException('Der Identity-Sync besitzt keine gültigen Profilzuordnungen.');
        }
        foreach ($profileIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('Der Identity-Sync besitzt eine ungültige Profilzuordnung.');
            }
        }
    }
}
