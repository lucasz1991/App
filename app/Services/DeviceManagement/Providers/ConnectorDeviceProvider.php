<?php

namespace App\Services\DeviceManagement\Providers;

use App\Enums\DeviceCommandType;
use App\Enums\DeviceEnrollmentStatus;
use App\Models\Device;
use App\Models\DeviceArtifact;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Services\DeviceManagement\ConnectorHttpClient;
use App\Services\DeviceManagement\Contracts\DeviceProviderInterface;
use App\Services\DeviceManagement\Data\CommandResult;
use App\Services\DeviceManagement\Data\EnrollmentResult;
use App\Services\DeviceManagement\Data\HealthResult;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\Support\SafeProviderData;
use RuntimeException;

final class ConnectorDeviceProvider implements DeviceProviderInterface
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly string $providerKey,
        private readonly array $configuration,
        private readonly ConnectorHttpClient $http,
        private readonly DeviceManagementSettings $settings,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return (string) ($this->configuration['label'] ?? $this->providerKey);
    }

    public function enabled(): bool
    {
        return (bool) ($this->configuration['enabled'] ?? false);
    }

    public function capabilities(): array
    {
        $capabilities = $this->configuration['capabilities'] ?? [];

        return is_array($capabilities) ? $capabilities : [];
    }

    public function supportsPlatform(string $platform): bool
    {
        return in_array(strtolower($platform), $this->capabilities()['platforms'] ?? [], true);
    }

    public function supportsCommand(DeviceCommandType $type): bool
    {
        return in_array($type->value, $this->capabilities()['commands'] ?? [], true);
    }

    public function health(): HealthResult
    {
        $this->assertEnabled();
        $response = $this->http->request(
            $this->providerKey,
            $this->configuration,
            'GET',
            $this->path('health'),
        );

        $healthy = filter_var($response['healthy'] ?? false, FILTER_VALIDATE_BOOL);
        $status = mb_substr(trim((string) ($response['status'] ?? ($healthy ? 'healthy' : 'unhealthy'))), 0, 80);
        $details = SafeProviderData::summary(is_array($response['details'] ?? null) ? $response['details'] : $response);

        return new HealthResult($healthy, $status, $details);
    }

    public function enrollment(DeviceEnrollment $enrollment, Device $device): EnrollmentResult
    {
        $this->assertEnabled();
        if ($enrollment->status !== DeviceEnrollmentStatus::Claimed || ! $enrollment->claimed_at) {
            throw new RuntimeException('Enrollment-Anweisungen werden erst nach einem atomischen Claim ausgegeben.');
        }
        if (! ($this->capabilities()['enrollment'] ?? false)) {
            throw new RuntimeException("Der Provider '{$this->providerKey}' unterstützt kein Enrollment.");
        }

        $platform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : (string) $device->platform;
        if (! $this->supportsPlatform($platform)) {
            throw new RuntimeException("Der Provider '{$this->providerKey}' unterstützt die Geräteplattform nicht.");
        }

        $response = $this->http->request(
            $this->providerKey,
            $this->configuration,
            'POST',
            $this->path('enrollment'),
            [
                'enrollment_id' => (string) $enrollment->public_id,
                'device_id' => (string) $device->public_id,
                'provider_device_id' => $this->limitedString($device->primary_provider_device_id, 191),
                'platform' => $platform,
                'mode' => $this->limitedString($enrollment->mode, 40),
                'expires_at' => $enrollment->expires_at?->toIso8601String(),
            ],
        );

        $steps = [];
        foreach (is_array($response['steps'] ?? null) ? $response['steps'] : [] as $step) {
            if (is_string($step) && trim($step) !== '') {
                $steps[] = mb_substr(trim($step), 0, 500);
            }
            if (count($steps) >= 12) {
                break;
            }
        }

        if ($steps === []) {
            throw new RuntimeException("Der Connector '{$this->providerKey}' lieferte keine Enrollment-Schritte.");
        }

        return new EnrollmentResult(
            status: mb_substr(trim((string) ($response['status'] ?? 'ready')), 0, 80),
            steps: $steps,
            enrollmentUrl: $this->safeExternalUrl($response['enrollment_url'] ?? null),
            expiresAt: $this->limitedString($response['expires_at'] ?? null, 40),
            limitedManagement: filter_var($response['limited_management'] ?? false, FILTER_VALIDATE_BOOL),
            message: $this->limitedString($response['message'] ?? null, 500),
        );
    }

    public function dispatch(DeviceCommand $command, Device $device): CommandResult
    {
        $this->assertEnabled();
        if (! $this->settings->productionCommandsEnabled(fresh: true)) {
            throw new RuntimeException('Externe Gerätebefehle sind durch den globalen Kill-Switch deaktiviert.');
        }
        if (! $this->supportsCommand($command->type)) {
            throw new RuntimeException("Der Provider '{$this->providerKey}' unterstützt diesen Befehl nicht.");
        }

        $payload = [
            'command_id' => (string) $command->public_id,
            'correlation_id' => (string) $command->correlation_id,
            'device_id' => (string) $device->public_id,
            'provider_device_id' => $this->limitedString($device->primary_provider_device_id, 191),
            'command' => $command->type->value,
            // The encrypted payload is intentional command input. The
            // service layer has already rejected secret-like fields.
            'options' => is_array($command->payload) ? $command->payload : [],
        ];

        if (is_array($command->payload) && filled($command->payload['artifact_public_id'] ?? null)) {
            $artifact = DeviceArtifact::query()
                ->where('public_id', $command->payload['artifact_public_id'])
                ->where('device_id', $device->id)
                ->whereNotNull('approved_at')
                ->first();
            if (! $artifact
                || ! hash_equals((string) $artifact->sha256, (string) ($command->payload['artifact_sha256'] ?? ''))) {
                throw new RuntimeException('Das freigegebene Geräteartefakt ist nicht mehr gültig.');
            }

            $payload['artifact'] = [
                'download_url' => route('api.device-management.artifacts.show', [
                    'provider' => $this->providerKey,
                    'artifact' => $artifact->public_id,
                ]),
                'sha256' => $artifact->sha256,
                'size_bytes' => $artifact->size_bytes,
                'name' => basename((string) ($artifact->original_name ?: $artifact->name)),
                // Connector signs: timestamp + ".GET." + URL path using its
                // provider webhook secret. No durable URL token is created.
                'authentication' => 'railtime-hmac-v1',
            ];
        }

        $response = $this->http->request(
            $this->providerKey,
            $this->configuration,
            'POST',
            $this->path('command'),
            $payload,
        );

        $accepted = filter_var($response['accepted'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $accepted) {
            throw new RuntimeException("Der Connector '{$this->providerKey}' hat den Befehl abgelehnt.");
        }

        return new CommandResult(
            accepted: true,
            completed: filter_var($response['completed'] ?? false, FILTER_VALIDATE_BOOL),
            providerJobId: $this->limitedString($response['provider_job_id'] ?? null, 191),
            message: $this->limitedString($response['message'] ?? null, 500),
            details: SafeProviderData::summary(is_array($response['details'] ?? null) ? $response['details'] : []),
        );
    }

    public function remoteSupportUrl(Device $device): ?string
    {
        $this->assertEnabled();
        if (! ($this->capabilities()['remote_support'] ?? false)) {
            return null;
        }

        $template = trim((string) ($this->configuration['remote_url_template'] ?? ''));
        if ($template === '') {
            return null;
        }

        $url = strtr($template, [
            '{device_id}' => rawurlencode((string) $device->public_id),
            '{provider_device_id}' => rawurlencode((string) ($device->primary_provider_device_id ?? '')),
        ]);

        return $this->safeExternalUrl($url);
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException("Der Provider '{$this->providerKey}' ist deaktiviert.");
        }
    }

    private function path(string $name): string
    {
        $path = $this->configuration['paths'][$name] ?? null;
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException("Für '{$this->providerKey}' fehlt der Connector-Pfad '{$name}'.");
        }

        return $path;
    }

    private function limitedString(mixed $value, int $maximum): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $maximum);
    }

    private function safeExternalUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException("Der Connector '{$this->providerKey}' lieferte eine ungültige URL.");
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https') {
            throw new RuntimeException("Der Connector '{$this->providerKey}' lieferte eine unsichere URL.");
        }

        if (isset($parts['query']) && preg_match('/(?:token|secret|signature|password|key)=/i', (string) $parts['query'])) {
            throw new RuntimeException("Die URL des Connectors '{$this->providerKey}' darf keine Zugangsdaten enthalten.");
        }

        return mb_substr($url, 0, 2000);
    }
}
