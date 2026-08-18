<?php

use App\Enums\DeviceCommandType;
use App\Services\DeviceManagement\DeviceReadinessService;

$command = static fn (DeviceCommandType $type): string => $type->value;

return [
    /*
    |--------------------------------------------------------------------------
    | Immutable defaults and safety ceilings
    |--------------------------------------------------------------------------
    |
    | Mutable deployment, identity and provider values live encrypted where
    | necessary in settings(type=device_management,key=runtime). This file is
    | deliberately free of DEVICE_* environment variables and remains safe for
    | config:cache. Database failures always fall back to these closed defaults.
    |
    */
    'defaults' => [
        'deployment' => [
            'mode' => 'subdomain',
            // Null means: derive from the trusted, server-side app.url host.
            'base_domain' => null,
        ],
        'runtime' => [
            'production_commands_enabled' => false,
            'enrollment_ttl_minutes' => 1440,
            'max_sync_age_hours' => 24,
            'artifact_max_kilobytes' => 102400,
        ],
        'identity_domains' => [
            'microsoft_365' => '',
            'google_workspace' => '',
            'apple_managed' => '',
        ],
    ],

    // Infrastructure choices are code-owned. They are not editable provider
    // credentials and cannot silently strand jobs or expose a public disk.
    'queue' => 'devices',
    'artifact_disk' => 'private',

    'limits' => [
        'enrollment_ttl_minutes' => [15, 10080],
        'max_sync_age_hours' => [1, 720],
        'artifact_max_kilobytes' => [1024, 1048576],
        'provider_timeout_seconds' => [1, 30],
        'adapter_port' => [1024, 65535],
    ],

    'security' => [
        'maximum_response_bytes' => 65536,
        'maximum_request_bytes' => 32768,
        'maximum_webhook_bytes' => 65536,
        'webhook_tolerance_seconds' => 300,
        // Immutable production SSRF ceiling. local/testing may deliberately
        // use reserved names; production connector subdomains may not.
        'blocked_public_domain_suffixes' => [
            'localhost',
            'localdomain',
            'local',
            'internal',
            'intranet',
            'lan',
            'home',
            'home.arpa',
            'test',
            'example',
            'example.com',
            'example.net',
            'example.org',
            'invalid',
            'onion',
            'arpa',
        ],
    ],

    /*
    | Connector contract
    | ------------------
    | These are RailTime adapter endpoints, not undocumented native upstream
    | APIs. In subdomain mode RailTime derives HTTPS from prefix + base domain.
    | In port mode it talks only to the exact local adapter address
    | http://127.0.0.1:<adapter_port> on the same Plesk host.
    |
    | Capabilities and paths are an immutable allowlist. Database input may
    | narrow availability but can never add a platform, command or endpoint.
    */
    'providers' => [
        'openuem' => [
            'label' => 'OpenUEM Desktop Management',
            'enabled' => false,
            'subdomain' => 'openuem',
            'adapter_port' => 9441,
            'token' => '',
            'webhook_secret' => '',
            'timeout' => 10,
            'paths' => [
                'health' => '/v1/health',
                'enrollment' => '/v1/enrollments',
                'command' => '/v1/commands',
            ],
            'remote_url_template' => '',
            'capabilities' => [
                'platforms' => ['windows', 'macos', 'linux'],
                'inventory' => true,
                'enrollment' => true,
                'remote_support' => false,
                'unattended_remote_support' => false,
                'commands' => [
                    $command(DeviceCommandType::Sync),
                    $command(DeviceCommandType::Restart),
                    $command(DeviceCommandType::ExecuteScript),
                    $command(DeviceCommandType::InstallSoftware),
                    $command(DeviceCommandType::UninstallSoftware),
                    $command(DeviceCommandType::CollectDiagnostics),
                    $command(DeviceCommandType::ApplyProfile),
                ],
                'readiness_checks' => ['provider_sync', 'profiles', 'network', 'certificate', 'compliance'],
            ],
        ],

        'meshcentral' => [
            'label' => 'MeshCentral Remote Support',
            'enabled' => false,
            'subdomain' => 'support',
            'adapter_port' => 9442,
            'token' => '',
            'webhook_secret' => '',
            'timeout' => 10,
            'paths' => [
                'health' => '/v1/health',
                'enrollment' => '/v1/enrollments',
                'command' => '/v1/commands',
            ],
            'remote_url_template' => '',
            'capabilities' => [
                'platforms' => ['windows', 'macos', 'linux'],
                'inventory' => false,
                'enrollment' => true,
                'remote_support' => true,
                'unattended_remote_support' => true,
                'commands' => [
                    $command(DeviceCommandType::Restart),
                    $command(DeviceCommandType::ExecuteScript),
                    $command(DeviceCommandType::CollectDiagnostics),
                    $command(DeviceCommandType::StartRemoteSupport),
                ],
                'readiness_checks' => ['remote_support'],
            ],
        ],

        'headwind' => [
            'label' => 'Headwind MDM Community (Android)',
            'enabled' => false,
            'subdomain' => 'android-mdm',
            'adapter_port' => 9443,
            'token' => '',
            'webhook_secret' => '',
            'timeout' => 10,
            'paths' => [
                'health' => '/v1/health',
                'enrollment' => '/v1/enrollments',
                'command' => '/v1/commands',
            ],
            'remote_url_template' => '',
            'capabilities' => [
                'platforms' => ['android'],
                'inventory' => true,
                'enrollment' => true,
                // Community edition must not advertise enterprise lock, wipe
                // or unattended remote control as available RailTime actions.
                'remote_support' => false,
                'unattended_remote_support' => false,
                'commands' => [
                    $command(DeviceCommandType::Sync),
                    $command(DeviceCommandType::InstallSoftware),
                    $command(DeviceCommandType::ApplyProfile),
                ],
                'readiness_checks' => ['provider_sync', 'profiles', 'network', 'certificate', 'compliance'],
            ],
        ],

        'nanomdm' => [
            'label' => 'NanoMDM Apple Orchestration',
            'enabled' => false,
            'subdomain' => 'apple-mdm',
            'adapter_port' => 9444,
            'token' => '',
            'webhook_secret' => '',
            'timeout' => 10,
            'paths' => [
                'health' => '/v1/health',
                'enrollment' => '/v1/enrollments',
                'command' => '/v1/commands',
            ],
            'remote_url_template' => '',
            'mobile_commands_enabled' => false,
            'command_ceiling' => [
                $command(DeviceCommandType::Sync),
                $command(DeviceCommandType::Lock),
                $command(DeviceCommandType::Wipe),
                $command(DeviceCommandType::Restart),
                $command(DeviceCommandType::InstallSoftware),
                $command(DeviceCommandType::ApplyProfile),
            ],
            'capabilities' => [
                'platforms' => ['macos', 'ios', 'ipados'],
                'inventory' => true,
                'enrollment' => true,
                'remote_support' => false,
                'unattended_remote_support' => false,
                // NanoMDM is a protocol core, not a finished UEM. Commands
                // remain a code-reviewed allowlist and therefore closed here.
                'commands' => [],
                'readiness_checks' => ['provider_sync', 'profiles', 'network', 'certificate', 'compliance'],
            ],
        ],

        'identity' => [
            'label' => 'Entra / Google / Apple Identity Connector',
            'enabled' => false,
            'subdomain' => 'identity',
            'adapter_port' => 9445,
            'token' => '',
            'webhook_secret' => '',
            'timeout' => 10,
            'paths' => [
                'health' => '/v1/health',
                'enrollment' => '/v1/enrollments-disabled',
                'command' => '/v1/commands-disabled',
            ],
            'remote_url_template' => '',
            'capabilities' => [
                'platforms' => ['windows', 'macos', 'linux', 'android', 'ios', 'ipados', 'chromeos'],
                'inventory' => false,
                'enrollment' => false,
                'remote_support' => false,
                'unattended_remote_support' => false,
                'commands' => [],
                'readiness_checks' => ['identity', 'user_sign_in'],
            ],
        ],

        'simulation' => [
            'label' => 'RailTime deterministic simulation',
            'enabled' => false,
            'subdomain' => 'simulation-disabled',
            'adapter_port' => 9446,
            'token' => '',
            'webhook_secret' => '',
            'timeout' => 1,
            'paths' => [
                'health' => '/v1/health-disabled',
                'enrollment' => '/v1/enrollments-disabled',
                'command' => '/v1/commands-disabled',
            ],
            'remote_url_template' => '',
            'capabilities' => [
                'platforms' => ['windows', 'macos', 'linux', 'android', 'ios', 'ipados'],
                'inventory' => true,
                'enrollment' => true,
                'remote_support' => true,
                'unattended_remote_support' => false,
                'commands' => array_map(
                    static fn (DeviceCommandType $type): string => $type->value,
                    DeviceCommandType::cases(),
                ),
                'readiness_checks' => array_keys(DeviceReadinessService::REQUIRED_CHECKS),
            ],
        ],
    ],
];
