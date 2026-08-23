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
    public const VERSION = 2;

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
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
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
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
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
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
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
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
                    'federation' => 'microsoft_entra',
                    'principal' => '{{ identity.principal }}',
                    'device_based_app_assignment_preferred' => true,
                    'user_action' => 'federated_sign_in_if_required',
                ],
            ],
            'microsoft-entra-device-registration' => [
                'provider' => AccountProvider::Microsoft365->value,
                'type' => 'device_identity',
                'name' => 'Microsoft Entra Geraeteregistrierung',
                'platforms' => ['windows', 'macos', 'android', 'ios', 'ipados'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
                    'identity_authority' => 'microsoft_entra',
                    'windows_join_mode' => 'entra_join_or_registered_by_enrollment_mode',
                    'required_apps' => ['company_portal', 'microsoft_authenticator'],
                    'user_action' => 'official_oauth_mfa_once',
                ],
            ],
            'microsoft-managed-network' => [
                'provider' => AccountProvider::Microsoft365->value,
                'type' => 'network_and_certificate',
                'name' => 'RailTime WLAN VPN und SCEP Sollprofil',
                'platforms' => ['windows', 'macos', 'android', 'ios', 'ipados'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
                    // Immutable references only. Concrete Wi-Fi secrets,
                    // provider credentials and private keys stay outside
                    // RailTime and are resolved by the connector.
                    'wifi_profile_reference' => 'railtime-corporate-wifi',
                    'vpn_profile_reference' => 'railtime-corporate-vpn',
                    'scep_profile_reference' => 'railtime-device-certificate',
                ],
            ],
            'google-managed-app-baseline' => [
                'provider' => AccountProvider::GoogleWorkspace->value,
                'type' => 'required_apps',
                'name' => 'Google Workspace Pflichtapps',
                'platforms' => ['windows', 'macos', 'android', 'ios', 'ipados', 'chromeos'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
                    'applications' => ['chrome', 'google_drive', 'gmail'],
                    'android_distribution' => 'managed_google_play_via_qualified_emm',
                ],
            ],
            'apple-business-baseline' => [
                'provider' => AccountProvider::AppleManaged->value,
                'type' => 'apple_business_baseline',
                'name' => 'Apple Business ADE und Apps Sollprofil',
                'platforms' => ['macos', 'ios', 'ipados'],
                'configuration' => [
                    'schema' => 'railtime.device-profile.v2',
                    'required' => true,
                    'enrollment_authority' => 'apple_business_manager',
                    'app_assignment' => 'apps_and_books_device_assignment',
                    'certificate_profile_reference' => 'railtime-device-certificate',
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
            $profile = DeviceProvisioningProfile::query()->firstOrNew([
                'provider' => $definition['provider'],
                'type' => $definition['type'],
                'name' => $definition['name'],
                'version' => self::VERSION,
            ]);
            $profile->fill([
                'platforms' => $definition['platforms'],
                'configuration' => $definition['configuration'],
                'is_active' => true,
                'updated_by' => $actor->id,
            ]);
            if (! $profile->exists) {
                $profile->created_by = $actor->id;
            }
            $profile->save();

            DeviceProvisioningProfile::query()
                ->where('provider', $definition['provider'])
                ->where('type', $definition['type'])
                ->where('name', $definition['name'])
                ->where('version', '<', self::VERSION)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                ]);

            return [$key => $profile];
        });
    }
}
