<?php

namespace App\Services\DeviceManagement;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Separate bounded writer. No deletion, password reset, mail reads or arbitrary URLs. */
class MicrosoftProvisioningGraph
{
    private string $token = '';

    public function begin(array $configuration): void
    {
        $this->token = '';
        if (! $configuration['enabled'] || ! Str::isUuid($configuration['tenant_id']) || ! Str::isUuid($configuration['client_id']) || $configuration['secret'] === '') {
            throw new \RuntimeException('writer_disabled');
        }
        try {
            $response = Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->withOptions(['debug' => false, 'on_stats' => null])
                ->post('https://login.microsoftonline.com/'.$configuration['tenant_id'].'/oauth2/v2.0/token', ['grant_type' => 'client_credentials', 'client_id' => $configuration['client_id'], 'client_secret' => $configuration['secret'], 'scope' => 'https://graph.microsoft.com/.default']);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || strlen($token) < 20 || strlen($token) > 32768) {
                throw new \RuntimeException;
            }
            $this->token = $token;
        } catch (\Throwable) {
            throw new \RuntimeException('writer_authentication_failed');
        }
    }

    private function request(string $method, string $path, array $body = [], bool $allowMissing = false): ?array
    {
        if ($this->token === '') {
            throw new \RuntimeException('writer_disabled');
        }
        try {
            $response = Http::withToken($this->token)->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->withOptions(['debug' => false, 'on_stats' => null])->send($method, 'https://graph.microsoft.com/v1.0'.$path, $method === 'GET' ? [] : ['json' => $body]);
        } catch (\Throwable) {
            throw new \RuntimeException('graph_response_uncertain');
        }
        if ($allowMissing && $response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new \RuntimeException('graph_http_'.$response->status());
        }
        if (strlen($response->body()) > 1048576 || ! is_array($response->json())) {
            throw new \RuntimeException('graph_invalid_response');
        }

        return $response->json();
    }

    public function user(string $key): ?array
    {
        if (! Str::isUuid($key) && ! filter_var($key, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('invalid_user_key');
        }

        return $this->request('GET', '/users/'.rawurlencode($key).'?'.http_build_query(['$select' => 'id,userPrincipalName,userType,accountEnabled,usageLocation,assignedLicenses,assignedPlans,provisionedPlans,mail']), allowMissing: true);
    }

    public function domains(): array
    {
        return $this->request('GET', '/domains')['value'] ?? [];
    }

    public function skus(): array
    {
        return $this->request('GET', '/subscribedSkus')['value'] ?? [];
    }

    public function createUser(array $body): array
    {
        return $this->request('POST', '/users', $body);
    }

    public function assignLicense(string $objectId, string $skuId): void
    {
        if (! Str::isUuid($objectId) || ! Str::isUuid($skuId)) {
            throw new \RuntimeException('invalid_license_target');
        }
        $this->request('POST', '/users/'.$objectId.'/assignLicense', ['addLicenses' => [['skuId' => $skuId, 'disabledPlans' => []]], 'removeLicenses' => []]);
    }
}
