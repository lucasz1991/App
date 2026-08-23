<?php

namespace App\Services\DeviceManagement;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Strict runtime validation for the versioned RailTime connector responses.
 *
 * Keep this class aligned with
 * .dev/railtime-device-management-research/connector-contract.openapi.yaml.
 * Connector payloads are untrusted even after bearer authentication.
 */
final class ConnectorContractValidator
{
    private const VERSION_PATTERN = '/^[0-9]+(?:\.[0-9]+){0,2}(?:[-+][0-9A-Za-z.-]+)?$/';

    private const IDENTIFIER_PATTERN = '/^[a-z0-9_-]+$/';

    /** @return array<string, mixed> */
    public static function healthResponse(array $payload): array
    {
        self::assertObject(
            $payload,
            ['healthy', 'status', 'contract_version', 'connector_version', 'provider', 'capabilities', 'upstream'],
            ['healthy', 'status', 'contract_version', 'connector_version', 'provider', 'capabilities', 'upstream', 'details'],
            'HealthResponse',
        );

        self::assertBoolean($payload['healthy'], 'HealthResponse.healthy');
        self::assertString($payload['status'], 80, 'HealthResponse.status');
        self::assertPatternString($payload['contract_version'], 32, self::VERSION_PATTERN, 'HealthResponse.contract_version');
        self::assertString($payload['connector_version'], 64, 'HealthResponse.connector_version');
        self::assertPatternString($payload['provider'], 64, '/^[a-z0-9_-]{2,64}$/', 'HealthResponse.provider');

        self::assertCapabilities($payload['capabilities']);
        self::assertUpstream($payload['upstream']);
        if (array_key_exists('details', $payload)) {
            self::assertScalarMap($payload['details'], 'HealthResponse.details');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public static function enrollmentResponse(array $payload): array
    {
        self::assertObject(
            $payload,
            ['status', 'steps'],
            ['status', 'steps', 'enrollment_url', 'expires_at', 'limited_management', 'message'],
            'EnrollmentResponse',
        );

        self::assertString($payload['status'], 80, 'EnrollmentResponse.status');
        self::assertStringList($payload['steps'], 1, 12, 500, null, false, 'EnrollmentResponse.steps');
        self::assertNullableUrl($payload, 'enrollment_url', 2000, 'EnrollmentResponse.enrollment_url');
        self::assertNullableDateTime($payload, 'expires_at', 'EnrollmentResponse.expires_at');
        if (array_key_exists('limited_management', $payload)) {
            self::assertBoolean($payload['limited_management'], 'EnrollmentResponse.limited_management');
        }
        self::assertNullableString($payload, 'message', 500, 'EnrollmentResponse.message');

        return $payload;
    }

    /** @return array<string, mixed> */
    public static function commandResponse(array $payload): array
    {
        self::assertObject(
            $payload,
            ['accepted', 'completed'],
            ['accepted', 'completed', 'provider_job_id', 'message', 'details'],
            'CommandResponse',
        );

        self::assertBoolean($payload['accepted'], 'CommandResponse.accepted');
        self::assertBoolean($payload['completed'], 'CommandResponse.completed');
        self::assertNullableString($payload, 'provider_job_id', 191, 'CommandResponse.provider_job_id');
        self::assertNullableString($payload, 'message', 500, 'CommandResponse.message');
        if (array_key_exists('details', $payload)) {
            self::assertScalarMap($payload['details'], 'CommandResponse.details');
        }

        return $payload;
    }

    /** @param array<string, mixed> $reported
     * @param  array<string, mixed>  $expected
     */
    public static function capabilitiesAreCompatible(array $reported, array $expected): bool
    {
        foreach (['inventory', 'enrollment', 'remote_support', 'unattended_remote_support'] as $key) {
            if (($expected[$key] ?? false) === true && ($reported[$key] ?? false) !== true) {
                return false;
            }
        }

        foreach (['platforms', 'commands', 'readiness_checks'] as $key) {
            $reportedValues = is_array($reported[$key] ?? null) ? $reported[$key] : [];
            $expectedValues = is_array($expected[$key] ?? null) ? $expected[$key] : [];
            if (array_diff($expectedValues, $reportedValues) !== []) {
                return false;
            }
        }

        return true;
    }

    private static function assertCapabilities(mixed $capabilities): void
    {
        if (! is_array($capabilities) || (array_is_list($capabilities) && $capabilities !== []) || count($capabilities) > 64) {
            self::invalid('HealthResponse.capabilities');
        }

        self::assertObject(
            $capabilities,
            ['platforms', 'inventory', 'enrollment', 'remote_support', 'unattended_remote_support', 'commands', 'readiness_checks'],
            array_keys($capabilities),
            'HealthResponse.capabilities',
        );

        self::assertStringList($capabilities['platforms'], 0, 64, 80, self::IDENTIFIER_PATTERN, true, 'HealthResponse.capabilities.platforms');
        self::assertBoolean($capabilities['inventory'], 'HealthResponse.capabilities.inventory');
        self::assertBoolean($capabilities['enrollment'], 'HealthResponse.capabilities.enrollment');
        self::assertBoolean($capabilities['remote_support'], 'HealthResponse.capabilities.remote_support');
        self::assertBoolean($capabilities['unattended_remote_support'], 'HealthResponse.capabilities.unattended_remote_support');
        self::assertStringList($capabilities['commands'], 0, 64, 80, self::IDENTIFIER_PATTERN, true, 'HealthResponse.capabilities.commands');
        self::assertStringList($capabilities['readiness_checks'], 0, 64, 80, self::IDENTIFIER_PATTERN, true, 'HealthResponse.capabilities.readiness_checks');

        $known = ['platforms', 'inventory', 'enrollment', 'remote_support', 'unattended_remote_support', 'commands', 'readiness_checks'];
        foreach (array_diff_key($capabilities, array_flip($known)) as $key => $value) {
            $path = 'HealthResponse.capabilities.'.(string) $key;
            if (is_bool($value)) {
                continue;
            }
            if (is_string($value)) {
                self::assertString($value, 80, $path);

                continue;
            }
            if (is_array($value)) {
                self::assertStringList($value, 0, 64, 80, null, false, $path);

                continue;
            }

            self::invalid($path);
        }
    }

    private static function assertUpstream(mixed $upstream): void
    {
        if (! is_array($upstream)) {
            self::invalid('HealthResponse.upstream');
        }

        self::assertObject(
            $upstream,
            ['reachable', 'authenticated', 'status'],
            ['reachable', 'authenticated', 'status', 'api_version', 'last_success_at'],
            'HealthResponse.upstream',
        );
        self::assertBoolean($upstream['reachable'], 'HealthResponse.upstream.reachable');
        self::assertBoolean($upstream['authenticated'], 'HealthResponse.upstream.authenticated');
        self::assertString($upstream['status'], 80, 'HealthResponse.upstream.status');
        self::assertNullableString($upstream, 'api_version', 64, 'HealthResponse.upstream.api_version');
        self::assertNullableDateTime($upstream, 'last_success_at', 'HealthResponse.upstream.last_success_at');
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $required
     * @param  list<string>  $allowed
     */
    private static function assertObject(array $value, array $required, array $allowed, string $path): void
    {
        if (array_is_list($value) && $value !== []) {
            self::invalid($path);
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                self::invalid($path.'.'.$key);
            }
        }
        if (array_diff(array_keys($value), $allowed) !== []) {
            self::invalid($path);
        }
    }

    private static function assertBoolean(mixed $value, string $path): void
    {
        if (! is_bool($value)) {
            self::invalid($path);
        }
    }

    private static function assertString(mixed $value, int $maximum, string $path): void
    {
        if (! is_string($value) || mb_strlen($value) > $maximum) {
            self::invalid($path);
        }
    }

    private static function assertPatternString(mixed $value, int $maximum, string $pattern, string $path): void
    {
        self::assertString($value, $maximum, $path);
        if (preg_match($pattern, $value) !== 1) {
            self::invalid($path);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertNullableString(array $payload, string $key, int $maximum, string $path): void
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return;
        }
        self::assertString($payload[$key], $maximum, $path);
    }

    /** @param array<string, mixed> $payload */
    private static function assertNullableUrl(array $payload, string $key, int $maximum, string $path): void
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return;
        }
        self::assertString($payload[$key], $maximum, $path);
        if (filter_var($payload[$key], FILTER_VALIDATE_URL) === false) {
            self::invalid($path);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertNullableDateTime(array $payload, string $key, string $path): void
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return;
        }

        $value = $payload[$key];
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            self::invalid($path);
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            self::invalid($path);
        }
    }

    private static function assertScalarMap(mixed $value, string $path): void
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            self::invalid($path);
        }
        foreach ($value as $entry) {
            if (! is_string($entry) && ! is_int($entry) && ! is_float($entry) && ! is_bool($entry) && $entry !== null) {
                self::invalid($path);
            }
        }
    }

    private static function assertStringList(
        mixed $value,
        int $minimum,
        int $maximum,
        int $maximumLength,
        ?string $pattern,
        bool $unique,
        string $path,
    ): void {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            self::invalid($path);
        }

        foreach ($value as $entry) {
            self::assertString($entry, $maximumLength, $path);
            if ($pattern !== null && preg_match($pattern, $entry) !== 1) {
                self::invalid($path);
            }
        }
        if ($unique && count(array_unique($value, SORT_STRING)) !== count($value)) {
            self::invalid($path);
        }
    }

    private static function invalid(string $path): never
    {
        throw new RuntimeException("Die Connector-Antwort verletzt den Vertrag bei {$path}.");
    }
}
