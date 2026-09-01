<?php

$baseUrl = rtrim((string) env('OUTLOOK_ADDIN_BASE_URL', env('APP_URL', '')), '/');
$tenantId = trim((string) env('OUTLOOK_ADDIN_TENANT_ID', ''));
$clientId = trim((string) env('OUTLOOK_ADDIN_CLIENT_ID', ''));
$scope = trim((string) env('OUTLOOK_ADDIN_SCOPE', 'Signature.Read'));

return [
    /*
    |--------------------------------------------------------------------------
    | RailTime Outlook add-in
    |--------------------------------------------------------------------------
    |
    | Client- und Tenant-ID sind keine Geheimnisse. Das Add-in verwendet
    | Microsoft Entra Nested App Authentication (NAA); es gibt absichtlich
    | weder ein Client-Secret noch einen dauerhaft gespeicherten RailTime-
    | Bearer-Token im Outlook-Client.
    |
    */
    'enabled' => (bool) env('OUTLOOK_ADDIN_ENABLED', false),
    'deployed' => (bool) env('OUTLOOK_ADDIN_DEPLOYED', false),
    'addin_id' => (string) env(
        'OUTLOOK_ADDIN_ID',
        'b9e33e51-38f0-4311-a2cc-6a73d0518935',
    ),
    'base_url' => $baseUrl,
    'marker' => 'RT-SIGNATURE-MANAGED-V1',

    'entra' => [
        'tenant_id' => $tenantId,
        'client_id' => $clientId,
        'authority' => $tenantId !== ''
            ? "https://login.microsoftonline.com/{$tenantId}"
            : '',
        'audience' => trim((string) env('OUTLOOK_ADDIN_AUDIENCE', $clientId)),
        'scope' => $scope,
        'scope_uri' => trim((string) env(
            'OUTLOOK_ADDIN_SCOPE_URI',
            $clientId !== '' && $scope !== '' ? "api://{$clientId}/{$scope}" : '',
        )),
    ],

    'token' => [
        'clock_skew_seconds' => max(0, (int) env('OUTLOOK_ADDIN_CLOCK_SKEW', 60)),
        'jwks_cache_seconds' => max(300, (int) env('OUTLOOK_ADDIN_JWKS_CACHE', 21600)),
        'maximum_length' => max(4096, (int) env('OUTLOOK_ADDIN_TOKEN_MAX_LENGTH', 16384)),
    ],
];
