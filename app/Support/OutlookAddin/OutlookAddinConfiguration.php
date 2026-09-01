<?php

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class OutlookAddinConfiguration
{
    /** @return list<string> */
    public function missing(): array
    {
        $missing = [];

        if (! (bool) config('outlook_addin.enabled', false)) {
            $missing[] = 'enabled';
        }

        $baseUrl = $this->baseUrl(throwWhenInvalid: false);
        if ($baseUrl === '' || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            $missing[] = 'https_base_url';
        }

        foreach ([
            'tenant_id' => config('outlook_addin.entra.tenant_id'),
            'client_id' => config('outlook_addin.entra.client_id'),
            'audience' => config('outlook_addin.entra.audience'),
            'scope_uri' => config('outlook_addin.entra.scope_uri'),
        ] as $key => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        foreach (['outlook_addin.addin_id', 'outlook_addin.entra.tenant_id', 'outlook_addin.entra.client_id'] as $key) {
            $value = trim((string) config($key, ''));
            if ($value !== '' && ! Str::isUuid($value)) {
                $missing[] = str_replace('outlook_addin.', '', $key).'_invalid';
            }
        }

        return array_values(array_unique($missing));
    }

    public function ready(): bool
    {
        return $this->missing() === [];
    }

    public function deployed(): bool
    {
        return $this->ready() && (bool) config('outlook_addin.deployed', false);
    }

    public function availableTo(?User $user): bool
    {
        if (! $this->deployed()
            || ! $user instanceof User
            || ! Schema::hasTable('employee_identity_accounts')) {
            return false;
        }

        return EmployeeIdentityAccount::query()
            ->forProvider(AccountProvider::Microsoft365)
            ->active()
            ->where('user_id', $user->getKey())
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->exists();
    }

    public function assertReady(): void
    {
        if ($this->ready()) {
            return;
        }

        throw new OutlookAddinException(
            'Das Outlook-Add-in ist noch nicht vollständig durch die Administration konfiguriert.',
            503,
            'outlook_addin_not_configured',
        );
    }

    public function baseUrl(bool $throwWhenInvalid = true): string
    {
        $url = rtrim(trim((string) config('outlook_addin.base_url', '')), '/');
        $parts = parse_url($url);

        if ($url !== ''
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])) {
            return $url;
        }

        if ($throwWhenInvalid) {
            throw new OutlookAddinException(
                'Die öffentliche Outlook-Add-in-Adresse ist ungültig.',
                503,
                'outlook_addin_invalid_base_url',
            );
        }

        return '';
    }

    /** @return array<string, mixed> */
    public function publicConfiguration(): array
    {
        $baseUrl = $this->baseUrl(throwWhenInvalid: false);

        return [
            'ready' => $this->deployed(),
            'configured' => $this->ready(),
            'auth' => [
                'clientId' => (string) config('outlook_addin.entra.client_id', ''),
                'authority' => (string) config('outlook_addin.entra.authority', ''),
                'scopes' => array_values(array_filter([
                    (string) config('outlook_addin.entra.scope_uri', ''),
                ])),
            ],
            'endpoints' => [
                'bootstrap' => $baseUrl !== '' ? $baseUrl.'/api/outlook-addin/bootstrap' : '',
            ],
            'marker' => (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
        ];
    }
}
