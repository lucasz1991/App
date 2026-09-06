<?php

namespace App\Services\DeviceManagement;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Read-only Microsoft Graph v1.0 transport. Batch POSTs contain GETs only. */
class MicrosoftGraphDeviceClient
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    private string $token = '';

    private float $startedAt = 0;

    private int $requests = 0;

    private bool $shortProbe = false;

    public function begin(array $configuration, bool $shortProbe = false): void
    {
        $this->shortProbe = $shortProbe;
        $this->token = '';
        $this->startedAt = microtime(true);
        $this->requests = 0;
        if (! Str::isUuid($configuration['tenant_id'] ?? '')
            || ! Str::isUuid($configuration['client_id'] ?? '')
            || trim((string) ($configuration['client_secret'] ?? '')) === '') {
            throw new MicrosoftGraphDeviceException('missing_configuration');
        }

        try {
            $response = Http::asForm()->acceptJson()->connectTimeout($shortProbe ? 2 : 5)->timeout($shortProbe ? 5 : 20)
                ->withoutRedirecting()
                ->withOptions($this->diagnosticOptions())
                ->post('https://login.microsoftonline.com/'.$configuration['tenant_id'].'/oauth2/v2.0/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $configuration['client_id'],
                    'client_secret' => $configuration['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);
        } catch (Throwable) {
            throw new MicrosoftGraphDeviceException('unreachable');
        }
        $body = $this->decode($response);
        if (! is_string($body['access_token'] ?? null) || $body['access_token'] === ''
            || strlen($body['access_token']) > 32768
            || strtolower((string) ($body['token_type'] ?? '')) !== 'bearer') {
            throw new MicrosoftGraphDeviceException('invalid_response');
        }
        $this->token = $body['access_token'];
    }

    public function devices(bool $probe = false): array
    {
        $query = http_build_query([
            '$select' => 'id,deviceId,displayName,operatingSystem,operatingSystemVersion,trustType,accountEnabled,isManaged,isCompliant,approximateLastSignInDateTime,manufacturer,model',
            '$top' => $probe ? 1 : 100,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->collection('/devices?'.$query, $probe);
    }

    public function managedDevices(bool $probe = false): array
    {
        $query = http_build_query([
            '$select' => 'id,azureADDeviceId,deviceName,operatingSystem,osVersion,serialNumber,manufacturer,model,managedDeviceOwnerType,complianceState,lastSyncDateTime',
            '$top' => $probe ? 1 : 100,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->collection('/deviceManagement/managedDevices?'.$query, $probe);
    }

    /** @return array<string, list<string>> Complete, paged owner/primary-user IDs keyed by object ID. */
    public function userRelationships(array $objectIds, bool $intune = false): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($objectIds)), 20) as $chunk) {
            $requests = [];
            $paths = [];
            foreach ($chunk as $index => $objectId) {
                if (! is_string($objectId) || ! Str::isUuid($objectId)) {
                    throw new MicrosoftGraphDeviceException('invalid_response');
                }
                $paths[(string) $index] = $intune
                    ? '/deviceManagement/managedDevices/'.$objectId.'/users'
                    : '/devices/'.$objectId.'/registeredOwners';
                $requests[] = ['id' => (string) $index, 'method' => 'GET', 'url' => $paths[(string) $index]];
            }
            $batch = $this->request('/$batch', ['requests' => $requests]);
            if (! is_array($batch['responses'] ?? null) || count($batch['responses']) !== count($requests)) {
                throw new MicrosoftGraphDeviceException('invalid_response');
            }
            $seen = [];
            foreach ($batch['responses'] as $entry) {
                $key = (string) ($entry['id'] ?? '');
                if (! array_key_exists($key, $paths) || isset($seen[$key])
                    || ! is_int($entry['status'] ?? null)) {
                    throw new MicrosoftGraphDeviceException('invalid_response');
                }
                $seen[$key] = true;
                $this->assertStatus($entry['status']);
                $rows = $this->collectPages($paths[$key], $entry['body'] ?? null);
                $ids = [];
                foreach ($rows as $row) {
                    // Non-user directory objects are not employee identities.
                    if (isset($row['@odata.type']) && $row['@odata.type'] !== '#microsoft.graph.user') {
                        throw new MicrosoftGraphDeviceException('invalid_response');
                    }
                    if (! Str::isUuid($row['id'] ?? '')) {
                        throw new MicrosoftGraphDeviceException('invalid_response');
                    }
                    $ids[] = strtolower($row['id']);
                }
                $result[$chunk[(int) $key]] = array_values(array_unique($ids));
            }
        }

        return $result;
    }

    private function collection(string $path, bool $probe): array
    {
        $body = $this->request($path);

        return $this->collectPages($path, $body, $probe);
    }

    private function collectPages(string $path, mixed $body, bool $probe = false): array
    {
        $rows = [];
        $seen = [];
        for ($page = 0; $page < 100; $page++) {
            if (! is_array($body) || ! is_array($body['value'] ?? null) || ! array_is_list($body['value'])) {
                throw new MicrosoftGraphDeviceException('invalid_response');
            }
            foreach ($body['value'] as $row) {
                if (! is_array($row)) {
                    throw new MicrosoftGraphDeviceException('invalid_response');
                }
                $rows[] = $row;
            }
            if (count($rows) > 10000) {
                throw new MicrosoftGraphDeviceException('response_limit');
            }
            $next = $body['@odata.nextLink'] ?? null;
            if ($probe || $next === null) {
                return $rows;
            }
            if (! is_string($next) || isset($seen[$next]) || strlen($next) > 8192) {
                throw new MicrosoftGraphDeviceException('invalid_response');
            }
            $parts = parse_url($next);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || ($parts['host'] ?? '') !== 'graph.microsoft.com'
                || isset($parts['port'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || ($parts['path'] ?? '') !== '/v1.0'.parse_url($path, PHP_URL_PATH)) {
                throw new MicrosoftGraphDeviceException('invalid_pagination');
            }
            $seen[$next] = true;
            $body = $this->request(substr($next, strlen(self::BASE)));
        }

        throw new MicrosoftGraphDeviceException('response_limit');
    }

    private function request(string $path, ?array $batch = null): array
    {
        if ($this->token === '' || ++$this->requests > ($this->shortProbe ? 2 : 500) || microtime(true) - $this->startedAt > ($this->shortProbe ? 15 : 180)) {
            throw new MicrosoftGraphDeviceException('request_limit');
        }
        try {
            $request = Http::withToken($this->token)->acceptJson()->connectTimeout($this->shortProbe ? 2 : 5)->timeout($this->shortProbe ? 5 : 20)->withoutRedirecting();
            $request->withOptions($this->diagnosticOptions());
            $response = $batch === null
                ? $request->get(self::BASE.$path)
                : $request->post(self::BASE.'/$batch', $batch);
        } catch (Throwable) {
            throw new MicrosoftGraphDeviceException('unreachable');
        }

        return $this->decode($response);
    }

    private function decode(Response $response): array
    {
        $this->assertStatus($response->status());
        if (strlen($response->body()) > ($this->shortProbe ? 65536 : 5 * 1024 * 1024)) {
            throw new MicrosoftGraphDeviceException('response_limit');
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new MicrosoftGraphDeviceException('invalid_response');
        }

        return $body;
    }

    private function assertStatus(int $status): void
    {
        if ($status === 200) {
            return;
        }
        throw new MicrosoftGraphDeviceException(match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            429 => 'rate_limited',
            default => 'http_error',
        });
    }

    private function diagnosticOptions(): array
    {
        if (! $this->shortProbe) {
            return [];
        }

        return ['verify' => true, 'proxy' => '', 'progress' => static function ($total, $received): void {
            if ($total > 65536 || $received > 65536) {
                throw new MicrosoftGraphDeviceException('response_limit');
            }
        }];
    }
}
