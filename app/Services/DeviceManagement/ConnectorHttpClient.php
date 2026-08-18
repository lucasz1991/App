<?php

namespace App\Services\DeviceManagement;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class ConnectorHttpClient
{
    public function __construct(
        private readonly DeviceManagementSettings $settings,
        private readonly ConnectorNetworkGuard $networkGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $providerConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(
        string $provider,
        array $providerConfig,
        string $method,
        string $path,
        array $payload = [],
    ): array {
        $baseUrl = $this->validatedBaseUrl(
            (string) ($providerConfig['connector_base_url'] ?? ''),
            $providerConfig,
        );
        $path = $this->validatedPath($path);
        $token = trim((string) ($providerConfig['token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException("Der Connector '{$provider}' hat kein Zugriffstoken.");
        }

        $networkOptions = $this->networkGuard->requestOptions(
            $baseUrl,
            (string) ($providerConfig['mode'] ?? 'subdomain'),
        );

        $requestBytes = strlen(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $maximumRequestBytes = $this->settings->maximumRequestBytes();
        if ($requestBytes > $maximumRequestBytes) {
            throw new RuntimeException("Die Anfrage an den Connector '{$provider}' ist zu groß.");
        }

        $timeout = min(30, max(1, (int) ($providerConfig['timeout'] ?? 10)));
        $url = rtrim($baseUrl, '/').$path;

        try {
            $pending = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->withOptions(array_replace_recursive([
                    'allow_redirects' => false,
                    'stream' => true,
                ], $networkOptions));

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $payload),
                'POST' => $pending->post($url, $payload),
                default => throw new RuntimeException('Nicht erlaubte Connector-Methode.'),
            };
        } catch (ConnectionException|RequestException) {
            throw new RuntimeException("Der Connector '{$provider}' ist nicht erreichbar.");
        }

        if ($response->redirect() || ! $response->successful()) {
            throw new RuntimeException("Der Connector '{$provider}' antwortete mit HTTP {$response->status()}.");
        }

        $maximumResponseBytes = $this->settings->maximumResponseBytes();
        try {
            $body = $this->limitedResponseBody(
                $response->toPsrResponse()->getBody(),
                $maximumResponseBytes,
            );
        } catch (Throwable) {
            throw new RuntimeException("Die Antwort des Connectors '{$provider}' konnte nicht sicher gelesen werden.");
        }
        if ($body === null) {
            throw new RuntimeException("Die Antwort des Connectors '{$provider}' ist zu groß.");
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Der Connector '{$provider}' lieferte kein gültiges JSON.");
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("Der Connector '{$provider}' lieferte kein JSON-Objekt.");
        }

        return $decoded;
    }

    private function limitedResponseBody(StreamInterface $stream, int $maximumBytes): ?string
    {
        $body = '';
        while (! $stream->eof()) {
            $remaining = ($maximumBytes + 1) - strlen($body);
            if ($remaining <= 0) {
                return null;
            }

            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '') {
                return $stream->eof() ? $body : null;
            }
            $body .= $chunk;

            if (strlen($body) > $maximumBytes) {
                return null;
            }
        }

        return $body;
    }

    /** @param array<string, mixed> $providerConfig */
    private function validatedBaseUrl(string $url, array $providerConfig): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Die Connector-URL ist nicht konfiguriert oder ungültig.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Die Connector-URL darf keine Zugangsdaten, Query oder Fragmente enthalten.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $mode = (string) ($providerConfig['mode'] ?? 'subdomain');
        $adapterPort = (int) ($providerConfig['adapter_port'] ?? 0);
        $loopbackAllowed = $mode === 'port'
            && ($providerConfig['same_server'] ?? false) === true
            && $host === '127.0.0.1'
            && (int) ($parts['port'] ?? 0) === $adapterPort
            && rtrim($url, '/') === "http://127.0.0.1:{$adapterPort}";

        if ($mode === 'port') {
            if ($scheme !== 'http' || ! $loopbackAllowed) {
                throw new RuntimeException('Der gespeicherte Port-Modus darf ausschließlich den lokalen Adapter über 127.0.0.1 ansprechen.');
            }
        } elseif ($mode === 'subdomain' && $scheme === 'https') {
            $publicUrl = rtrim((string) ($providerConfig['public_url'] ?? ''), '/');
            if ($publicUrl === '' || ! hash_equals($publicUrl, rtrim($url, '/'))) {
                throw new RuntimeException('Die Connector-URL entspricht nicht der gespeicherten Plesk-Subdomain.');
            }
        } else {
            throw new RuntimeException('Connectoren benötigen eine gespeicherte HTTPS-Subdomain oder den exakten lokalen Port-Modus.');
        }

        return rtrim($url, '/');
    }

    private function validatedPath(string $path): string
    {
        $path = trim($path);
        if ($path === ''
            || ! str_starts_with($path, '/')
            || str_contains($path, '..')
            || str_contains($path, '://')
            || str_contains($path, '?')
            || str_contains($path, '#')) {
            throw new RuntimeException('Der konfigurierte Connector-Pfad ist ungültig.');
        }

        return $path;
    }
}
