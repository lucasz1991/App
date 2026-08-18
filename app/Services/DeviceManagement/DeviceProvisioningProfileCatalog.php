<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Models\DeviceProvisioningProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Versionierter Soll-Katalog fuer nicht geheime Konto-/SSO-Konfigurationen.
 *
 * Der Katalog speichert bewusst weder Passwoerter noch OAuth-/Refresh-Tokens.
 * Platzhalter werden erst beim providerseitigen Deployment aus den explizit
 * zugewiesenen Identitaetsreferenzen aufgeloest.
 */
class DeviceProvisioningProfileCatalog
{
    public const VERSION = 1;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'microsoft-outlook-modern-auth' => [
                'provider' => AccountProvider::Microsoft365->value,
                'type' => 'managed_account',
                'name' => 'Microsoft 365 / Outlook (Modern Auth)',
                'platforms' => ['windows', 'macos', 'android', 'ios', 'ipados'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v1',
                    'applications' => ['microsoft_365', 'outlook', 'teams', 'onedrive'],
                    'account' => [
                        'principal' => '{{ identity.principal }}',
                        'email' => '{{ identity.email }}',
                        'authentication' => 'oauth2',
                        'modern_auth' => true,
                    ],
                    'user_action' => 'oauth_mfa_once',
                ],
            ],
            'microsoft-apple-sso' => [
                'provider' => AccountProvider::Microsoft365->value,
                'type' => 'sso_extension',
                'name' => 'Microsoft Enterprise SSO fuer Apple',
                'platforms' => ['macos', 'ios', 'ipados'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v1',
                    'extension' => 'microsoft_enterprise_sso',
                    'required_apps' => [
                        'ios' => 'microsoft_authenticator',
                        'macos' => 'company_portal',
                    ],
                    'principal' => '{{ identity.principal }}',
                    'user_action' => 'register_entra_identity_once',
                ],
            ],
            'google-workspace-sso' => [
                'provider' => AccountProvider::GoogleWorkspace->value,
                'type' => 'managed_account',
                'name' => 'Google Workspace / Cloud Identity SSO',
                'platforms' => ['windows', 'macos', 'android', 'ios', 'ipados', 'chromeos'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v1',
                    'applications' => ['chrome', 'google_drive', 'gmail'],
                    'account' => [
                        'principal' => '{{ identity.principal }}',
                        'email' => '{{ identity.email }}',
                        'authentication' => 'federated_oauth2',
                    ],
                    'source_identity' => 'microsoft_entra',
                    'user_action' => 'oauth_mfa_once',
                ],
            ],
            'apple-managed-account' => [
                'provider' => AccountProvider::AppleManaged->value,
                'type' => 'federated_identity',
                'name' => 'Apple Business Managed Account',
                'platforms' => ['macos', 'ios', 'ipados'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v1',
                    'federation' => 'microsoft_entra',
                    'principal' => '{{ identity.principal }}',
                    'device_based_app_assignment_preferred' => true,
                    'user_action' => 'federated_sign_in_if_required',
                ],
            ],
        ];
    }

    /**
     * @return Collection<string, DeviceProvisioningProfile>
     */
    public function ensurePersisted(User $actor): Collection
    {
        return collect($this->definitions())->mapWithKeys(function (array $definition, string $key) use ($actor): array {
            $profile = DeviceProvisioningProfile::query()->updateOrCreate(
                [
                    'provider' => $definition['provider'],
                    'type' => $definition['type'],
                    'name' => $definition['name'],
                    'version' => self::VERSION,
                ],
                [
                    'platforms' => $definition['platforms'],
                    'configuration' => $definition['configuration'],
                    'is_active' => true,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            return [$key => $profile];
        });
    }
}
