<?php

namespace App\Services\DeviceManagement;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class DeviceProviderDiagnosticsService
{
    private const HEALTH_PATH = '/v1/health';

    private const CONTRACT_MAJOR = 1;

    private const MAXIMUM_RESPONSE_BYTES = 65536;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const TIMEOUT_SECONDS = 5;

    /** @var list<string> */
    private const BOOLEAN_CAPABILITIES = [
        'inventory',
        'enrollment',
        'remote_support',
        'unattended_remote_support',
    ];

    /** @var list<string> */
    private const LIST_CAPABILITIES = [
        'platforms',
        'commands',
        'readiness_checks',
    ];

    public function __construct(
        private readonly DeviceManagementSettings $settings,
        private readonly ConnectorNetworkGuard $networkGuard,
    ) {}

    /**
     * Run a read-only probe against a configured RailTime connector adapter.
     *
     * @return array{
     *     config: array{provider: string, mode: string|null, enabled: bool, configured: bool, token_configured: bool},
     *     configured: bool,
     *     url: string|null,
     *     reachable: bool,
     *     authenticated: bool,
     *     contract: array{expected: string, reported: string|null, connector: string|null, provider: string|null, compatible: bool, upstream_reachable: bool, upstream_authenticated: bool},
     *     contract_valid: bool,
     *     healthy: bool,
     *     status: string,
     *     latency: int|null,
     *     latency_ms: int|null,
     *     checked_at: string,
     *     details: array{provider: string, mode: string|null, enabled: bool, token_configured: bool, expected_contract: string, reported_contract: string|null, connector_version: string|null, upstream_reachable: bool, upstream_authenticated: bool}
     * }
     */
    public function probe(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $result = $this->emptyResult($provider);

        if (! preg_match('/^[a-z0-9_-]{2,64}$/', $provider)) {
            return $this->withStatus($result, 'invalid_provider');
        }

        try {
            $runtime = $this->settings->providerRuntime($provider);
        } catch (Throwable) {
            return $this->withStatus($result, 'invalid_configuration');
        }

        if (trim((string) ($runtime['configuration_error'] ?? '')) !== '') {
            return $this->withStatus($result, 'invalid_configuration');
        }

        $mode = strtolower(trim((string) ($runtime['mode'] ?? '')));
        $token = trim((string) ($runtime['token'] ?? ''));
        $result['config']['mode'] = in_array($mode, ['port', 'subdomain'], true) ? $mode : null;
        $result['config']['enabled'] = (bool) ($runtime['enabled'] ?? false);
        $result['config']['token_configured'] = $token !== '';

        $baseUrl = trim((string) ($runtime['effective_url'] ?? $runtime['connector_base_url'] ?? ''));
        $validatedBaseUrl = $this->validatedBaseUrl($baseUrl, $mode);
        if ($validatedBaseUrl === null) {
            return $this->withStatus($result, 'invalid_configuration');
        }

        $result['url'] = $validatedBaseUrl.self::HEALTH_PATH;
        $result['config']['configured'] = $token !== '';

        if ($token === '') {
            return $this->withStatus($result, 'missing_credentials');
        }

        try {
            $networkOptions = $this->networkGuard->requestOptions($validatedBaseUrl, $mode);
        } catch (Throwable) {
            return $this->withStatus($result, 'invalid_configuration');
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions(array_replace_recursive([
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'stream' => true,
                ], $networkOptions))
                ->get($result['url']);
        } catch (ConnectionException) {
            $result['latency'] = $this->elapsedMilliseconds($startedAt);

            return $this->withStatus($result, 'unreachable');
        } catch (Throwable) {
            $result['latency'] = $this->elapsedMilliseconds($startedAt);

            return $this->withStatus($result, 'probe_failed');
        }

        $result['latency'] = $this->elapsedMilliseconds($startedAt);
        $result['reachable'] = true;

        if ($response->redirect()) {
            return $this->withStatus($result, 'redirect_rejected');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->withStatus($result, 'unauthorized');
        }

        if (! $response->successful()) {
            return $this->withStatus($result, 'http_error');
        }

        $result['authenticated'] = true;
        $body = $this->limitedResponseBody($response->toPsrResponse()->getBody());
        if ($body === null) {
            return $this->withStatus($result, 'response_too_large');
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->withStatus($result, 'invalid_response');
        }

        if (! is_array($payload) || array_is_list($payload)) {
            return $this->withStatus($result, 'invalid_response');
        }

        if (! is_bool($payload['healthy'] ?? null)
            || ! is_string($payload['status'] ?? null)
            || trim($payload['status']) === ''
            || mb_strlen($payload['status']) > 80
            || ! is_array($payload['capabilities'] ?? null)
            || array_is_list($payload['capabilities'])
            || ! is_array($payload['upstream'] ?? null)
            || array_is_list($payload['upstream'])) {
            return $this->withStatus($result, 'contract_invalid');
        }

        $reportedVersion = $this->validatedVersion($payload['contract_version'] ?? null);
        $connectorVersion = $this->validatedVersion($payload['connector_version'] ?? null);
        $reportedProvider = null;
        if (is_string($payload['provider'] ?? null)
            && preg_match('/^[a-z0-9_-]{2,64}$/', $payload['provider']) === 1) {
            $reportedProvider = $payload['provider'];
        }
        $upstream = $payload['upstream'];
        $expectedCapabilities = is_array($runtime['capabilities'] ?? null)
            ? $runtime['capabilities']
            : [];

        $result['contract']['reported'] = $reportedVersion;
        $result['contract']['connector'] = $connectorVersion;
        $result['contract']['provider'] = $reportedProvider;

        if ($reportedProvider !== $provider) {
            return $this->withStatus($result, 'provider_mismatch');
        }

        if ($reportedVersion === null
            || (int) explode('.', $reportedVersion, 2)[0] !== self::CONTRACT_MAJOR
            || $connectorVersion === null
            || ! is_bool($upstream['reachable'] ?? null)
            || ! is_bool($upstream['authenticated'] ?? null)
            || ! is_string($upstream['status'] ?? null)
            || trim($upstream['status']) === ''
            || mb_strlen($upstream['status']) > 80
            || ! $this->capabilitiesAreCompatible($payload['capabilities'], $expectedCapabilities)) {
            return $this->withStatus($result, 'contract_invalid');
        }

        $result['contract']['compatible'] = true;
        $result['contract']['upstream_reachable'] = $upstream['reachable'];
        $result['contract']['upstream_authenticated'] = $upstream['authenticated'];

        if ($payload['healthy'] !== true
            || $upstream['reachable'] !== true
            || $upstream['authenticated'] !== true) {
            return $this->withStatus($result, 'unhealthy');
        }

        $result['healthy'] = true;

        return $this->withStatus($result, 'healthy');
    }

