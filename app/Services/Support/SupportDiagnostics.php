<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Validator;

final class SupportDiagnostics
{
    public static function validate(array $input): array
    {
        // Enumerated results only; never accept arbitrary logs, paths, tokens or machine inventories.
        return Validator::make(['diagnostics' => $input], [
            'diagnostics' => ['array:client_version,platform,connection,pairing,software_runner,service_state,permission_state,error_code,reference'],
            'diagnostics.client_version' => ['sometimes', 'string', 'regex:/\A[0-9A-Za-z][0-9A-Za-z._+\-]{0,39}\z/'],
            'diagnostics.platform' => ['sometimes', 'in:windows,macos,linux,android,ios,unknown'],
            'diagnostics.connection' => ['sometimes', 'in:reachable,unreachable,unknown'],
            'diagnostics.pairing' => ['sometimes', 'in:paired,unpaired,revoked,unknown'],
            'diagnostics.software_runner' => ['sometimes', 'in:available,unavailable,unknown'],
            'diagnostics.service_state' => ['sometimes', 'in:running,stopped,not_installed,unknown'],
            'diagnostics.permission_state' => ['sometimes', 'in:allowed,denied,unknown'],
            'diagnostics.error_code' => ['sometimes', 'in:connection_failed,pairing_required,setup_blocked,installation_failed,permission_required,restart_required,unknown'],
            'diagnostics.reference' => ['sometimes', 'uuid'],
        ])->validate()['diagnostics'];
    }
}
