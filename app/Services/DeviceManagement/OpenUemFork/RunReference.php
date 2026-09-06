<?php

namespace App\Services\DeviceManagement\OpenUemFork;

use RuntimeException;

/** Immutable reference only: snapshots and scripts are never accepted from Laravel. */
final readonly class RunReference
{
    public function __construct(
        public string $commandId,
        public string $correlationId,
        public string $agentId,
        public int $profileId,
    ) {
        if (! self::isUuid($commandId) || ! self::isUuid($correlationId)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $agentId)
            || $profileId < 1) {
            throw new RuntimeException('Die OpenUEM-Auftragsreferenz ist ungültig.');
        }
    }

    /** @return array{command_id: string, correlation_id: string, agent_id: string, profile_id: int} */
    public function payload(): array
    {
        return [
            'command_id' => $this->commandId,
            'correlation_id' => $this->correlationId,
            'agent_id' => $this->agentId,
            'profile_id' => $this->profileId,
        ];
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) === 1;
    }

    public static function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }
}