    /**
     * @return array{
     *     config: array{provider: string, mode: null, enabled: false, configured: false, token_configured: false},
     *     configured: false,
     *     url: null,
     *     reachable: false,
     *     authenticated: false,
     *     contract: array{expected: string, reported: null, connector: null, provider: null, compatible: false, upstream_reachable: false, upstream_authenticated: false},
     *     contract_valid: false,
     *     healthy: false,
     *     status: string,
     *     latency: null,
     *     latency_ms: null,
     *     checked_at: string,
     *     details: array{provider: string, mode: null, enabled: false, token_configured: false, expected_contract: string, reported_contract: null, connector_version: null, upstream_reachable: false, upstream_authenticated: false}
     * }
     */
    private function emptyResult(string $provider): array
    {
        return [
            'config' => [
                'provider' => preg_match('/^[a-z0-9_-]{2,64}$/', $provider) ? $provider : 'unknown',
                'mode' => null,
                'enabled' => false,
                'configured' => false,
                'token_configured' => false,
            ],
            'configured' => false,
            'url' => null,
            'reachable' => false,
            'authenticated' => false,
            'contract' => [
                'expected' => self::CONTRACT_MAJOR.'.x',
                'reported' => null,
                'connector' => null,
                'provider' => null,
                'compatible' => false,
                'upstream_reachable' => false,
                'upstream_authenticated' => false,
            ],
            'contract_valid' => false,
            'healthy' => false,
            'status' => 'pending',
            'latency' => null,
            'latency_ms' => null,
            'checked_at' => now()->toIso8601String(),
            'details' => [
                'provider' => preg_match('/^[a-z0-9_-]{2,64}$/', $provider) ? $provider : 'unknown',
                'mode' => null,
                'enabled' => false,
                'token_configured' => false,
                'expected_contract' => self::CONTRACT_MAJOR.'.x',
                'reported_contract' => null,
                'connector_version' => null,
                'upstream_reachable' => false,
                'upstream_authenticated' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $result */
    private function withStatus(array $result, string $status): array
    {
        $result['status'] = $status;
        $result['configured'] = (bool) $result['config']['configured'];
        $result['contract_valid'] = (bool) $result['contract']['compatible'];
        $result['latency_ms'] = $result['latency'];
        $result['details'] = [
            'provider' => (string) $result['config']['provider'],
            'mode' => $result['config']['mode'],
            'enabled' => (bool) $result['config']['enabled'],
            'token_configured' => (bool) $result['config']['token_configured'],
            'expected_contract' => (string) $result['contract']['expected'],
            'reported_contract' => $result['contract']['reported'],
            'connector_version' => $result['contract']['connector'],
            'upstream_reachable' => (bool) $result['contract']['upstream_reachable'],
            'upstream_authenticated' => (bool) $result['contract']['upstream_authenticated'],
        ];

        return $result;
    }

    private function validatedBaseUrl(string $url, string $mode): ?string
    {
        if (! in_array($mode, ['port', 'subdomain'], true)) {
            return null;
        }

        $parts = parse_url($url);
        if ($url === '' || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = $parts['port'] ?? null;

        if ($mode === 'port') {
            if ($scheme !== 'http' || $host !== '127.0.0.1' || ! is_int($port) || $port < 1 || $port > 65535) {
                return null;
            }

            return "http://127.0.0.1:{$port}";
        }

        if ($scheme !== 'https'
            || $host === ''
            || $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ! str_contains($host, '.')
            || ($port !== null && $port !== 443)) {
            return null;
        }

        return 'https://'.$host.($port === 443 ? ':443' : '');
    }

    private function limitedResponseBody(StreamInterface $stream): ?string
    {
        $body = '';
        while (! $stream->eof()) {
            $remaining = (self::MAXIMUM_RESPONSE_BYTES + 1) - strlen($body);
            if ($remaining <= 0) {
                return null;
            }

            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '') {
                return $stream->eof() ? $body : null;
            }
            $body .= $chunk;

            if (strlen($body) > self::MAXIMUM_RESPONSE_BYTES) {
                return null;
            }
        }

        return $body;
    }

    /**
     * The adapter may expose additional abilities, but it must truthfully
     * report every code-owned capability that RailTime relies on. Database
     * values can therefore never manufacture a capability, and a health probe
     * cannot become green while a required platform or action is missing.
     *
     * @param  array<string, mixed>  $reported
     * @param  array<string, mixed>  $expected
     */
    private function capabilitiesAreCompatible(array $reported, array $expected): bool
    {
        foreach (self::BOOLEAN_CAPABILITIES as $key) {
            if (! is_bool($reported[$key] ?? null)) {
                return false;
            }
            if (($expected[$key] ?? false) === true && $reported[$key] !== true) {
                return false;
            }
        }

        foreach (self::LIST_CAPABILITIES as $key) {
            $reportedValues = $this->validatedCapabilityList($reported[$key] ?? null);
            $expectedValues = $this->validatedCapabilityList($expected[$key] ?? null);
            if ($reportedValues === null || $expectedValues === null) {
                return false;
            }
            if (array_diff($expectedValues, $reportedValues) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string>|null */
    private function validatedCapabilityList(mixed $values): ?array
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > 64) {
            return null;
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                return null;
            }

            $value = strtolower(trim($value));
            if ($value === '' || mb_strlen($value) > 80 || ! preg_match('/^[a-z0-9_-]+$/', $value)) {
                return null;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function validatedVersion(mixed $version): ?string
    {
        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);
        if (strlen($version) > 32
            || ! preg_match('/^[0-9]+(?:\.[0-9]+){0,2}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }

        return $version;
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
